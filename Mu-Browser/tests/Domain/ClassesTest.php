<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\CharacterService;
use Mu\Domain\Item\Item;
use Mu\Domain\Npc\ChaosMachine;
use Mu\Domain\Shop\ShopCatalog;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Magic Gladiator y Dark Lord (clases que se desbloquean por nivel), alas de 3.er nivel y capas. */
final class ClassesTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private CharacterService $service;
    private Account $account;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->pdo = TestDatabase::create();
        $clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->service = new CharacterService($this->characters, $this->accounts, $clock, 10_000_000);
        $this->account = $this->accounts->insert('uArthur', 'arthur@example.test', 'hash', 'es', '10.0.0.1', $clock->now());
        $this->service->create($this->account(), 'Arthur', CharacterClass::DarkKnight->value);
    }

    public function testMagicGladiatorAndDarkLordUnlockByLevelForTheWholeAccount(): void
    {
        $create = function (string $name, CharacterClass $class): ?string {
            try {
                $this->service->create($this->account(), $name, $class->value);

                return null;
            } catch (ValidationException $e) {
                return $e->errors[0];
            }
        };

        self::assertSame('error.character_class', $create('Gladius', CharacterClass::MagicGladiator));
        self::assertSame('error.character_class', $create('Gladius', CharacterClass::BladeKnight), 'las evoluciones nunca se crean');

        $this->pdo->exec('UPDATE characters SET level = 220');
        self::assertNull($create('Gladius', CharacterClass::MagicGladiator));
        self::assertSame('error.character_class', $create('Lord', CharacterClass::DarkLord));

        // con un reset el nivel vuelve a 1 pero lo desbloqueado queda
        $this->pdo->exec("UPDATE characters SET level = 1, resets = 1 WHERE name = 'Arthur'");
        self::assertNull($create('Lord', CharacterClass::DarkLord));

        $lord = $this->characters->findByName('Lord') ?? self::fail('sin Dark Lord');
        self::assertSame([25, ['80']], [$lord->command, $lord->skills]);
        self::assertSame(0, ($this->characters->findByName('Gladius') ?? self::fail('sin MG'))->command);
    }

    public function testNewClassesStartWithTheirOfficialValues(): void
    {
        $sheet = static function (CharacterClass $class): array {
            $s = $class->startingStats();
            $stats = new StatsCalculator()->calculate(
                new Character(1, 1, 'Test', $class, 1, $s['strength'], $s['agility'], $s['stamina'], $s['energy'], 0, 0, 0, $s['hp'], $s['mp'], command: $s['command']),
            );

            return [$stats->hpMax, $stats->mpMax, $stats->damageMin, $stats->damageMax, $stats->wizDamageMax, $stats->defense, $stats->command];
        };

        self::assertSame([110, 60, 6, 9, 6, 6, 0], $sheet(CharacterClass::MagicGladiator));
        self::assertSame([90, 40, 4, 6, 0, 2, 25], $sheet(CharacterClass::DarkLord));
        self::assertSame([7, 7, 5, 5], [
            CharacterClass::MagicGladiator->pointsPerLevel(), CharacterClass::DarkLord->pointsPerLevel(),
            CharacterClass::DarkKnight->pointsPerLevel(), CharacterClass::BladeKnight->pointsPerLevel(),
        ]);

        $skills = new SkillCatalog($this->root . '/data/skills.php');
        foreach (array_keys(CharacterClass::UNLOCKABLE) as $class) {
            foreach (CharacterClass::from($class)->startingStats()['skills'] as $code) {
                self::assertTrue($skills->has($code));
                self::assertFileExists($this->root . "/public/assets/img/skills/skill$code.gif");
            }
            self::assertFileExists($this->root . "/public/assets/img/classes/$class.gif");
        }
    }

    public function testOnlyTheDarkLordSpendsPointsOnCommand(): void
    {
        $this->pdo->exec('UPDATE characters SET points = 3');
        try {
            $this->service->addStatPoint($this->account(), 'command');
            self::fail('Un Dark Knight no tiene Comando');
        } catch (ValidationException $e) {
            self::assertSame('error.stat_invalid', $e->errors[0]);
        }

        $this->pdo->exec('UPDATE characters SET class = 6, command = 25');
        $this->service->addStatPoint($this->account(), 'command');
        $lord = $this->service->active($this->account()) ?? self::fail('sin personaje');
        self::assertSame([26, 2], [$lord->command, $lord->points]);

        // los pergaminos que piden Comando se comparan contra ese atributo
        $scroll = Item::parse('G.12.6.0000.00.0.3102.6300.06.0.0.00.00.0.000.082.000000.scroll_of_critical_damage');
        self::assertSame([['Energy', 102], ['Command', 300]], array_map(static fn (array $r): array => [$r['kind']->name, $r['value']], $scroll->requirements()));
        self::assertSame(26, $scroll->requirements()[1]['kind']->valueOf($lord));
        self::assertTrue($scroll->canBeUsedBy(CharacterClass::DarkLord));
        self::assertFalse($scroll->canBeUsedBy(CharacterClass::MagicGladiator));
    }

    public function testDarkRavenGoesInTheFreeHandAndAttacksEveryTurn(): void
    {
        $horse = Item::parse('C.11.A.0000.00.0.4218.6150.06.E.0.00.00.0.255.255.000000.dark_horse');
        $raven = Item::parse('C.11.B.0000.00.0.4218.6180.06.0.0.00.00.0.255.255.000000.dark_raven');
        self::assertSame([\Mu\Domain\Stats\EquipmentSlot::Pet], \Mu\Domain\Inventory\EquipRules::slotsFor($horse));
        self::assertSame([\Mu\Domain\Stats\EquipmentSlot::Hand2], \Mu\Domain\Inventory\EquipRules::slotsFor($raven));

        $lord = new Character(1, 1, 'Lord', CharacterClass::DarkLord, 250, 300, 100, 100, 100, 0, 0, 0, 500, 200, command: 400);
        $both = new StatsCalculator()->calculate($lord, [
            \Mu\Domain\Stats\EquipmentSlot::Pet->value => $horse,
            \Mu\Domain\Stats\EquipmentSlot::Hand2->value => $raven,
        ]);
        $alone = new StatsCalculator()->calculate($lord);

        self::assertSame([150, 213], [$both->ravenMin, $both->ravenMax]);
        self::assertSame([0, 0], [$alone->ravenMin, $alone->ravenMax]);
        self::assertSame($alone->defense + 20, $both->defense, 'el caballo suma Comando/20 de defensa');
        self::assertSame([$alone->damageMin, $alone->damageMax, 15], [$both->damageMin, $both->damageMax, $both->damageAbsorb], 'el cuervo no cambia el golpe del personaje');
        self::assertContains('E', $both->skills, 'el caballo enseña Earthshake');

        $config = require $this->root . '/data/game.php';
        $engine = new \Mu\Domain\Battle\BattleEngine(new SkillCatalog($this->root . '/data/skills.php'), new \Mu\Domain\Item\DropGenerator($this->root . '/data/drop', $config['drop']), $config);
        $battle = new \Mu\Domain\Battle\Battle();
        $battle->hp = $both->hpMax;
        $map = new \Mu\Domain\World\MapCatalog($this->root . '/data/maps')->get('lorencia') ?? self::fail('sin mapa');
        $engine->startRound($battle, $map, $map->spots['spot1'], null, new FakeRng([1], 'min'));
        $battle->enemies[0]['hpCur'] = $battle->enemies[0]['hp'] = 100000;
        $engine->act($battle, $both, \Mu\Domain\Battle\BattleEngine::ATTACK, 0, new FakeRng([], 'min'), new \DateTimeImmutable('2026-01-05 12:00:00'));
        self::assertContains('raven_dmg', array_column($battle->log, 'type'));
    }

    public function testWingsAndCapesByTier(): void
    {
        $rates = static fn (string $code): array => Item::parse($code)->with(['level' => 3])->wingRates();

        self::assertSame([18, 18], $rates('8.42.1.0010.00.0.4180.0000.01.0.0.00.00.0.200.200.000000.satan_wings'));
        self::assertSame([35, 28], $rates('8.53.2.0030.00.0.4215.0000.05.0.0.00.00.0.220.220.000000.darkness_wings'));
        self::assertSame([45, 45], $rates('8.53.3.0060.00.0.4400.0000.31.0.0.00.00.0.240.240.000000.wing_of_storm'));
        self::assertSame([26, 16], $rates('8.23.4.0015.00.0.4180.0000.06.0.0.00.00.0.200.200.000000.cape_of_lord'));
        self::assertSame([45, 30], $rates('8.23.5.0045.00.0.4400.0000.36.0.0.00.00.0.240.240.000000.cape_of_emperor'));
    }

    public function testThirdLevelWingsComeFromTheSecondLevelOnesOfTheSameClass(): void
    {
        $chaos = Item::parse('E.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_chaos');
        $feather = Item::parse('E.12.8.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.condor_feather');
        $flame = Item::parse('E.12.9.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.condor_flame');
        $made = static function (string $wings, array $rolls) use ($chaos, $feather, $flame) {
            return ChaosMachine::mix([Item::parse($wings), $feather, $flame, $chaos], new FakeRng($rolls));
        };
        $dragon = '8.53.2.0030.02.0.4215.0000.21.0.0.00.00.0.220.220.000000.dragon_wings';

        // opción 1, luck 1, tirada 50: alas +2 → 40 + 10 = 50% de éxito
        $storm = $made($dragon, [1, 1, 50]);
        self::assertTrue($storm->success);
        self::assertSame(['wing_of_storm', 1, 1, 0], [$storm->items[0]->name, $storm->items[0]->option, $storm->items[0]->luck, $storm->items[0]->level]);

        $failed = $made($dragon, [1, 1, 51]);
        self::assertFalse($failed->success);
        self::assertSame(['dragon_wings', 0], [$failed->items[0]->name, $failed->items[0]->level], 'devuelve las alas, en +0');

        self::assertSame('cape_of_emperor', $made('8.23.4.0015.00.0.4180.0000.06.0.0.00.00.0.200.200.000000.cape_of_lord', [0, 0, 1])->items[0]->name);
        self::assertSame('wing_of_ruin', $made('8.53.2.0030.00.0.4215.0000.05.0.0.00.00.0.220.220.000000.darkness_wings', [0, 0, 1])->items[0]->name);
    }

    public function testShopsSellBasicsOnlyAndNothingOverlaps(): void
    {
        $shops = new ShopCatalog($this->root . '/data/shops');
        $names = static fn (string $id): array => array_map(static fn (Item $i): string => $i->name, $shops->stock($id));

        // el set Dragon Knight es de nivel 380: ya no se compra, cae de los monstruos más fuertes
        foreach (array_keys(ShopCatalog::SHOPS) as $id) {
            self::assertNotContains('dragon_knight_armor', $names($id));
        }
        self::assertContains('leather_armor', $names('lorencia2'));

        // ningún ítem pisa a otro ni se sale de la grilla
        foreach (array_keys(ShopCatalog::SHOPS) as $id) {
            $taken = [];
            foreach ($shops->stock($id) as $cell => $item) {
                self::assertLessThanOrEqual(8, $cell % 8 + $item->width, "$id: {$item->name} se sale de la grilla");
                self::assertLessThanOrEqual(ShopCatalog::ROWS, intdiv($cell, 8) + $item->height, "$id: {$item->name} se sale de la grilla");
                for ($r = 0; $r < $item->height; $r++) {
                    for ($c = 0; $c < $item->width; $c++) {
                        $at = $cell + $r * 8 + $c;
                        self::assertArrayNotHasKey($at, $taken, "$id: {$item->name} pisa a " . ($taken[$at] ?? ''));
                        $taken[$at] = $item->name;
                    }
                }
            }
        }
    }

    private function account(): Account
    {
        return $this->accounts->findById($this->account->id) ?? self::fail('cuenta inexistente');
    }
}
