<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemCatalog;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Support\Translator;
use PHPUnit\Framework\TestCase;

/** Recorre los datos reales del juego (data/ y public/assets/img) para detectar inconsistencias. */
final class CatalogTest extends TestCase
{
    private string $root;
    private ItemCatalog $items;
    private SkillCatalog $skills;
    private Translator $translator;
    private ItemDescriber $describer;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->items = new ItemCatalog($this->root . '/data/items');
        $this->skills = new SkillCatalog($this->root . '/data/skills.php');
        $this->translator = new Translator($this->root . '/lang');
        $this->describer = new ItemDescriber($this->translator, $this->skills);
    }

    /** @return list<Item> */
    private function allItems(): array
    {
        return array_merge(...array_map($this->items->ofType(...), ItemCatalog::TYPES));
    }

    public function testEveryCatalogItemParsesAndRoundTrips(): void
    {
        $count = 0;
        foreach (ItemCatalog::TYPES as $type) {
            $xml = simplexml_load_file($this->root . '/data/items/' . $type . '.xml');
            self::assertNotFalse($xml);
            foreach ($xml->item as $node) {
                $code = trim((string) $node);
                $item = Item::parse($code);
                self::assertSame($code, $item->toCode());
                self::assertSame($type, $item->type, "$code está en el archivo de otro tipo");
                $count++;
            }
        }

        self::assertGreaterThan(500, $count);
    }

    public function testEveryCatalogItemHasItsImage(): void
    {
        $missing = [];
        foreach ($this->allItems() as $item) {
            if (!is_file($this->root . '/public/assets/img/items/' . $item->imagePath())) {
                $missing[] = $item->imagePath();
            }
        }

        self::assertSame([], $missing);
    }

    public function testUnknownTypeIsEmpty(): void
    {
        self::assertSame([], $this->items->ofType('Z'));
        self::assertSame([], $this->items->ofType('../skills'));
    }

    public function testEveryItemDescriptionIsFullyTranslated(): void
    {
        foreach (array_keys(Translator::LOCALES) as $locale) {
            foreach ($this->allItems() as $item) {
                $card = $this->describer->describe($item, $locale);
                foreach ([...$card['lines'], ...$card['classes']] as $line) {
                    self::assertStringStartsNotWith('item.', $line['text'], "$locale: {$item->name}");
                    self::assertStringNotContainsString('{', $line['text'], "$locale: {$item->name}");
                }
            }
        }
    }

    public function testDescriptionMarksWhatTheCharacterLacks(): void
    {
        $wizard = new Character(1, 1, 'Merlin', CharacterClass::DarkWizard, 1, 18, 18, 15, 30, 0, 0, 0, 60, 60);
        $item = Item::parse('1.13.1.0056.00.0.1120.2018.11.0.0.00.00.0.043.043.000000.double_blade');

        $card = $this->describer->describe($item, 'en', $wizard);

        $byText = array_column($card['lines'], 'color', 'text');
        self::assertSame('red', $byText['Strength Requirement: 120 (lacking 102)']);
        self::assertSame('', $byText['Agility Requirement: 18']);
        self::assertSame([false, false], array_column($card['classes'], 'ok'));

        $guest = $this->describer->describe($item, 'en');
        self::assertSame([true, true], array_column($guest['classes'], 'ok'));
        self::assertArrayHasKey('Strength Requirement: 120', array_column($guest['lines'], 'color', 'text'));
    }

    public function testDescriptionOfAnUpgradedExcellentWeapon(): void
    {
        $item = Item::parse('1.12.1.0011.02.1.1027.2027.00.3.1.01.00.0.022.022.000000.kris');

        $card = $this->describer->describe($item, 'en');

        self::assertSame('Excellent Kris +2', $card['name']);
        self::assertSame('green', $card['color']);
        self::assertSame(
            [
                'One-Handed Damage: 39~47',
                'Durability [22/22]',
                'Strength Requirement: 67',
                'Agility Requirement: 67',
                'Uppercut Skill',
                'Luck (success of Jewel of Soul + 25%)',
                'Luck (critical damage rate + 5%)',
                'Additional Dmg + 4',
                'Excellent damage rate + 10%',
            ],
            array_column($card['lines'], 'text'),
        );
    }

    public function testEverySkillHasImageNameAndTranslatedLines(): void
    {
        self::assertNotEmpty($this->skills->all());

        foreach ($this->skills->all() as $code => $skill) {
            $code = (string) $code;
            self::assertFileExists($this->root . '/public/assets/img/skills/skill' . $code . '.gif');
            self::assertContains($skill['group'], SkillCatalog::GROUPS);

            foreach (array_keys(Translator::LOCALES) as $locale) {
                $lines = $this->skills->describe($code, $this->translator, $locale);
                self::assertNotEmpty($lines);
                foreach ($lines as $line) {
                    self::assertStringStartsNotWith('skill.', $line['text'], "$locale: $code");
                    self::assertStringNotContainsString('{', $line['text'], "$locale: $code");
                }
            }
        }
    }

    public function testStartingSkillsExist(): void
    {
        foreach (CharacterClass::CREATABLE as $class) {
            foreach ($class->startingStats()['skills'] as $code) {
                self::assertTrue($this->skills->has($code), "{$class->label()} empieza con una habilidad inexistente");
            }
        }
    }
}
