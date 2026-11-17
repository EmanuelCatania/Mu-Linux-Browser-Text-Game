<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Item\Item;
use Mu\Domain\Stats\EquipmentSlot;
use Mu\Domain\Stats\StatsCalculator;
use PHPUnit\Framework\TestCase;

final class StatsCalculatorTest extends TestCase
{
    private StatsCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new StatsCalculator();
    }

    /** @param list<string> $skills */
    private static function character(CharacterClass $class, int $level = 1, array $skills = []): Character
    {
        $s = $class->startingStats();

        return new Character(1, 1, 'Test', $class, $level, $s['strength'], $s['agility'], $s['stamina'], $s['energy'], 0, 0, 0, $s['hp'], $s['mp'], $skills);
    }

    public function testNewDarkKnightMatchesTheOriginalGame(): void
    {
        // Valores que muestra Classic-2011 para un Dark Knight recién creado.
        $stats = $this->calculator->calculate(self::character(CharacterClass::DarkKnight));

        self::assertSame([4, 7], [$stats->damageMin, $stats->damageMax]);
        self::assertSame(21, $stats->damageRate);
        self::assertSame(6, $stats->defense);
        self::assertSame(6, $stats->defenseRate);
        self::assertSame(112, $stats->hpMax);
        self::assertSame(20, $stats->mpMax);
        self::assertSame([0, 0], [$stats->wizDamageMin, $stats->wizDamageMax]);
        self::assertSame(0, $stats->damageBonus);
    }

    public function testBaseStatsOfTheOtherClasses(): void
    {
        $wizard = $this->calculator->calculate(self::character(CharacterClass::DarkWizard, 1, ['30']));
        self::assertSame([1, 2, 3, 7, 4, 6, 61, 62], [
            $wizard->damageMin, $wizard->damageMax, $wizard->wizDamageMin, $wizard->wizDamageMax,
            $wizard->defense, $wizard->defenseRate, $wizard->hpMax, $wizard->mpMax,
        ]);
        self::assertSame(['30'], $wizard->skills);

        $elf = $this->calculator->calculate(self::character(CharacterClass::FairyElf));
        self::assertSame([5, 9, 2, 6, 81, 30], [$elf->damageMin, $elf->damageMax, $elf->defense, $elf->defenseRate, $elf->hpMax, $elf->mpMax]);

        $summoner = $this->calculator->calculate(self::character(CharacterClass::Summoner));
        self::assertSame([1, 2, 2, 5, 7, 5, 70, 40], [
            $summoner->damageMin, $summoner->damageMax, $summoner->wizDamageMin, $summoner->wizDamageMax,
            $summoner->defense, $summoner->defenseRate, $summoner->hpMax, $summoner->mpMax,
        ]);
    }

    public function testEvolvedClassesUseTheirBaseFormulas(): void
    {
        $knight = self::character(CharacterClass::DarkKnight, 150);
        $blade = new Character(...array_merge(get_object_vars($knight), ['class' => CharacterClass::BladeKnight]));

        self::assertEquals($this->calculator->calculate($knight), $this->calculator->calculate($blade));
    }

    public function testWeaponArmorAndShieldAddUp(): void
    {
        $equipment = [
            // kris +2 con opción +1 (4), luck, habilidad Uppercut: poder 11 + 6 = 17
            EquipmentSlot::Hand1->value => Item::parse('1.12.1.0011.02.1.1027.2027.00.3.1.00.00.0.022.022.000000.kris'),
            // armadura: poder 7 + 3 = 10, opción 2 (8)
            EquipmentSlot::Armor->value => Item::parse('3.22.0.0007.01.0.1029.0000.14.0.2.00.00.0.022.022.000000.pad_armor'),
            // escudo: defensa 1 + 1, tasa (1*3 + 1*3) + opción 1 (5)
            EquipmentSlot::Hand2->value => Item::parse('9.22.0.0001.01.1.1026.0000.00.8.1.00.00.0.022.022.000000.small_shield'),
        ];

        $stats = $this->calculator->calculate(self::character(CharacterClass::DarkKnight), $equipment);

        self::assertSame([4 + 14 + 4, 7 + 17 + 4], [$stats->damageMin, $stats->damageMax]);
        self::assertSame(6 + 10 + 8 + 2, $stats->defense);
        self::assertSame(6 + 6 + 5, $stats->defenseRate);
        self::assertSame(10, $stats->luck, 'dos ítems con luck = 10% de crítico');
        self::assertSame(['3', '8'], $stats->skills);
    }

    public function testOnlyTheStrongerOfTwoWeaponsCounts(): void
    {
        $weak = Item::parse('1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris');
        $strong = Item::parse('1.13.1.0026.00.0.1058.2039.00.0.0.00.00.0.027.027.000000.katana');

        $a = $this->calculator->calculate(self::character(CharacterClass::DarkKnight), [3 => $weak, 6 => $strong]);
        $b = $this->calculator->calculate(self::character(CharacterClass::DarkKnight), [3 => $strong, 6 => $weak]);

        self::assertSame(7 + 26, $a->damageMax);
        self::assertSame($a->damageMax, $b->damageMax);
    }

    public function testExcellentOptionsAndWings(): void
    {
        $equipment = [
            // arma excellent opción 1: +10% de golpe excellent
            EquipmentSlot::Hand1->value => Item::parse('1.12.1.0011.00.0.1027.2027.00.0.0.01.00.0.022.022.000000.kris'),
            // armadura excellent opción 3: +4% de vida
            EquipmentSlot::Armor->value => Item::parse('3.22.0.0007.00.0.1029.0000.14.0.0.03.00.0.022.022.000000.pad_armor'),
            // casco excellent opción 6: refleja 5%
            EquipmentSlot::Helm->value => Item::parse('5.22.0.0005.00.0.1029.0000.14.0.0.06.00.0.022.022.000000.pad_helm'),
            // alas de 1.er nivel +0 con opción C "vida": daño +12%, absorbe 12%, vida +50
            EquipmentSlot::Wings->value => Item::parse('8.32.1.0010.00.0.4180.0000.00.1.0.00.00.0.200.200.000000.wings_of_elf'),
        ];

        $stats = $this->calculator->calculate(self::character(CharacterClass::DarkKnight), $equipment);

        self::assertSame(10, $stats->excellentRate);
        self::assertSame(5, $stats->reflect);
        self::assertSame(12, $stats->damageBonus);
        self::assertSame(12, $stats->damageAbsorb);
        self::assertSame((int) floor((112 + 50) * 1.04), $stats->hpMax);
    }

    public function testStaffRaisesWizardryAndRingsGiveResistance(): void
    {
        $equipment = [
            // bastón: poder 20 → aumento de 16%; opción 2 → +8 de daño mágico
            EquipmentSlot::Hand1->value => Item::parse('1.13.3.0020.00.0.1040.0000.02.0.2.00.00.0.030.030.000000.skull_staff'),
            // anillo de hielo +2: resistencia 3
            EquipmentSlot::Ring1->value => Item::parse('B.11.3.0000.02.0.0000.0000.00.0.1.00.00.0.050.050.000000.ring_of_ice'),
        ];

        $stats = $this->calculator->calculate(self::character(CharacterClass::DarkWizard), $equipment);

        self::assertSame(16, $stats->staffRise);
        self::assertSame([3 + 8, 7 + 8], [$stats->wizDamageMin, $stats->wizDamageMax]);
        self::assertSame(3, $stats->elementResist['ice']);
        self::assertSame(1, $stats->hpRegeneration);
    }
}
