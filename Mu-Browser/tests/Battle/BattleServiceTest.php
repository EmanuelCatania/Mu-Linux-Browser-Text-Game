<?php

declare(strict_types=1);

namespace Mu\Tests\Battle;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\GoldenRepository;
use Mu\Data\ItemRepository;
use Mu\Data\PresenceRepository;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleException;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\MapCatalog;
use Mu\Support\Rng;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** El servicio de batalla contra la base: qué se guarda y cuándo. */
final class BattleServiceTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private BattleRepository $battles;
    private ItemRepository $items;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->battles = new BattleRepository($this->pdo);
        $this->items = new ItemRepository($this->pdo);
        $this->accountId = $this->accounts->insert('Tester', 't@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now())->id;
    }

    private function service(Rng $rng): BattleService
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/data/game.php';

        return new BattleService(
            new BattleEngine(new SkillCatalog($root . '/data/skills.php'), new DropGenerator($root . '/data/drop', $config['drop']), $config),
            new StatsCalculator(),
            new MapCatalog($root . '/data/maps'),
            new GoldenInvasion(new GoldenRepository($this->pdo), $config['golden']['hours']),
            $this->battles,
            $this->characters,
            $this->accounts,
            $this->items,
            new PresenceRepository($this->pdo),
            $this->clock,
            $rng,
            $config,
            new \Mu\Domain\Quest\QuestCatalog($root . '/data/quests.xml'),
        );
    }

    private function wizard(string $name = 'Merlin', ?int $accountId = null): Character
    {
        return $this->characters->insert($accountId ?? $this->accountId, $name, CharacterClass::DarkWizard, $this->clock->now());
    }

    private function reload(Character $character): Character
    {
        return $this->characters->findById($character->id) ?? self::fail('personaje inexistente');
    }

    public function testEnterCreatesABattleAndMarksPresence(): void
    {
        $service = $this->service(new FakeRng([2], 'max'));
        $merlin = $this->wizard();

        $battle = $service->enter($merlin, 'lorencia', 'spot1');

        self::assertCount(2, $battle->enemies);
        self::assertSame([60, 60, 100], [$battle->hp, $battle->mp, $battle->expPercent]);
        self::assertEquals($battle, $service->current($merlin));
        self::assertSame(['Merlin'], array_column($service->playersAt('lorencia', 'spot1'), 'name'));
    }

    public function testEnteringTheSameSpotAgainResumesTheBattle(): void
    {
        $service = $this->service(new FakeRng([3], 'max'));
        $merlin = $this->wizard();
        $first = $service->enter($merlin, 'lorencia', 'spot1');

        $second = $this->service(new FakeRng([1], 'max'))->enter($merlin, 'lorencia', 'spot1');

        self::assertEquals($first, $second);
        self::assertCount(3, $second->enemies);
    }

    public function testUnknownSpotIsRejected(): void
    {
        $service = $this->service(new FakeRng());
        $merlin = $this->wizard();

        foreach ([['nowhere', 'spot1'], ['lorencia', 'spot99'], ['../x', 'spot1']] as [$map, $spot]) {
            try {
                $service->enter($merlin, $map, $spot);
                self::fail('se esperaba un error');
            } catch (BattleException $e) {
                self::assertSame('battle.error_spot', $e->getMessage());
            }
        }
        self::assertNull($service->current($merlin));
    }

    public function testProgressIsSavedWhenTheRoundCloses(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $service->enter($merlin, 'lorencia', 'spot1');

        $battle = $service->act($merlin, '30', 0);
        self::assertTrue($battle->roundOver());
        self::assertSame(0, $this->reload($merlin)->experience, 'a mitad de ronda todavía no se guardó nada');

        $next = $service->nextRound($merlin);

        $saved = $this->reload($merlin);
        self::assertSame(6, $saved->experience);
        self::assertSame(1, (int) $this->pdo->query('SELECT monsters_killed FROM characters')->fetchColumn());
        self::assertSame(5000 + 10, $this->accounts->findById($this->accountId)?->zen);
        // regenera 1% por ronda (redondeado arriba): vida 60 → 61, maná 59 → 60
        self::assertSame([61, 60], [$saved->hpCur, $saved->mpCur]);
        self::assertFalse($next->roundOver(), 'ronda nueva con enemigos nuevos');
        self::assertSame([0, 0], [$next->kills, $next->zen], 'lo guardado no se vuelve a sumar');
    }

    public function testLevelUpGivesFivePoints(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $this->characters->saveProgress($merlin->id, 1, 0, 9, 60, 60, 0);
        $merlin = $this->reload($merlin);
        $service->enter($merlin, 'lorencia', 'spot1');
        $service->act($merlin, '30', 0);

        $service->leave($merlin);

        $saved = $this->reload($merlin);
        self::assertSame([2, 5, 10], [$saved->level, $saved->points, $saved->experience]);
        self::assertNull($service->current($merlin));
        self::assertSame([], $service->playersAt('lorencia', 'spot1'));
    }

    public function testCannotStartTheNextRoundWithEnemiesStanding(): void
    {
        $service = $this->service(new FakeRng([2], 'max'));
        $merlin = $this->wizard();
        $service->enter($merlin, 'lorencia', 'spot1');

        $this->expectExceptionObject(new BattleException('battle.error_round_open'));
        $service->nextRound($merlin);
    }

    public function testDefeatSavesThePenaltyAndRestoresHp(): void
    {
        $merlin = $this->wizard();
        $this->characters->saveProgress($merlin->id, 2, 0, 30, 1, 60, 0);
        $merlin = $this->reload($merlin);
        // 1 enemigo; el golpe (6) falla (1) y la araña pega 7 (100)
        $service = $this->service(new FakeRng([1, 6, 1, 7, 100]));
        $service->enter($merlin, 'lorencia', 'spot1');

        $battle = $service->act($merlin, '30', 0);

        self::assertTrue($battle->defeated);
        $saved = $this->reload($merlin);
        // data/game.php: defeat_hp_percent = 100 → vuelve con la vida completa de un Dark Wizard nivel 2 (62)
        self::assertSame([20, 62, 2], [$saved->experience, $saved->hpCur, $saved->level]);

        // la pantalla de derrota sigue visible hasta que sale; salir no vuelve a guardar nada
        self::assertTrue($service->current($merlin)?->defeated);
        $service->leave($merlin);
        self::assertSame(20, $this->reload($merlin)->experience);
        self::assertNull($service->current($merlin));
    }

    public function testChangingSpotSettlesThePreviousBattle(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $service->enter($merlin, 'lorencia', 'spot1');
        $service->act($merlin, '30', 0);

        $battle = $this->service(new FakeRng([1], 'max'))->enter($merlin, 'lorencia', 'spot2');

        self::assertSame('spot2', $battle->spot);
        self::assertSame(6, $battle->experience, 'arranca con lo ganado en el spot anterior');
        self::assertSame(6, $this->reload($merlin)->experience);
        self::assertSame([], $service->playersAt('lorencia', 'spot1'));
    }

    public function testSpotCapacity(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        for ($i = 0; $i < 10; $i++) {
            $account = $this->accounts->insert("User$i", "u$i@example.test", 'hash', 'es', '10.0.0.1', $this->clock->now());
            $service->enter($this->wizard("Mage$i", $account->id), 'lorencia', 'spot1');
        }
        $late = $this->wizard('Late');

        try {
            $service->enter($late, 'lorencia', 'spot1');
            self::fail('se esperaba spot lleno');
        } catch (BattleException $e) {
            self::assertSame('battle.error_spot_full', $e->getMessage());
        }

        // a los 5 minutos sin jugar, los demás dejan de contar
        $this->clock->advance('+6 minutes');
        self::assertNotEmpty($service->enter($late, 'lorencia', 'spot1')->enemies);
    }

    public function testPickingUpADropStoresItOnce(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $battle = $service->enter($merlin, 'lorencia', 'spot1');
        $battle->enemies[0]['dead'] = true;
        $battle->enemies[0]['item'] = '1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris';
        self::assertTrue($this->battles->save($merlin->id, $battle, 1, $this->clock->now()));

        $item = $service->pick($merlin, 0);

        self::assertSame('kris', $item->name);
        self::assertSame([0 => 'kris'], array_map(static fn ($i) => $i->name, $this->items->items(Container::Inventory, $merlin->id)));
        self::assertTrue($service->current($merlin)?->enemies[0]['taken']);

        $this->expectExceptionObject(new BattleException('battle.error_no_item'));
        $service->pick($merlin, 0);
    }

    public function testPickingUpWithAFullInventoryLeavesTheItemOnTheGround(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        // 8 ítems de 2x4 llenan la grilla de 8x8
        $big = \Mu\Domain\Item\Item::parse('2.24.1.0050.00.0.1100.0000.00.0.0.00.00.0.050.050.000000.great_sword');
        for ($i = 0; $i < 8; $i++) {
            self::assertNotNull($this->items->addToInventory($merlin->id, $big, $this->clock->now()));
        }
        $battle = $service->enter($merlin, 'lorencia', 'spot1');
        $battle->enemies[0]['dead'] = true;
        $battle->enemies[0]['item'] = '1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris';
        $this->battles->save($merlin->id, $battle, 1, $this->clock->now());

        try {
            $service->pick($merlin, 0);
            self::fail('se esperaba inventario lleno');
        } catch (BattleException $e) {
            self::assertSame('battle.error_no_space', $e->getMessage());
        }

        self::assertFalse($service->current($merlin)?->enemies[0]['taken'], 'se puede volver a intentar');
        self::assertCount(8, $this->items->items(Container::Inventory, $merlin->id));
    }

    public function testStaleSaveIsRejected(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $service->enter($merlin, 'lorencia', 'spot1');
        [$battle, $version] = $this->battles->find($merlin->id) ?? self::fail('sin batalla');

        self::assertTrue($this->battles->save($merlin->id, $battle, $version, $this->clock->now()));
        self::assertFalse($this->battles->save($merlin->id, $battle, $version, $this->clock->now()), 'la misma versión no puede guardarse dos veces');
    }

    public function testActingWithoutABattleFails(): void
    {
        $this->expectExceptionObject(new BattleException('battle.error_none'));
        $this->service(new FakeRng())->act($this->wizard(), BattleEngine::ATTACK, 0);
    }

    public function testPotionInBattleUsesTheStrongestAndSpendsTheTurn(): void
    {
        $merlin = $this->wizard();
        $this->characters->setVitals($merlin->id, 10, 60);
        $merlin = $this->reload($merlin);
        $small = \Mu\Domain\Item\Item::parse('F.11.2.0020.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.small_healing_potion');
        $large = \Mu\Domain\Item\Item::parse('F.11.4.0040.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.large_healing_potion');
        $this->items->addToInventory($merlin->id, $small, $this->clock->now());
        $this->items->addToInventory($merlin->id, $large, $this->clock->now());
        // 1 enemigo; la araña tira 7 y falla (tirada 1)
        $service = $this->service(new FakeRng([1, 7, 1]));
        $service->enter($merlin, 'lorencia', 'spot1');
        self::assertSame(['potion_hp' => 2, 'potion_mp' => 0, 'potion_antidote' => 0], $service->potions($merlin));

        $battle = $service->act($merlin, 'potion_hp', null);

        // 40% de 61 = 24
        self::assertSame(['heal', 'enemy_miss'], array_column($battle->log, 'type'));
        self::assertSame(34, $battle->hp);
        self::assertSame(2, $battle->turn);
        self::assertSame(['small_healing_potion'], array_values(array_map(static fn ($i) => $i->name, $this->items->items(Container::Inventory, $merlin->id))));
    }

    public function testPotionActionWithoutPotionsFails(): void
    {
        $merlin = $this->wizard();
        $service = $this->service(new FakeRng([1], 'max'));
        $service->enter($merlin, 'lorencia', 'spot1');

        try {
            $service->act($merlin, 'potion_mp', null);
            self::fail('se esperaba un error');
        } catch (BattleException $e) {
            self::assertSame('battle.error_no_potion', $e->getMessage());
        }
        self::assertSame(1, $service->current($merlin)?->turn, 'no se gastó el turno');
    }

    public function testArrowsAreSpentAndRunOut(): void
    {
        $elf = $this->characters->insert($this->accountId, 'Robin', CharacterClass::FairyElf, $this->clock->now());
        $bow = \Mu\Domain\Item\Item::parse('2.24.8.0020.00.0.0000.0000.00.0.0.00.00.0.030.030.000000.short_bow');
        $arrows = \Mu\Domain\Item\Item::parse('1.12.6.0000.00.0.0000.0000.00.0.0.00.00.0.002.255.000000.arrows');
        $this->items->put(Container::Equipment, $elf->id, 3, $bow, $this->clock->now());
        $this->items->put(Container::Equipment, $elf->id, 6, $arrows, $this->clock->now());

        // 3 enemigos; cada golpe mata una araña (todo al máximo)
        $service = $this->service(new FakeRng([3], 'max'));
        $battle = $service->enter($elf, 'lorencia', 'spot1');
        self::assertSame(2, $battle->arrows);

        $service->act($elf, BattleEngine::ATTACK, 0);
        self::assertSame(1, $service->current($elf)?->arrows);

        $battle = $service->act($elf, BattleEngine::ATTACK, 1);
        // se acabaron: desaparecen del equipo en el momento
        self::assertSame(-1, $battle->arrows);
        self::assertSame([3], array_keys($this->items->items(Container::Equipment, $elf->id)));

        $battle = $service->act($elf, BattleEngine::ATTACK, 2);
        self::assertSame('no_arrows', $battle->log[0]['type']);
        self::assertFalse($battle->enemies[2]['dead']);
    }

    public function testRemainingArrowsAreSavedWhenLeaving(): void
    {
        $elf = $this->characters->insert($this->accountId, 'Robin', CharacterClass::FairyElf, $this->clock->now());
        $this->items->put(Container::Equipment, $elf->id, 3, \Mu\Domain\Item\Item::parse('2.24.8.0020.00.0.0000.0000.00.0.0.00.00.0.030.030.000000.short_bow'), $this->clock->now());
        $this->items->put(Container::Equipment, $elf->id, 6, \Mu\Domain\Item\Item::parse('1.12.6.0000.00.0.0000.0000.00.0.0.00.00.0.100.255.000000.arrows'), $this->clock->now());
        $service = $this->service(new FakeRng([1], 'max'));
        $service->enter($elf, 'lorencia', 'spot1');
        $service->act($elf, BattleEngine::ATTACK, 0);

        $service->leave($elf);

        self::assertSame(99, $this->items->items(Container::Equipment, $elf->id)[6]->durability);
    }

    public function testPetLosesLifeOnHardHitsAndDisappearsAtZero(): void
    {
        $merlin = $this->wizard();
        $pet = \Mu\Domain\Item\Item::parse('C.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.001.255.000000.imp');
        $this->items->put(Container::Equipment, $merlin->id, 0, $pet, $this->clock->now());
        // spot 5 de Lorencia: monstruos que pegan fuerte. 1 enemigo, el último de la lista.
        // Turno: golpe propio (15) que falla (1); el enemigo pega al máximo, no falla (100) y la mascota pierde vida (1).
        $service = $this->service(new FakeRng([1, 99, 15, 1, 999, 100, 1]));
        $battle = $service->enter($merlin, 'lorencia', 'spot5');
        self::assertSame(1, $battle->petLife);

        $battle = $service->act($merlin, '30', 0);

        self::assertSame(-1, $battle->petLife);
        self::assertSame([], $this->items->items(Container::Equipment, $merlin->id), 'la mascota murió');
        self::assertSame(0, $service->stats($merlin)->damageBonus, 'y con ella su bonus de daño');
    }

    public function testDeletingTheCharacterRemovesItsBattleAndItems(): void
    {
        $service = $this->service(new FakeRng([1], 'max'));
        $merlin = $this->wizard();
        $service->enter($merlin, 'lorencia', 'spot1');
        $this->items->addToInventory($merlin->id, \Mu\Domain\Item\Item::parse('1.12.1.0011.00.0.1027.2027.00.0.0.00.00.0.022.022.000000.kris'), $this->clock->now());

        $this->characters->delete($merlin->id);

        foreach (['battles', 'presence', 'items'] as $table) {
            self::assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), $table);
        }
        self::assertInstanceOf(Battle::class, new Battle());
    }
}
