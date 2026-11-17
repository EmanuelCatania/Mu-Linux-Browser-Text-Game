<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\GoldenRepository;
use Mu\Data\ItemRepository;
use Mu\Data\PresenceRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleException;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\Item;
use Mu\Domain\Npc\BoxOpener;
use Mu\Domain\Npc\ChaosMachine;
use Mu\Domain\Npc\NpcService;
use Mu\Domain\Npc\SpecialNpcs;
use Mu\Domain\Quest\QuestCatalog;
use Mu\Domain\Quest\QuestService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\MapCatalog;
use Mu\Support\Rng;
use Mu\Support\Translator;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Chaos Machine, NPC especiales, cajas, misiones y Kalima. */
final class NpcTest extends TestCase
{
    private const string CHAOS = 'E.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_chaos';
    private const string CREATION = 'E.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_creation';
    private const string LOCH = 'E.12.7.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.loch_feather';
    private const string BLESS = 'D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless';
    private const string SOUL = 'D.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul';
    private const string BLESS_PACK = 'E.11.4.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless_x_10';
    private const string UNIRIA = 'C.11.3.0000.00.0.0000.0000.00.0.0.00.00.0.255.255.000000.horn_of_uniria';
    private const string KRIS = '1.12.1.0011.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.kris';
    private const string SYMBOL = 'I.11.A.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.symbol_of_kundun';
    private const string BRANCH = 'I.12.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.golden_cherry_blossom_branch';
    private const string BOX_OF_LUCK = 'H.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_luck';
    private const string LOST_MAP = 'K.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map';

    private string $root;
    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private ItemRepository $items;
    private BattleRepository $battles;
    private int $accountId;
    private int $characterId;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->items = new ItemRepository($this->pdo);
        $this->battles = new BattleRepository($this->pdo);
        $this->accountId = $this->accounts->insert('Tester', 't@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now())->id;
        $this->characterId = $this->characters->insert($this->accountId, 'Merlin', CharacterClass::DarkWizard, $this->clock->now())->id;
    }

    private function account(): Account
    {
        return $this->accounts->findById($this->accountId) ?? self::fail('cuenta inexistente');
    }

    private function character(): Character
    {
        return $this->characters->findById($this->characterId) ?? self::fail('personaje inexistente');
    }

    private static function item(string $code, int $level = 0, int $option = 0, int $excellent = 0, int $luck = 0): Item
    {
        return Item::parse($code)->with(['level' => $level, 'option' => $option, 'excellent' => $excellent, 'luck' => $luck]);
    }

    /** @return list<Item> */
    private static function many(string $code, int $times, int $level = 0): array
    {
        return array_fill(0, $times, self::item($code, $level));
    }

    private function npcs(?Rng $rng = null): NpcService
    {
        return new NpcService(
            new SpecialNpcs(new BoxOpener($this->root . '/data/drop')),
            $this->items,
            $this->accounts,
            $this->battles,
            new Transaction($this->pdo),
            $this->clock,
            $rng ?? new FakeRng(),
        );
    }

    private function quests(): QuestService
    {
        return new QuestService(
            new QuestCatalog($this->root . '/data/quests.xml'),
            $this->characters,
            $this->items,
            $this->battles,
            new StatsCalculator(),
            new Transaction($this->pdo),
            $this->clock,
        );
    }

    private function battleService(Rng $rng): BattleService
    {
        $config = require $this->root . '/data/game.php';

        return new BattleService(
            new BattleEngine(new SkillCatalog($this->root . '/data/skills.php'), new DropGenerator($this->root . '/data/drop', $config['drop']), $config),
            new StatsCalculator(),
            new MapCatalog($this->root . '/data/maps'),
            new GoldenInvasion(new GoldenRepository($this->pdo), $config['golden']['hours']),
            $this->battles,
            $this->characters,
            $this->accounts,
            $this->items,
            new PresenceRepository($this->pdo),
            $this->clock,
            $rng,
            $config,
            new QuestCatalog($this->root . '/data/quests.xml'),
        );
    }

    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (InventoryException | BattleException $e) {
            return $e->getMessage();
        }

        self::fail('Se esperaba un error');
    }

    /** @return list<string> nombres de lo que hay en la mesa */
    private function table(): array
    {
        return array_values(array_map(static fn (Item $i): string => $i->name, $this->items->items(Container::Mix, $this->accountId)));
    }

    // --- Chaos Machine ---

    public function testFruitRecipe(): void
    {
        $items = [self::item(self::CREATION), self::item(self::CHAOS)];

        // tirada de fruta 2 → stamina; tirada de fallo 0 → sale
        $ok = ChaosMachine::mix($items, new FakeRng([2, 0]));
        self::assertTrue($ok->success);
        self::assertSame(['stamina_fruit', '3'], [$ok->items[0]->name, $ok->items[0]->subType]);

        // tirada de fallo 1 → se pierde todo
        $failed = ChaosMachine::mix($items, new FakeRng([2, 1]));
        self::assertFalse($failed->success);
        self::assertSame([], $failed->items);
    }

    public function testDinorantNeedsExactlyTenUniria(): void
    {
        $ten = [...self::many(self::UNIRIA, 10), self::item(self::CHAOS)];
        self::assertSame('horn_of_dinorant', ChaosMachine::mix($ten, new FakeRng([0]))->items[0]->name);

        $this->expectExceptionObject(new InventoryException('npc.error_recipe'));
        ChaosMachine::mix([...self::many(self::UNIRIA, 9), self::item(self::CHAOS)], new FakeRng());
    }

    public function testUpgradingFromPlusNineToThirteen(): void
    {
        $recipe = static fn (int $level, int $luck = 0): array => [
            self::item(self::KRIS, $level, luck: $luck),
            self::item(self::CHAOS),
            ...self::many(self::BLESS, $level - 8),
            ...self::many(self::SOUL, $level - 8),
        ];

        // sin luck: tirada 0 de (0..1) = éxito
        self::assertSame(10, ChaosMachine::mix($recipe(9), new FakeRng([0]))->items[0]->level);
        self::assertSame(13, ChaosMachine::mix($recipe(12), new FakeRng([0]))->items[0]->level);

        // la tirada 1 falla y el ítem vuelve a +0, pero no se pierde
        $failed = ChaosMachine::mix($recipe(11), new FakeRng([1]));
        self::assertFalse($failed->success);
        self::assertSame(['kris', 0], [$failed->items[0]->name, $failed->items[0]->level]);

        // con luck sólo falla 1 de 4
        self::assertSame(10, ChaosMachine::mix($recipe(9, 1), new FakeRng([3]))->items[0]->level);

        // +13 ya no sube, y con joyas de menos tampoco
        foreach ([$recipe(13), [self::item(self::KRIS, 10), self::item(self::CHAOS), self::item(self::BLESS), self::item(self::SOUL)]] as $bad) {
            try {
                ChaosMachine::mix($bad, new FakeRng());
                self::fail('se esperaba un rechazo');
            } catch (InventoryException $e) {
                self::assertSame('npc.error_recipe', $e->getMessage());
            }
        }
    }

    public function testChaosWeaponRateDependsOnTheIngredients(): void
    {
        // kris +6 con opción: aporta floor(11/10*6) = 6; una Chaos +2 → 8% de éxito
        $items = [self::item(self::KRIS, 6, 1), self::item(self::CHAOS)];

        // arma 1 (hacha), nivel: 0 → +1; opción: 0 → 1; luck 1; tirada final 8 → éxito
        $ok = ChaosMachine::mix($items, new FakeRng([1, 0, 0, 1, 8]));
        self::assertTrue($ok->success);
        self::assertSame(['chaos_dragon_axe', 1, 1, 1], [$ok->items[0]->name, $ok->items[0]->level, $ok->items[0]->option, $ok->items[0]->luck]);

        // tirada 9 > 8 → falla y devuelve el primer ítem en +0
        $failed = ChaosMachine::mix($items, new FakeRng([1, 0, 0, 1, 9]));
        self::assertFalse($failed->success);
        self::assertSame(['kris', 0], [$failed->items[0]->name, $failed->items[0]->level]);

        // con 5 Bless y 5 Soul: 6 + 20 + 15 + 2 = 43%
        $boosted = [...$items, ...self::many(self::BLESS, 5), ...self::many(self::SOUL, 5)];
        self::assertTrue(ChaosMachine::mix($boosted, new FakeRng([1, 0, 0, 1, 43]))->success);
        self::assertFalse(ChaosMachine::mix($boosted, new FakeRng([1, 0, 0, 1, 44]))->success);
    }

    public function testWingsFromChaosWeaponAndSecondLevelWings(): void
    {
        $axe = Item::parse('2.24.2.0130.06.0.0000.0000.15.0.1.00.00.0.080.080.000000.chaos_dragon_axe');
        // alas 3 (fairy), opción 2, luck 0, tirada final 1
        $first = ChaosMachine::mix([$axe, self::item(self::CHAOS)], new FakeRng([3, 2, 0, 1]));
        self::assertSame(['fairy_wings', 2, 0], [$first->items[0]->name, $first->items[0]->option, $first->items[0]->luck]);

        // de 1.º a 2.º nivel: alas +0 → 10*(0+1) + 2 = 12% de éxito
        $recipe = [$first->items[0], self::item(self::LOCH), self::item(self::CHAOS)];
        // alas 2 (archangel), opción 1, luck 1, opción propia: sí (1) → tipo 3, tirada final 12
        $second = ChaosMachine::mix($recipe, new FakeRng([2, 1, 1, 1, 3, 12]));
        self::assertTrue($second->success);
        self::assertSame(['archangel_wings', '3'], [$second->items[0]->name, $second->items[0]->skill]);

        $failed = ChaosMachine::mix($recipe, new FakeRng([2, 1, 1, 2, 13]));
        self::assertFalse($failed->success);
        self::assertSame('fairy_wings', $failed->items[0]->name, 'devuelve las alas originales');
    }

    public function testUnknownCombinationsAreRejected(): void
    {
        $cases = [
            'una sola joya' => [self::item(self::CHAOS)],
            'ítem que no sirve' => [self::item(self::KRIS), self::item(self::CHAOS)],
            'ítem +5 sin opción' => [self::item(self::KRIS, 5), self::item(self::CHAOS)],
            'sin Chaos' => [self::item(self::KRIS, 6, 1), self::item(self::BLESS)],
            'nada' => [],
        ];
        foreach ($cases as $label => $items) {
            self::assertSame('npc.error_recipe', $this->errorOf(fn () => ChaosMachine::mix($items, new FakeRng())), $label);
        }
    }

    public function testEveryChaosMachineResultHasAnImage(): void
    {
        $constants = new \ReflectionClass(ChaosMachine::class)->getConstants();
        $codes = [];
        array_walk_recursive($constants, static function (mixed $value) use (&$codes): void {
            if (is_string($value) && str_contains($value, '.')) {
                $codes[] = $value;
            }
        });

        self::assertGreaterThan(10, count($codes));
        foreach ($codes as $code) {
            self::assertFileExists($this->root . '/public/assets/img/items/' . Item::parse($code)->imagePath());
        }
    }

    // --- NPC especiales ---

    public function testLahapPacksAndUnpacksJewels(): void
    {
        $npcs = new SpecialNpcs(new BoxOpener($this->root . '/data/drop'));

        self::assertSame('jewel_of_bless_x_10', $npcs->lahap(self::many(self::BLESS, 10), new FakeRng())->items[0]->name);
        self::assertSame('jewel_of_soul_x_10', $npcs->lahap(self::many(self::SOUL, 10), new FakeRng())->items[0]->name);

        $unpacked = $npcs->lahap([self::item(self::BLESS_PACK)], new FakeRng())->items;
        self::assertCount(10, $unpacked);
        self::assertSame(['jewel_of_bless'], array_values(array_unique(array_map(static fn (Item $i) => $i->name, $unpacked))));

        $mixed = [...self::many(self::BLESS, 9), self::item(self::SOUL)];
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->lahap($mixed, new FakeRng())));
    }

    public function testLahapMakesLostMapsFromFiveSymbolsOfTheSameLevel(): void
    {
        $npcs = new SpecialNpcs(new BoxOpener($this->root . '/data/drop'));

        $map = $npcs->lahap(self::many(self::SYMBOL, 5, 3), new FakeRng())->items[0];
        self::assertSame(['lost_map', 3], [$map->name, $map->level]);

        $mismatched = [...self::many(self::SYMBOL, 4, 3), self::item(self::SYMBOL, 2)];
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->lahap($mismatched, new FakeRng())));
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->lahap(self::many(self::SYMBOL, 5, 0), new FakeRng())), 'no hay Kalima 0');
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->lahap(self::many(self::SYMBOL, 5, 8), new FakeRng())), 'ni Kalima 8');
    }

    public function testBoxesGiveAnItemNineTimesOutOfTen(): void
    {
        $npcs = new SpecialNpcs(new BoxOpener($this->root . '/data/drop'));
        $box = [self::item(self::BOX_OF_LUCK)];

        // todo al máximo: la última tirada (0..9) da 9 → no viene vacía
        $prize = $npcs->lahap($box, new FakeRng([], 'max'));
        self::assertTrue($prize->success);
        self::assertCount(1, $prize->items);

        // todo al mínimo: la tirada 0 → vacía
        self::assertSame([], $npcs->lahap($box, new FakeRng([], 'min'))->items);

        self::assertNull(BoxOpener::tableFor(self::item(self::KRIS)));
        self::assertSame('box_of_kundun2', BoxOpener::tableFor(Item::parse('H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun')));
        self::assertNull(BoxOpener::tableFor(Item::parse('H.11.2.0000.05.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun')));
    }

    public function testEveryBoxTableOpensToValidItems(): void
    {
        $opener = new BoxOpener($this->root . '/data/drop');
        foreach (['cherry_blossom_box', 'box_of_luck', 'box_of_kundun1', 'box_of_kundun2'] as $table) {
            foreach (['min', 'max'] as $mode) {
                $item = $opener->open($table, new FakeRng([], $mode));
                self::assertSame($item->toCode(), Item::parse($item->toCode())->toCode(), $table);
                self::assertFileExists($this->root . '/public/assets/img/items/' . $item->imagePath(), $table);
                self::assertLessThanOrEqual(6, $item->excellent, "$table: opción excellent válida");
                if ($item->isExcellent()) {
                    self::assertSame(0, $item->level, "$table: los excellent salen +0");
                }
            }
        }
    }

    public function testCherryBlossomOsbourneAndJerridon(): void
    {
        $npcs = new SpecialNpcs(new BoxOpener($this->root . '/data/drop'));

        self::assertSame('jewel_of_chaos', $npcs->cherryBlossom(self::many(self::BRANCH, 5))->items[0]->name);
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->cherryBlossom(self::many(self::BRANCH, 4))));

        // Osbourne: excellent → Higher (50%); común +4 con opción → Lower (20%); el ítem se pierde igual
        $higher = $npcs->osbourne([self::item(self::KRIS, excellent: 2)], new FakeRng([1]));
        self::assertSame(['higher_refining_stone', '6'], [$higher->items[0]->name, $higher->items[0]->subType]);
        self::assertSame([], $npcs->osbourne([self::item(self::KRIS, excellent: 2)], new FakeRng([0]))->items);
        self::assertSame('lower_refining_stone', $npcs->osbourne([self::item(self::KRIS, 4, 1)], new FakeRng([1]))->items[0]->name);
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->osbourne([self::item(self::KRIS, 3, 1)], new FakeRng())));
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->osbourne([self::item(self::BLESS)], new FakeRng())));

        // Jerridon
        $harmonised = self::item(self::KRIS)->with(['harmonyType' => 2, 'harmonyValue' => 3]);
        $clean = $npcs->jerridon([$harmonised])->items[0];
        self::assertSame([0, 0, 'kris'], [$clean->harmonyType, $clean->harmonyValue, $clean->name]);
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $npcs->jerridon([self::item(self::KRIS)])));
    }

    // --- el servicio: mesa, zen y transacción ---

    public function testMixingChargesZenAndLeavesTheResultOnTheTable(): void
    {
        foreach (self::many(self::BLESS, 10) as $jewel) {
            $this->items->addFirstFree(Container::Mix, $this->accountId, $jewel, $this->clock->now());
        }

        $result = $this->npcs()->mix($this->account(), $this->character(), 'lahap');

        self::assertTrue($result->success);
        self::assertSame(['jewel_of_bless_x_10'], $this->table());
        self::assertSame(5000 - NpcService::PRICE, $this->account()->zen);

        // y de vuelta: un paquete → 10 joyas sobre la mesa
        $this->npcs()->mix($this->account(), $this->character(), 'lahap');
        self::assertCount(10, $this->table());
        self::assertSame(3000, $this->account()->zen);
    }

    public function testAFailedMixStillCostsAndConsumesTheIngredients(): void
    {
        $this->items->addFirstFree(Container::Mix, $this->accountId, self::item(self::CREATION), $this->clock->now());
        $this->items->addFirstFree(Container::Mix, $this->accountId, self::item(self::CHAOS), $this->clock->now());

        $result = $this->npcs(new FakeRng([0, 1]))->mix($this->account(), $this->character(), 'chaos_machine');

        self::assertFalse($result->success);
        self::assertSame([], $this->table());
        self::assertSame(4000, $this->account()->zen);
    }

    public function testInvalidMixesChangeNothing(): void
    {
        self::assertSame('npc.error_empty', $this->errorOf(fn () => $this->npcs()->mix($this->account(), $this->character(), 'lahap')));

        $this->items->addFirstFree(Container::Mix, $this->accountId, self::item(self::KRIS), $this->clock->now());
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $this->npcs()->mix($this->account(), $this->character(), 'chaos_machine')));
        self::assertSame('npc.error_recipe', $this->errorOf(fn () => $this->npcs()->mix($this->account(), $this->character(), 'nobody')));
        self::assertSame(['kris'], $this->table());
        self::assertSame(5000, $this->account()->zen);

        // sin zen: tampoco se pierde nada
        $this->pdo->exec('UPDATE accounts SET zen = 999');
        $this->items->delete($this->items->find(Container::Mix, $this->accountId, 0)?->id ?? 0);
        foreach (self::many(self::BLESS, 10) as $jewel) {
            $this->items->addFirstFree(Container::Mix, $this->accountId, $jewel, $this->clock->now());
        }
        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $this->npcs()->mix($this->account(), $this->character(), 'lahap')));
        self::assertCount(10, $this->table());
        self::assertSame(999, $this->account()->zen);
    }

    // --- misiones ---

    public function testQuestDataIsConsistent(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');
        $monsters = [];
        foreach ($maps->all() as $map) {
            $monsters += $map->monsters;
        }
        $translator = new Translator($this->root . '/lang');
        $quests = new QuestCatalog($this->root . '/data/quests.xml')->all();

        self::assertCount(19, $quests);
        foreach ($quests as $quest) {
            self::assertArrayHasKey($quest->target, $monsters, "misión {$quest->id}: monstruo inexistente");
            self::assertFileExists($this->root . "/public/assets/img/monsters/{$quest->target}.gif");
            self::assertGreaterThan(0, $quest->amount);
            self::assertLessThanOrEqual($quest->levelMax, $quest->levelMin);
            foreach (array_keys(Translator::LOCALES) as $locale) {
                foreach (['title', 'text'] as $part) {
                    $key = "quest.{$quest->id}.$part";
                    self::assertNotSame($key, $translator->translate($key, $locale), "$locale: falta $key");
                }
            }
        }
    }

    public function testQuestsAreOfferedByLevel(): void
    {
        self::assertSame([1], array_map(static fn ($q) => $q->id, $this->quests()->available($this->character())));

        $this->characters->saveProgress($this->characterId, 85, 0, 0, 60, 60, 0);
        self::assertSame([8, 31], array_map(static fn ($q) => $q->id, $this->quests()->available($this->character())));

        self::assertSame('quest.error_unavailable', $this->errorOf(fn () => $this->quests()->accept($this->account(), $this->character(), 1)), 'fuera de su nivel');
        self::assertSame('quest.error_unavailable', $this->errorOf(fn () => $this->quests()->accept($this->account(), $this->character(), 999)));
    }

    public function testQuestProgressComesFromBattleKillsAndRewardsALevel(): void
    {
        $this->quests()->accept($this->account(), $this->character(), 1); // 10 Spider
        self::assertSame('quest.error_unfinished', $this->errorOf(fn () => $this->quests()->claim($this->account(), $this->character())));

        // una ronda con una araña: cuenta 1 al cerrar la ronda
        $service = $this->battleService(new FakeRng([1], 'max'));
        $service->enter($this->character(), 'lorencia', 'spot1');
        $service->act($this->character(), '30', 0);
        self::assertSame(0, $this->character()->questScore, 'todavía no se guardó');
        $service->leave($this->character());
        self::assertSame(1, $this->character()->questScore);

        $this->characters->addQuestScore($this->characterId, 9);
        $level = $this->character()->level;

        self::assertSame('level', $this->quests()->claim($this->account(), $this->character()));

        $after = $this->character();
        self::assertSame([$level + 1, 5, Battle::experienceFor($level), null, 0], [$after->level, $after->points, $after->experience, $after->questId, $after->questScore]);
        self::assertSame([1], $this->characters->questsDone($this->characterId));
        self::assertSame('quest.error_none', $this->errorOf(fn () => $this->quests()->claim($this->account(), $this->character())), 'no se cobra dos veces');
        self::assertNotContains(1, array_map(static fn ($q) => $q->id, $this->quests()->available($this->character())), 'ni se repite');
    }

    public function testItemRewardNeedsRoomAndGivingUpResetsProgress(): void
    {
        $this->characters->saveProgress($this->characterId, 85, 0, 0, 60, 60, 0);
        $this->quests()->accept($this->account(), $this->character(), 31);
        $this->characters->addQuestScore($this->characterId, 50);

        $big = Item::parse('2.24.1.0050.00.0.0000.0000.00.0.0.00.00.0.050.050.000000.great_sword');
        for ($i = 0; $i < 8; $i++) {
            $this->items->addToInventory($this->characterId, $big, $this->clock->now());
        }
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->quests()->claim($this->account(), $this->character())));
        self::assertSame(31, $this->character()->questId, 'sigue pendiente de cobro');

        $this->items->delete($this->items->find(Container::Inventory, $this->characterId, 0)?->id ?? 0);
        self::assertSame('item', $this->quests()->claim($this->account(), $this->character()));
        self::assertContains('jewel_of_chaos', array_map(static fn (Item $i) => $i->name, $this->items->items(Container::Inventory, $this->characterId)));

        // abandonar otra misión borra el progreso
        $this->quests()->accept($this->account(), $this->character(), 8);
        $this->characters->addQuestScore($this->characterId, 20);
        $this->quests()->giveUp($this->account(), $this->character());
        self::assertSame([null, 0], [$this->character()->questId, $this->character()->questScore]);
    }

    public function testSoldierRestoresHpAndMpButNotDuringABattle(): void
    {
        $this->characters->setVitals($this->characterId, 1, 0);

        $this->quests()->restore($this->account(), $this->character());
        self::assertSame([61, 62], [$this->character()->hpCur, $this->character()->mpCur]);

        $this->battles->create($this->characterId, new Battle(), $this->clock->now());
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->quests()->restore($this->account(), $this->character())));
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->quests()->accept($this->account(), $this->character(), 1)));
    }

    // --- Kalima ---

    public function testLostMapOpensKalimaAndTheEventRunsDownToTheBoss(): void
    {
        $inventory = new InventoryService(
            $this->items,
            $this->characters,
            $this->battles,
            new StatsCalculator(),
            new SkillCatalog($this->root . '/data/skills.php'),
            new Transaction($this->pdo),
            new FakeRng(),
        );
        $this->items->addToInventory($this->characterId, self::item(self::LOST_MAP, 2), $this->clock->now());
        self::assertSame('battle.error_no_event', $this->errorOf(fn () => $this->battleService(new FakeRng())->enterEvent($this->character())));

        $used = $inventory->use($this->account(), $this->character(), 0);
        self::assertSame(['key' => 'inventory.used_lost_map', 'params' => ['n' => 2]], $used);
        self::assertSame('kalima_2', $this->character()->eventMap);

        // a Kalima no se entra como a un mapa común
        self::assertSame('battle.error_spot', $this->errorOf(fn () => $this->battleService(new FakeRng())->enter($this->character(), 'kalima_2', 'spot14')));

        // todas las tiradas al máximo: la cuenta baja en cada ronda
        $service = $this->battleService(new FakeRng([], 'max'));
        $battle = $service->enterEvent($this->character());
        self::assertTrue($battle->event);
        self::assertSame(['kalima_2', 'spot14', 13], [$battle->map, $battle->spot, $battle->eventCountdown]);
        self::assertNull($this->character()->eventMap, 'la entrada se gastó');
        self::assertSame('battle.error_no_event', $this->errorOf(fn () => $service->enterEvent($this->character())));

        // Se salta el combate: se dan por muertos los enemigos de cada ronda hasta llegar al jefe.
        $spots = [$battle->spot];
        for ($round = 0; $round < 13; $round++) {
            [$current, $version] = $this->battles->find($this->characterId) ?? self::fail('sin batalla');
            foreach (array_keys($current->enemies) as $i) {
                $current->enemies[$i]['dead'] = true;
            }
            $this->battles->save($this->characterId, $current, $version, $this->clock->now());
            $spots[] = $service->nextRound($this->character())->spot;
        }
        self::assertSame(array_map(static fn (int $n): string => "spot$n", range(14, 1)), $spots);

        [$last, $version] = $this->battles->find($this->characterId) ?? self::fail('sin batalla');
        self::assertSame(0, $last->eventCountdown);
        self::assertCount(1, $last->enemies, 'el jefe pelea solo');
        self::assertSame('boss', $last->enemies[0]['kind']);

        $last->enemies[0]['dead'] = true;
        $this->battles->save($this->characterId, $last, $version, $this->clock->now());
        self::assertSame('battle.event_over', $this->errorOf(fn () => $service->nextRound($this->character())));
        self::assertNull($service->current($this->character()));
    }

    public function testOnlyLostMapsOfExistingKalimaLevelsCanBeUsed(): void
    {
        $inventory = new InventoryService(
            $this->items,
            $this->characters,
            $this->battles,
            new StatsCalculator(),
            new SkillCatalog($this->root . '/data/skills.php'),
            new Transaction($this->pdo),
            new FakeRng(),
        );
        $this->items->addToInventory($this->characterId, self::item(self::LOST_MAP, 9), $this->clock->now());
        $this->items->addToInventory($this->characterId, Item::parse('K.22.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.cloak_of_invisibility'), $this->clock->now());

        self::assertSame('inventory.error_not_usable', $this->errorOf(fn () => $inventory->use($this->account(), $this->character(), 0)));
        self::assertNull($this->character()->eventMap);
        self::assertCount(2, $this->items->items(Container::Inventory, $this->characterId));

        // la Cloak of Invisibility da entrada al Blood Castle del nivel del personaje
        $used = $inventory->use($this->account(), $this->character(), 1);
        self::assertSame('inventory.used_ticket', $used['key']);
        self::assertSame(\Mu\Domain\World\ScheduledEvents::mapFor('blood_castle', $this->character()->level), $this->character()->eventMap);
    }

    public function testEveryKalimaMapHasItsFourteenSpots(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');
        foreach (MapCatalog::EVENT_MAPS as $id) {
            $map = $maps->get($id);
            self::assertNotNull($map, $id);
            for ($n = 1; $n <= BattleService::EVENT_SPOTS; $n++) {
                $spot = $map->spot("spot$n");
                self::assertNotNull($spot, "$id spot$n");
                foreach ($spot->encounters as $name) {
                    self::assertFileExists($this->root . "/public/assets/img/monsters/$name.gif");
                }
            }
        }
        self::assertArrayNotHasKey('kalima_1', $maps->all(), 'los mapas de evento no están en el continente');
    }
}
