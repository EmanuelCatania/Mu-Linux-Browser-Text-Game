<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Inventory\Jewels;
use Mu\Domain\Item\Item;
use Mu\Domain\Shop\ShopCatalog;
use Mu\Domain\Shop\ShopService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\EquipmentSlot;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Support\Rng;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Inventario, equipo, baúl, joyas y tiendas. */
final class InventoryTest extends TestCase
{
    // sin requisitos ni clase, salvo donde se indica
    private const string KRIS = '1.12.1.0011.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.kris';
    private const string GREAT_SWORD = '2.24.1.0050.00.0.0000.0000.00.0.0.00.00.0.050.050.000000.great_sword';
    private const string SHIELD = '9.22.0.0001.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.small_shield';
    private const string BOW = '2.24.8.0020.00.0.0000.0000.00.0.0.00.00.0.030.030.000000.short_bow';
    private const string ARROWS = '1.12.6.0000.00.0.0000.0000.00.0.0.00.00.0.255.255.000000.arrows';
    private const string BOLTS = '1.12.7.0000.00.0.0000.0000.00.0.0.00.00.0.255.255.000000.bolts';
    private const string HELM = '5.22.0.0005.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.bronze_helm';
    private const string HEAVY_SWORD = '1.13.1.0030.00.0.1086.0000.00.0.0.00.00.0.030.030.000000.gladius';
    private const string WIZARD_STAFF = '1.13.3.0020.00.0.0000.0000.02.0.0.00.00.0.030.030.000000.skull_staff';
    private const string POTION = 'F.11.2.0020.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.small_healing_potion';
    private const string MANA = 'F.11.6.0020.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.small_mana_potion';
    private const string ANTIDOTE = 'F.11.A.0010.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.antidote';
    private const string ORB_TWISTING = 'G.11.1.0000.00.0.4001.0000.01.0.0.00.00.0.000.022.000000.orb_of_twisting_slash';
    private const string ORB_HIGH_LEVEL = 'G.11.1.0000.00.0.4080.0000.01.0.0.00.00.0.000.022.000000.orb_of_twisting_slash';
    private const string FRUIT_STR = 'J.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.fruit_of_strength';
    private const string BLESS = 'D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless';
    private const string SOUL = 'D.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul';

    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private ItemRepository $items;
    private BattleRepository $battles;
    private int $accountId;
    private Character $knight;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock();
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->items = new ItemRepository($this->pdo);
        $this->battles = new BattleRepository($this->pdo);
        $this->accountId = $this->accounts->insert('Tester', 't@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now())->id;
        $this->knight = $this->characters->insert($this->accountId, 'Arthur', CharacterClass::DarkKnight, $this->clock->now());
    }

    private function account(): Account
    {
        return $this->accounts->findById($this->accountId) ?? self::fail('cuenta inexistente');
    }

    private function knight(): Character
    {
        return $this->characters->findById($this->knight->id) ?? self::fail('personaje inexistente');
    }

    private function inventory(?Rng $rng = null): InventoryService
    {
        return new InventoryService(
            $this->items,
            $this->characters,
            $this->battles,
            new StatsCalculator(),
            new SkillCatalog(dirname(__DIR__, 2) . '/data/skills.php'),
            new Transaction($this->pdo),
            $rng ?? new FakeRng(),
        );
    }

    private function shop(): ShopService
    {
        return new ShopService(
            new ShopCatalog(dirname(__DIR__, 2) . '/data/shops'),
            $this->items,
            $this->accounts,
            $this->battles,
            new Transaction($this->pdo),
            $this->clock,
        );
    }

    private function give(string $code, Container $container = Container::Inventory, ?int $slot = null): int
    {
        $owner = $container->ownedByAccount() ? $this->accountId : $this->knight->id;
        if ($slot === null) {
            return $this->items->addFirstFree($container, $owner, Item::parse($code), $this->clock->now()) ?? self::fail('sin lugar');
        }
        $this->items->put($container, $owner, $slot, Item::parse($code), $this->clock->now());

        return $slot;
    }

    /** @return array<int, string> nombres por slot */
    private function names(Container $container): array
    {
        $owner = $container->ownedByAccount() ? $this->accountId : $this->knight->id;

        return array_map(static fn (Item $i): string => $i->name, $this->items->items($container, $owner));
    }

    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (InventoryException $e) {
            return $e->getMessage();
        }

        self::fail('Se esperaba una InventoryException');
    }

    private function move(Container $from, int $fromSlot, Container $to, int $toSlot): void
    {
        $this->inventory()->move($this->account(), $this->knight(), $from, $fromSlot, $to, $toSlot);
    }

    // --- mover dentro de grillas ---

    public function testMovesInsideTheInventory(): void
    {
        $this->give(self::KRIS); // 1x2 en la celda 0 (ocupa 0 y 8)

        $this->move(Container::Inventory, 0, Container::Inventory, 8); // se pisa a sí mismo: vale
        self::assertSame([8 => 'kris'], $this->names(Container::Inventory));

        $this->move(Container::Inventory, 8, Container::Inventory, 55); // última columna, filas 7 y 8
        self::assertSame([55 => 'kris'], $this->names(Container::Inventory));
    }

    public function testRejectsOverlapsAndPositionsOutsideTheGrid(): void
    {
        $this->give(self::KRIS, Container::Inventory, 0);
        $this->give(self::SHIELD, Container::Inventory, 2); // 2x2: ocupa 2, 3, 10, 11

        foreach ([2, 3, 10, 11, 60 /* no entra de alto */, 64, 999] as $target) {
            self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Inventory, $target)), "celda $target");
        }
        // el escudo de 2 de ancho no puede empezar en la última columna
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->move(Container::Inventory, 2, Container::Inventory, 15)));
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->move(Container::Inventory, 30, Container::Inventory, 31)));
        self::assertSame([0 => 'kris', 2 => 'small_shield'], $this->names(Container::Inventory));
    }

    // --- equipar ---

    public function testEquipsAndUnequips(): void
    {
        $this->give(self::KRIS);

        $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value);
        self::assertSame([EquipmentSlot::Hand1->value => 'kris'], $this->names(Container::Equipment));
        self::assertSame([], $this->names(Container::Inventory));

        $this->move(Container::Equipment, EquipmentSlot::Hand1->value, Container::Equipment, EquipmentSlot::Hand2->value);
        self::assertSame([EquipmentSlot::Hand2->value => 'kris'], $this->names(Container::Equipment));

        $this->move(Container::Equipment, EquipmentSlot::Hand2->value, Container::Inventory, 20);
        self::assertSame([20 => 'kris'], $this->names(Container::Inventory));
    }

    public function testEachItemTypeOnlyFitsItsSlot(): void
    {
        $this->give(self::KRIS, Container::Inventory, 0);
        $this->give(self::HELM, Container::Inventory, 2);
        $this->give(self::POTION, Container::Inventory, 7);

        self::assertSame('inventory.error_wrong_slot', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Helm->value)));
        self::assertSame('inventory.error_wrong_slot', $this->errorOf(fn () => $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Hand1->value)));
        self::assertSame('inventory.error_wrong_slot', $this->errorOf(fn () => $this->move(Container::Inventory, 7, Container::Equipment, EquipmentSlot::Hand1->value)), 'una poción no se equipa');
        self::assertSame('inventory.error_wrong_slot', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Equipment, 12)), 'slot inexistente');

        $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Helm->value);
        $this->give(self::HELM, Container::Inventory, 2);
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Helm->value)), 'slot ocupado');
    }

    public function testRequirementsAndClassAreEnforced(): void
    {
        $this->give(self::HEAVY_SWORD, Container::Inventory, 0); // pide 86 de fuerza; Arthur tiene 28
        $this->give(self::WIZARD_STAFF, Container::Inventory, 1); // sólo Dark Wizard

        self::assertSame('inventory.error_requirement', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value)));
        self::assertSame('inventory.error_class', $this->errorOf(fn () => $this->move(Container::Inventory, 1, Container::Equipment, EquipmentSlot::Hand1->value)));

        $this->pdo->exec('UPDATE characters SET strength = 86 WHERE id = ' . $this->knight->id);
        $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value);
        self::assertSame([EquipmentSlot::Hand1->value => 'gladius'], $this->names(Container::Equipment));
    }

    public function testTwoHandedWeaponsBlockTheOtherHand(): void
    {
        $this->give(self::GREAT_SWORD, Container::Inventory, 0);
        $this->give(self::SHIELD, Container::Inventory, 2);
        $this->give(self::KRIS, Container::Inventory, 4);

        $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value);
        self::assertSame('inventory.error_two_handed', $this->errorOf(fn () => $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Hand2->value)));
        self::assertSame('inventory.error_two_handed', $this->errorOf(fn () => $this->move(Container::Inventory, 4, Container::Equipment, EquipmentSlot::Hand2->value)));

        // al revés: con escudo puesto no entra el arma a dos manos
        $this->move(Container::Equipment, EquipmentSlot::Hand1->value, Container::Inventory, 0);
        $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Hand2->value);
        self::assertSame('inventory.error_two_handed', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value)));
        // …pero un arma de una mano sí
        $this->move(Container::Inventory, 4, Container::Equipment, EquipmentSlot::Hand1->value);
        self::assertCount(2, $this->names(Container::Equipment));
    }

    public function testBowsOnlyPairWithTheirAmmo(): void
    {
        $this->give(self::BOW, Container::Inventory, 0);
        $this->give(self::BOLTS, Container::Inventory, 2);
        $this->give(self::ARROWS, Container::Inventory, 3);

        $this->move(Container::Inventory, 0, Container::Equipment, EquipmentSlot::Hand1->value);
        self::assertSame('inventory.error_two_handed', $this->errorOf(fn () => $this->move(Container::Inventory, 2, Container::Equipment, EquipmentSlot::Hand2->value)), 'virotes con arco no');
        $this->move(Container::Inventory, 3, Container::Equipment, EquipmentSlot::Hand2->value);

        $stats = new StatsCalculator()->calculate($this->knight(), $this->items->items(Container::Equipment, $this->knight->id));
        self::assertTrue($stats->usesArrows);
        self::assertSame(255, $stats->arrows);
    }

    // --- baúl ---

    public function testVaultIsSharedByTheAccountButNotWithOthers(): void
    {
        $this->give(self::KRIS);
        $this->move(Container::Inventory, 0, Container::Vault, 119 - 8); // última columna, anteúltima fila
        self::assertSame([], $this->names(Container::Inventory));
        self::assertSame([111 => 'kris'], $this->names(Container::Vault));

        // otro personaje de la misma cuenta lo saca
        $merlin = $this->characters->insert($this->accountId, 'Merlin', CharacterClass::DarkWizard, $this->clock->now());
        $this->inventory()->move($this->account(), $merlin, Container::Vault, 111, Container::Inventory, 0);
        self::assertSame(['kris'], array_map(static fn (Item $i) => $i->name, $this->items->items(Container::Inventory, $merlin->id)));

        // otra cuenta no ve nada
        $other = $this->accounts->insert('Other', 'o@example.test', 'hash', 'es', '10.0.0.2', $this->clock->now());
        $thief = $this->characters->insert($other->id, 'Thief', CharacterClass::DarkKnight, $this->clock->now());
        $this->inventory()->move($this->account(), $merlin, Container::Inventory, 0, Container::Vault, 0);
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->inventory()->move($other, $thief, Container::Vault, 0, Container::Inventory, 0)));
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->move(Container::Vault, 0, Container::Vault, 120)), 'el baúl tiene 15 filas');
    }

    public function testNothingMovesDuringABattle(): void
    {
        $this->give(self::KRIS);
        $this->give(self::POTION);
        $this->battles->create($this->knight->id, new Battle(), $this->clock->now());

        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->move(Container::Inventory, 0, Container::Equipment, 3)));
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 1)));
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->shop()->buy($this->account(), $this->knight(), 'lorencia1', 0)));
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->shop()->sell($this->account(), $this->knight(), Container::Inventory, 0)));
    }

    // --- usar ---

    public function testPotionsRestoreAPercentageAndAreConsumed(): void
    {
        $this->characters->setVitals($this->knight->id, 10, 5);
        $this->give(self::POTION);
        $this->give(self::MANA);

        // Dark Knight nivel 1: 112 de vida, 20 de maná; las pociones chicas dan 20%
        $hp = $this->inventory()->use($this->account(), $this->knight(), 0);
        $mp = $this->inventory()->use($this->account(), $this->knight(), 1);

        self::assertSame(['key' => 'inventory.used_hp', 'params' => ['n' => 22]], $hp);
        self::assertSame(4, $mp['params']['n']);
        self::assertSame([32, 9], [$this->knight()->hpCur, $this->knight()->mpCur]);
        self::assertSame([], $this->names(Container::Inventory));
    }

    public function testPotionNeverGoesAboveTheMaximum(): void
    {
        $this->characters->setVitals($this->knight->id, 105, 20);
        $this->give(self::POTION);

        self::assertSame(7, $this->inventory()->use($this->account(), $this->knight(), 0)['params']['n']);
        self::assertSame(112, $this->knight()->hpCur);

        // con la vida completa no se toma (ni se gasta) otra poción
        $this->give(self::POTION);
        self::assertSame('inventory.error_already_full', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 0)));
        self::assertCount(1, $this->names(Container::Inventory));
    }

    public function testOrbTeachesItsSkillOnce(): void
    {
        $this->give(self::ORB_TWISTING);
        $this->give(self::ORB_TWISTING);

        $result = $this->inventory()->use($this->account(), $this->knight(), 0);
        self::assertSame(['name' => 'Twisting Slash'], $result['params']);
        self::assertSame(['22'], $this->knight()->skills);

        self::assertSame('inventory.error_skill_known', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 1)));
        self::assertCount(1, $this->names(Container::Inventory), 'el orbe repetido no se gasta');
    }

    public function testOrbChecksLevelAndClass(): void
    {
        $this->give(self::ORB_HIGH_LEVEL); // pide nivel 80
        self::assertSame('inventory.error_requirement', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 0)));

        $merlin = $this->characters->insert($this->accountId, 'Merlin', CharacterClass::DarkWizard, $this->clock->now());
        $this->items->addToInventory($merlin->id, Item::parse(self::ORB_TWISTING), $this->clock->now());
        self::assertSame('inventory.error_class', $this->errorOf(fn () => $this->inventory()->use($this->account(), $merlin, 0)));
    }

    public function testFruitsGetLessLikelyToWork(): void
    {
        $this->give(self::FRUIT_STR);
        $this->give(self::FRUIT_STR);
        $this->pdo->exec('UPDATE characters SET fruits = 40 WHERE id = ' . $this->knight->id);

        // tirada 41 > 40 frutas comidas → funciona, +3 puntos
        $ok = $this->inventory(new FakeRng([41, 3]))->use($this->account(), $this->knight(), 0);
        self::assertSame(['key' => 'inventory.fruit_ok', 'params' => ['n' => 3, 'stat' => 'strength']], $ok);
        self::assertSame([31, 41], [$this->knight()->strength, $this->knight()->fruits]);

        // tirada 41 ya no alcanza con 41 comidas
        $failed = $this->inventory(new FakeRng([41]))->use($this->account(), $this->knight(), 1);
        self::assertSame('inventory.fruit_failed', $failed['key']);
        self::assertSame(31, $this->knight()->strength);
        self::assertSame([], $this->names(Container::Inventory), 'la fruta se gasta igual');
    }

    public function testSomeItemsCannotBeUsedFromTheInventory(): void
    {
        $this->give(self::KRIS);
        $this->give(self::ANTIDOTE);

        self::assertSame('inventory.error_not_usable', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 0)));
        self::assertSame('inventory.error_only_in_battle', $this->errorOf(fn () => $this->inventory()->use($this->account(), $this->knight(), 1)));
        self::assertCount(2, $this->names(Container::Inventory));
    }

    // --- joyas ---

    public function testBlessAlwaysRaisesOneLevelUpToSix(): void
    {
        $kris = Item::parse(self::KRIS);

        [$upgraded, $outcome] = Jewels::apply(Item::parse(self::BLESS), $kris, new FakeRng());
        self::assertSame([1, 'ok'], [$upgraded?->level, $outcome]);

        $this->expectExceptionObject(new InventoryException('inventory.error_jewel_useless'));
        Jewels::apply(Item::parse(self::BLESS), $kris->with(['level' => 6]), new FakeRng());
    }

    public function testSoulCanFailAndDegrade(): void
    {
        $soul = Item::parse(self::SOUL);
        $kris = Item::parse(self::KRIS);

        // sin luck: 50% (tirada 1 = éxito, 0 = fallo)
        self::assertSame(7, Jewels::apply($soul, $kris->with(['level' => 6]), new FakeRng([1]))[0]?->level);
        self::assertSame([4, 'degraded'], [Jewels::apply($soul, $kris->with(['level' => 5]), new FakeRng([0]))[0]?->level, Jewels::apply($soul, $kris->with(['level' => 5]), new FakeRng([0]))[1]]);
        self::assertSame(0, Jewels::apply($soul, $kris->with(['level' => 8]), new FakeRng([0]))[0]?->level, 'de +7 para arriba vuelve a +0');
        self::assertSame(0, Jewels::apply($soul, $kris, new FakeRng([0]))[0]?->level);

        // con luck: 75% (sólo falla con la tirada 1 de 0..3)
        $lucky = $kris->with(['luck' => 1, 'level' => 3]);
        self::assertSame(4, Jewels::apply($soul, $lucky, new FakeRng([0]))[0]?->level);
        self::assertSame(2, Jewels::apply($soul, $lucky, new FakeRng([1]))[0]?->level);
        self::assertSame(4, Jewels::apply($soul, $lucky, new FakeRng([3]))[0]?->level);
    }

    public function testLifeHarmonyAndRefiningStones(): void
    {
        $kris = Item::parse(self::KRIS);
        $jewel = static fn (string $sub): Item => Item::parse(self::BLESS)->with(['subType' => $sub]);

        self::assertSame(1, Jewels::apply($jewel('3'), $kris, new FakeRng([1]))[0]?->option);
        self::assertSame(1, Jewels::apply($jewel('3'), $kris->with(['option' => 2]), new FakeRng([0]))[0]?->option);

        [$harmonised, $outcome] = Jewels::apply($jewel('4'), $kris, new FakeRng([1, 5, 2]));
        self::assertSame([5, 2, 'ok'], [$harmonised?->harmonyType, $harmonised?->harmonyValue, $outcome]);
        self::assertSame([null, 'nothing'], Jewels::apply($jewel('4'), $kris, new FakeRng([0])));

        self::assertNotNull($harmonised);
        self::assertSame(3, Jewels::apply($jewel('5'), $harmonised, new FakeRng([1]))[0]?->harmonyValue);
        self::assertSame(1, Jewels::apply($jewel('5'), $harmonised, new FakeRng([0]))[0]?->harmonyValue);
        self::assertSame(3, Jewels::apply($jewel('6'), $harmonised, new FakeRng([2]))[0]?->harmonyValue, 'la piedra superior acierta 3 de 4');

        foreach ([[$jewel('5'), $kris], [$jewel('4'), $harmonised], [$jewel('1'), Item::parse(self::POTION)], [$jewel('9'), $kris]] as [$j, $target]) {
            try {
                Jewels::apply($j, $target, new FakeRng());
                self::fail('se esperaba un rechazo');
            } catch (InventoryException $e) {
                self::assertStringStartsWith('inventory.error_jewel', $e->getMessage());
            }
        }
    }

    public function testApplyingAJewelFromTheInventory(): void
    {
        $this->give(self::KRIS, Container::Inventory, 0);
        $this->give(self::BLESS, Container::Inventory, 1);
        $this->give(self::BLESS, Container::Inventory, 2);

        $result = $this->inventory()->applyJewel($this->account(), $this->knight(), 1, 0);

        self::assertSame(['key' => 'inventory.jewel_ok', 'params' => ['name' => 'Kris +1']], $result);
        self::assertSame(1, $this->items->items(Container::Inventory, $this->knight->id)[0]->level);
        self::assertSame([0 => 'kris', 2 => 'jewel_of_bless'], $this->names(Container::Inventory), 'se gastó una sola joya');

        // un rechazo no gasta la joya
        self::assertSame('inventory.error_jewel_target', $this->errorOf(fn () => $this->inventory()->applyJewel($this->account(), $this->knight(), 2, 2)));
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->inventory()->applyJewel($this->account(), $this->knight(), 0, 2)), 'un arma no es una joya');
        self::assertCount(2, $this->names(Container::Inventory));
    }

    // --- tiendas ---

    public function testEveryShopHasValidItemsThatFitWithoutOverlapping(): void
    {
        $root = dirname(__DIR__, 2);
        $catalog = new ShopCatalog($root . '/data/shops');

        foreach (array_keys(ShopCatalog::SHOPS) as $id) {
            $stock = $catalog->stock($id);
            self::assertNotEmpty($stock, $id);
            self::assertFileExists("$root/public/assets/img/shops/$id.gif");

            $placed = [];
            foreach ($stock as $slot => $item) {
                self::assertTrue(Grid::fits($placed, $slot, $item->width, $item->height, ShopCatalog::ROWS), "$id: {$item->name} en $slot");
                $placed[] = ['slot' => $slot, 'width' => $item->width, 'height' => $item->height];
                self::assertFileExists("$root/public/assets/img/items/" . $item->imagePath(), $id);
                self::assertLessThanOrEqual(1, $item->luck, "$id: {$item->name}");
                self::assertLessThanOrEqual(6, $item->excellent, "$id: {$item->name}");
                self::assertGreaterThan(0, $item->buyPrice(), "$id: {$item->name}");
            }
        }
        self::assertSame([], $catalog->stock('gm-event'), 'la tienda de Game Master no está a la venta');
    }

    public function testTableQualitySellsTheItemsAsClassicHadThem(): void
    {
        $catalog = new ShopCatalog(dirname(__DIR__, 2) . '/data/shops', 'table');

        self::assertSame(9, max(array_map(static fn (Item $i): int => $i->luck, $catalog->stock('lorencia2'))));
    }

    public function testBuyingChargesZenAndStoresTheItem(): void
    {
        // lorencia1, celda 1: poción chica de vida (precio 40)
        $item = $this->shop()->buy($this->account(), $this->knight(), 'lorencia1', 1);

        self::assertSame(['small_healing_potion', 40], [$item->name, $item->buyPrice()]);
        self::assertSame(5000 - 40, $this->account()->zen);
        self::assertSame([0 => 'small_healing_potion'], $this->names(Container::Inventory));
    }

    public function testCannotBuyWithoutMoneyOrSpace(): void
    {
        $this->pdo->exec('UPDATE accounts SET zen = 39');
        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $this->shop()->buy($this->account(), $this->knight(), 'lorencia1', 1)));
        self::assertSame(39, $this->account()->zen);

        $this->pdo->exec('UPDATE accounts SET zen = 5000');
        for ($i = 0; $i < 8; $i++) {
            $this->give(self::GREAT_SWORD);
        }
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->shop()->buy($this->account(), $this->knight(), 'lorencia1', 1)));
        self::assertSame(5000, $this->account()->zen, 'sin lugar no se cobra');

        self::assertSame('shop.error_no_item', $this->errorOf(fn () => $this->shop()->buy($this->account(), $this->knight(), 'lorencia1', 25)), 'celda tapada');
        self::assertSame('shop.error_no_item', $this->errorOf(fn () => $this->shop()->buy($this->account(), $this->knight(), 'nowhere', 1)));
    }

    public function testSellingPaysHalfThePrice(): void
    {
        $this->give(self::KRIS, Container::Inventory, 0); // compra 72 → venta 36
        $this->give(self::HELM, Container::Equipment, EquipmentSlot::Helm->value); // (50 + 5*2) / 2 = 30
        $this->give(self::KRIS, Container::Vault, 0);

        self::assertSame(36, $this->shop()->sell($this->account(), $this->knight(), Container::Inventory, 0));
        self::assertSame(30, $this->shop()->sell($this->account(), $this->knight(), Container::Equipment, EquipmentSlot::Helm->value));
        self::assertSame(5066, $this->account()->zen);
        self::assertSame([], $this->names(Container::Inventory) + $this->names(Container::Equipment));

        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->shop()->sell($this->account(), $this->knight(), Container::Inventory, 0)), 'no se vende dos veces');
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->shop()->sell($this->account(), $this->knight(), Container::Vault, 0)), 'del baúl no se vende');
        self::assertSame(5066, $this->account()->zen);
    }
}
