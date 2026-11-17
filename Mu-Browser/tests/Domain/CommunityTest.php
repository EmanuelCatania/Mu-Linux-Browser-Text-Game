<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\GoldenRepository;
use Mu\Data\GuildRepository;
use Mu\Data\ItemRepository;
use Mu\Data\PresenceRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Chat\ChatService;
use Mu\Domain\Guild\GuildService;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\Item;
use Mu\Domain\Market\StoreService;
use Mu\Domain\Quest\QuestCatalog;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\MapCatalog;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Tienda personal, mercado, clanes, chat, ranking y conectados. */
final class CommunityTest extends TestCase
{
    private const string KRIS = '1.12.1.0011.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.kris';
    private const string STAFF = '1.13.3.0020.00.0.0000.0000.02.0.0.01.00.0.030.030.000000.skull_staff'; // de Dark Wizard, excellent
    private const string HELM = '5.22.0.0005.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.bronze_helm';
    private const string BLESS = 'D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless';
    private const string BIG = '2.24.1.0050.00.0.0000.0000.00.0.0.00.00.0.050.050.000000.great_sword';

    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private ItemRepository $items;
    private BattleRepository $battles;
    /** @var array<string, array{Account, Character}> */
    private array $players = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->items = new ItemRepository($this->pdo);
        $this->battles = new BattleRepository($this->pdo);
        foreach (['Arthur' => CharacterClass::DarkKnight, 'Merlin' => CharacterClass::DarkWizard, 'Robin' => CharacterClass::FairyElf] as $name => $class) {
            $account = $this->accounts->insert("u$name", "$name@example.test", 'hash', 'es', '10.0.0.1', $this->clock->now());
            $this->players[$name] = [$account, $this->characters->insert($account->id, $name, $class, $this->clock->now())];
        }
    }

    private function account(string $name): Account
    {
        return $this->accounts->findById($this->players[$name][0]->id) ?? self::fail('cuenta inexistente');
    }

    private function character(string $name): Character
    {
        return $this->characters->findById($this->players[$name][1]->id) ?? self::fail('personaje inexistente');
    }

    private function store(): StoreService
    {
        return new StoreService($this->items, $this->accounts, $this->battles, new Transaction($this->pdo));
    }

    private function guilds(): GuildService
    {
        return new GuildService(new GuildRepository($this->pdo), $this->characters, $this->accounts, new Transaction($this->pdo), $this->clock);
    }

    private function give(string $name, string $code): int
    {
        return $this->items->addToInventory($this->players[$name][1]->id, Item::parse($code), $this->clock->now()) ?? self::fail('sin lugar');
    }

    /** Pone un ítem del inventario en la tienda y devuelve el id de esa fila. */
    private function offer(string $seller, string $code, int $price): int
    {
        $slot = $this->give($seller, $code);
        $this->store()->add($this->account($seller), $this->character($seller), Container::Inventory, $slot, $price);
        $ids = array_map(static fn ($s) => $s->id, $this->items->stored(Container::Store, $this->account($seller)->id));

        return max($ids);
    }

    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (InventoryException $e) {
            return $e->getMessage();
        }

        self::fail('Se esperaba un error');
    }

    /** @return list<string> */
    private function names(Container $container, string $player): array
    {
        $owner = $container->ownedByAccount() ? $this->account($player)->id : $this->character($player)->id;

        return array_values(array_map(static fn (Item $i): string => $i->name, $this->items->items($container, $owner)));
    }

    // --- tienda personal ---

    public function testItemsGoIntoTheStoreWithAPriceAndComeBack(): void
    {
        $this->offer('Arthur', self::KRIS, 500);

        self::assertSame(['kris'], $this->names(Container::Store, 'Arthur'));
        self::assertSame([], $this->names(Container::Inventory, 'Arthur'));
        self::assertSame(500, $this->items->find(Container::Store, $this->account('Arthur')->id, 0)?->price);

        // dentro de la tienda se puede acomodar, sin pisar otro ítem ni salirse de la grilla
        $this->store()->move($this->account('Arthur'), $this->character('Arthur'), 0, 5);
        self::assertNotNull($this->items->find(Container::Store, $this->account('Arthur')->id, 5));
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->store()->move($this->account('Arthur'), $this->character('Arthur'), 5, 9999)));
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->store()->move($this->account('Arthur'), $this->character('Arthur'), 0, 3)));
        $this->store()->move($this->account('Arthur'), $this->character('Arthur'), 5, 0);
        $this->store()->remove($this->account('Arthur'), $this->character('Arthur'), 0);
        self::assertSame(['kris'], $this->names(Container::Inventory, 'Arthur'));
        self::assertNull($this->items->find(Container::Inventory, $this->character('Arthur')->id, 0)?->price, 'fuera de la tienda no tiene precio');
    }

    public function testStoreRejectsBadPricesAndTheGenericMove(): void
    {
        $slot = $this->give('Arthur', self::KRIS);
        $add = fn (int $price) => $this->store()->add($this->account('Arthur'), $this->character('Arthur'), Container::Inventory, $slot, $price);

        foreach ([0, -5, StoreService::MAX_PRICE + 1] as $price) {
            self::assertSame('store.error_price', $this->errorOf(fn () => $add($price)));
        }
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $this->store()->add($this->account('Arthur'), $this->character('Arthur'), Container::Vault, 0, 10)));

        // el movimiento común de inventario no puede meter ni sacar cosas de la tienda (se saltearía el precio)
        $inventory = new InventoryService(
            $this->items,
            $this->characters,
            $this->battles,
            new StatsCalculator(),
            new SkillCatalog(dirname(__DIR__, 2) . '/data/skills.php'),
            new Transaction($this->pdo),
            new FakeRng(),
        );
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Inventory, $slot, Container::Store, 0)));
        $add(100);
        self::assertSame('inventory.error_no_item', $this->errorOf(fn () => $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Store, 0, Container::Inventory, 0)));
    }

    public function testOpeningCostsZenAndLocksTheStore(): void
    {
        self::assertSame('store.error_empty', $this->errorOf(fn () => $this->store()->open($this->account('Arthur'))));
        $this->offer('Arthur', self::KRIS, 500);
        self::assertSame([], $this->store()->listings(), 'cerrada, no aparece en el mercado');

        $this->store()->open($this->account('Arthur'));

        self::assertTrue($this->account('Arthur')->storeOpen);
        self::assertSame(5000 - StoreService::OPEN_PRICE, $this->account('Arthur')->zen);
        self::assertCount(1, $this->store()->listings());
        self::assertSame('store.error_already_open', $this->errorOf(fn () => $this->store()->open($this->account('Arthur'))));

        $slot = $this->give('Arthur', self::HELM);
        self::assertSame('store.error_open', $this->errorOf(fn () => $this->store()->add($this->account('Arthur'), $this->character('Arthur'), Container::Inventory, $slot, 10)));
        self::assertSame('store.error_open', $this->errorOf(fn () => $this->store()->remove($this->account('Arthur'), $this->character('Arthur'), 0)));

        $this->store()->close($this->account('Arthur'));
        self::assertSame([], $this->store()->listings());
        $this->store()->remove($this->account('Arthur'), $this->character('Arthur'), 0);
        self::assertSame([], $this->names(Container::Store, 'Arthur'));
    }

    public function testCannotOpenWithoutZen(): void
    {
        $this->offer('Arthur', self::KRIS, 500);
        $this->pdo->exec('UPDATE accounts SET zen = 999');

        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $this->store()->open($this->account('Arthur'))));
        self::assertFalse($this->account('Arthur')->storeOpen);
    }

    // --- mercado ---

    public function testBuyingMovesTheItemAndTheZen(): void
    {
        $kris = $this->offer('Arthur', self::KRIS, 700);
        $helm = $this->offer('Arthur', self::HELM, 300);
        $this->store()->open($this->account('Arthur'));

        $bought = $this->store()->buy($this->account('Merlin'), $this->character('Merlin'), $kris);

        self::assertSame('kris', $bought->name);
        self::assertSame(['kris'], $this->names(Container::Inventory, 'Merlin'));
        self::assertSame(4300, $this->account('Merlin')->zen);
        self::assertSame(5000 - 1000 + 700, $this->account('Arthur')->zen);
        self::assertTrue($this->account('Arthur')->storeOpen, 'queda otro ítem: sigue abierta');
        self::assertSame('market.error_gone', $this->errorOf(fn () => $this->store()->buy($this->account('Robin'), $this->character('Robin'), $kris)), 'no se vende dos veces');

        $this->store()->buy($this->account('Robin'), $this->character('Robin'), $helm);
        self::assertFalse($this->account('Arthur')->storeOpen, 'vendió todo: se cierra sola');
        self::assertSame(5000, $this->account('Arthur')->zen);
    }

    public function testBuyingRules(): void
    {
        $kris = $this->offer('Arthur', self::KRIS, 6000);
        $big = $this->offer('Arthur', self::BIG, 100);

        self::assertSame('market.error_gone', $this->errorOf(fn () => $this->store()->buy($this->account('Merlin'), $this->character('Merlin'), $big)), 'tienda cerrada');
        $this->store()->open($this->account('Arthur'));

        self::assertSame('market.error_own', $this->errorOf(fn () => $this->store()->buy($this->account('Arthur'), $this->character('Arthur'), $kris)));
        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $this->store()->buy($this->account('Merlin'), $this->character('Merlin'), $kris)));
        self::assertSame('market.error_gone', $this->errorOf(fn () => $this->store()->buy($this->account('Merlin'), $this->character('Merlin'), 999999)));

        for ($i = 0; $i < 8; $i++) {
            $this->give('Merlin', self::BIG);
        }
        self::assertSame('inventory.error_no_space', $this->errorOf(fn () => $this->store()->buy($this->account('Merlin'), $this->character('Merlin'), $big)));
        self::assertSame(5000, $this->account('Merlin')->zen, 'sin lugar no se cobra');
        self::assertSame(4000, $this->account('Arthur')->zen);

        $this->battles->create($this->character('Robin')->id, new Battle(), $this->clock->now());
        self::assertSame('inventory.error_in_battle', $this->errorOf(fn () => $this->store()->buy($this->account('Robin'), $this->character('Robin'), $big)));
    }

    public function testMarketFiltersAndOrders(): void
    {
        $this->offer('Arthur', self::KRIS, 900);
        $this->offer('Arthur', self::HELM, 100);
        $this->offer('Merlin', self::STAFF, 500);
        $this->offer('Merlin', self::BLESS, 300);
        $this->store()->open($this->account('Arthur'));
        $this->store()->open($this->account('Merlin'));

        $names = static fn (array $rows): array => array_map(static fn (array $r): string => $r['item']->name, $rows);

        self::assertSame(['jewel_of_bless', 'skull_staff', 'bronze_helm', 'kris'], $names($this->store()->listings()), 'por defecto, lo más nuevo primero');
        self::assertSame(['bronze_helm', 'jewel_of_bless', 'skull_staff', 'kris'], $names($this->store()->listings(order: 'price')));
        self::assertSame(['bronze_helm', 'jewel_of_bless', 'kris', 'skull_staff'], $names($this->store()->listings(order: 'name')));
        self::assertSame('skull_staff', $names($this->store()->listings(order: 'excellent'))[0]);
        self::assertSame(['skull_staff', 'kris'], $names($this->store()->listings('weapon')));
        self::assertSame(['jewel_of_bless'], $names($this->store()->listings('jewel')));
        self::assertSame(['skull_staff'], $names($this->store()->listings(null, CharacterClass::DarkWizard)), 'sólo lo que pide esa clase');
        self::assertSame([], $names($this->store()->listings('wings')));
        self::assertSame('uMerlin', $this->store()->listings('jewel')[0]['seller']);
    }

    // --- clanes ---

    public function testCreatingAGuildNeedsLevelZenAndAFreeName(): void
    {
        $create = fn (string $who, string $name) => $this->guilds()->create($this->account($who), $this->character($who), $name);

        self::assertSame('guild.error_level', $this->errorOf(fn () => $create('Arthur', 'Knights')));
        $this->characters->saveProgress($this->character('Arthur')->id, 10, 0, 0, 100, 20, 0);
        self::assertSame('guild.error_name', $this->errorOf(fn () => $create('Arthur', 'a b')));

        $create('Arthur', 'Knights');

        self::assertSame('Knights', $this->guilds()->of($this->character('Arthur'))['name'] ?? null);
        self::assertSame(4000, $this->account('Arthur')->zen);
        self::assertSame('guild.error_member', $this->errorOf(fn () => $create('Arthur', 'Second')));

        $this->characters->saveProgress($this->character('Merlin')->id, 10, 0, 0, 60, 60, 0);
        self::assertSame('guild.error_taken', $this->errorOf(fn () => $create('Merlin', 'KNIGHTS')));
        $this->pdo->exec('UPDATE accounts SET zen = 0');
        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $create('Merlin', 'Mages')));
    }

    private function foundKnights(): void
    {
        $this->characters->saveProgress($this->character('Arthur')->id, 10, 0, 0, 100, 20, 0);
        $this->guilds()->create($this->account('Arthur'), $this->character('Arthur'), 'Knights');
    }

    public function testInviteAcceptDeclineAndLeave(): void
    {
        $this->foundKnights();
        $guilds = $this->guilds();

        self::assertSame('guild.error_not_founder', $this->errorOf(fn () => $guilds->invite($this->account('Merlin'), $this->character('Merlin'), 'Robin')));
        self::assertSame('guild.error_no_character', $this->errorOf(fn () => $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Nobody')));

        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'merlin'); // sin distinguir mayúsculas
        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Robin');
        self::assertSame('guild.error_taken_character', $this->errorOf(fn () => $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Robin')));
        self::assertNull($this->character('Merlin')->guildId, 'invitado todavía no es miembro');
        self::assertSame('Knights', $guilds->invitation($this->character('Merlin'))['name'] ?? null);

        $guilds->answer($this->account('Merlin'), $this->character('Merlin'), true);
        $guilds->answer($this->account('Robin'), $this->character('Robin'), false);

        self::assertNotNull($this->character('Merlin')->guildId);
        self::assertSame([null, null], [$this->character('Robin')->guildId, $this->character('Robin')->guildInviteId]);
        self::assertSame(['Arthur', 'Merlin'], array_column($guilds->roster($this->character('Arthur')->guildId ?? 0), 'name'));
        self::assertSame('guild.error_no_invite', $this->errorOf(fn () => $guilds->answer($this->account('Robin'), $this->character('Robin'), true)));

        self::assertSame('guild.error_founder_quit', $this->errorOf(fn () => $guilds->quit($this->account('Arthur'), $this->character('Arthur'))));
        $guilds->quit($this->account('Merlin'), $this->character('Merlin'));
        self::assertNull($this->character('Merlin')->guildId);
        self::assertSame('guild.error_not_member', $this->errorOf(fn () => $guilds->quit($this->account('Merlin'), $this->character('Merlin'))));
    }

    public function testKickDisbandAndFounderDeletion(): void
    {
        $this->foundKnights();
        $guilds = $this->guilds();
        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Merlin');
        $guilds->answer($this->account('Merlin'), $this->character('Merlin'), true);
        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Robin');

        self::assertSame('guild.error_not_founder', $this->errorOf(fn () => $guilds->kick($this->account('Merlin'), $this->character('Merlin'), $this->character('Robin')->id)));
        self::assertSame('guild.error_founder_quit', $this->errorOf(fn () => $guilds->kick($this->account('Arthur'), $this->character('Arthur'), $this->character('Arthur')->id)));

        $guilds->kick($this->account('Arthur'), $this->character('Arthur'), $this->character('Robin')->id); // retira la invitación
        $guilds->kick($this->account('Arthur'), $this->character('Arthur'), $this->character('Merlin')->id);
        self::assertSame([null, null], [$this->character('Merlin')->guildId, $this->character('Robin')->guildInviteId]);
        self::assertSame('guild.error_no_character', $this->errorOf(fn () => $guilds->kick($this->account('Arthur'), $this->character('Arthur'), $this->character('Merlin')->id)));

        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Merlin');
        $guilds->answer($this->account('Merlin'), $this->character('Merlin'), true);
        self::assertSame('guild.error_not_founder', $this->errorOf(fn () => $guilds->disband($this->account('Merlin'), $this->character('Merlin'))));
        $guilds->disband($this->account('Arthur'), $this->character('Arthur'));
        self::assertSame([null, null], [$this->character('Arthur')->guildId, $this->character('Merlin')->guildId]);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM guilds')->fetchColumn());

        // si se borra el personaje fundador, el clan desaparece y los miembros quedan libres
        $this->foundKnights();
        $guilds->invite($this->account('Arthur'), $this->character('Arthur'), 'Merlin');
        $guilds->answer($this->account('Merlin'), $this->character('Merlin'), true);
        $this->characters->delete($this->character('Arthur')->id);
        self::assertNull($this->character('Merlin')->guildId);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM guilds')->fetchColumn());
    }

    public function testGuildIsCappedAtTwentyIncludingInvitations(): void
    {
        $this->foundKnights();
        for ($i = 1; $i < GuildService::MAX_MEMBERS; $i++) {
            $account = $this->accounts->insert("extra$i", "extra$i@example.test", 'hash', 'es', '10.0.0.1', $this->clock->now());
            $this->characters->insert($account->id, "Extra$i", CharacterClass::DarkKnight, $this->clock->now());
            $this->guilds()->invite($this->account('Arthur'), $this->character('Arthur'), "Extra$i");
        }

        self::assertSame('guild.error_full', $this->errorOf(fn () => $this->guilds()->invite($this->account('Arthur'), $this->character('Arthur'), 'Merlin')));
    }

    public function testGuildmatesInTheSameSpotRaiseTheExperienceRate(): void
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/data/game.php';
        $presence = new PresenceRepository($this->pdo);
        $service = new BattleService(
            new BattleEngine(new SkillCatalog($root . '/data/skills.php'), new DropGenerator($root . '/data/drop', $config['drop']), $config),
            new StatsCalculator(),
            new MapCatalog($root . '/data/maps'),
            new GoldenInvasion(new GoldenRepository($this->pdo), $config['golden']['hours']),
            $this->battles,
            $this->characters,
            $this->accounts,
            $this->items,
            $presence,
            $this->clock,
            new FakeRng([1], 'max'),
            $config,
            new QuestCatalog($root . '/data/quests.xml'),
        );

        // Arthur ya viene sacando 30 de experiencia por turno en el spot.
        $presence->touch($this->character('Arthur')->id, 'lorencia:spot1', 30, $this->clock->now());

        // Un extraño que recién llega recibe el mínimo: el spot ya tiene dueño.
        self::assertSame(10, $service->enter($this->character('Robin'), 'lorencia', 'spot1')->expPercent);
        $service->leave($this->character('Robin'));

        // El mismo recién llegado, pero compañero de clan, recibe bonus en vez de penalidad.
        $this->foundKnights();
        $this->guilds()->invite($this->account('Arthur'), $this->character('Arthur'), 'Merlin');
        $this->guilds()->answer($this->account('Merlin'), $this->character('Merlin'), true);
        self::assertSame(130, $service->enter($this->character('Merlin'), 'lorencia', 'spot1')->expPercent);
    }

    // --- chat ---

    public function testChatChannelsAndSanitising(): void
    {
        $chat = new ChatService($this->pdo, $this->clock);

        $chat->post($this->character('Arthur'), ChatService::GLOBAL, "  hola <b>a todos</b>\n<script>alert(1)</script>  ");
        $this->clock->advance('+5 seconds');
        $chat->post($this->character('Merlin'), 'lorencia:spot1', 'alguien en el spot?');
        $this->clock->advance('+5 seconds');
        $chat->post($this->character('Robin'), 'noria:spot12', 'otro spot');

        $global = $chat->recent();
        self::assertSame(['hola a todos alert(1)'], array_column($global, 'text'), 'sin etiquetas ni saltos de línea');
        self::assertSame(['Arthur', true], [$global[0]['poster'], $global[0]['global']]);
        self::assertSame($this->character('Arthur')->class->label(), $global[0]['class'], 'el chat muestra la clase de quien escribe');
        self::assertStringStartsWith('2026-01-05T12:00:00', $global[0]['time']);

        self::assertSame(['hola a todos alert(1)', 'alguien en el spot?'], array_column($chat->recent('lorencia:spot1'), 'text'));
        self::assertSame(['hola a todos alert(1)', 'otro spot'], array_column($chat->recent('noria:spot12'), 'text'));
    }

    public function testChatLimits(): void
    {
        $chat = new ChatService($this->pdo, $this->clock);

        self::assertSame('chat.error_empty', $this->errorOf(fn () => $chat->post($this->character('Arthur'), ChatService::GLOBAL, " <i></i> \n ")));

        $chat->post($this->character('Arthur'), ChatService::GLOBAL, str_repeat('ñ', 300));
        self::assertSame(ChatService::MAX_LENGTH, mb_strlen($chat->recent()[0]['text']));

        self::assertSame('chat.error_too_fast', $this->errorOf(fn () => $chat->post($this->character('Arthur'), ChatService::GLOBAL, 'otra vez')));
        $chat->post($this->character('Merlin'), ChatService::GLOBAL, 'yo sí puedo'); // el límite es por personaje

        for ($i = 0; $i < 20; $i++) {
            $this->clock->advance('+3 seconds');
            $chat->post($this->character('Arthur'), ChatService::GLOBAL, "mensaje $i");
        }
        $recent = $chat->recent();
        self::assertCount(ChatService::VISIBLE, $recent);
        self::assertSame('mensaje 19', $recent[array_key_last($recent)]['text'], 'el más nuevo queda al final');

        $this->clock->advance('+61 minutes');
        self::assertSame([], $chat->recent(), 'los mensajes vencen a la hora');
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM chat_messages')->fetchColumn());
    }

    // --- ranking y conectados ---

    public function testRankingOrdersFiltersAndPaginates(): void
    {
        $this->characters->saveProgress($this->character('Arthur')->id, 5, 0, 300, 100, 20, 7);
        $this->characters->saveProgress($this->character('Merlin')->id, 9, 0, 900, 60, 60, 2);
        $this->characters->saveProgress($this->character('Robin')->id, 3, 0, 100, 80, 30, 40);
        $this->foundKnightsFor('Merlin');

        $byExp = $this->characters->ranking([], 'experience', 10, 0);
        self::assertSame(3, $byExp['total']);
        self::assertSame(['Merlin', 'Arthur', 'Robin'], array_column($byExp['rows'], 'name'));
        self::assertSame('Mages', $byExp['rows'][0]['guild']);
        self::assertNull($byExp['rows'][1]['guild']);

        self::assertSame(['Robin', 'Arthur', 'Merlin'], array_column($this->characters->ranking([], 'kills', 10, 0)['rows'], 'name'));

        $knights = $this->characters->ranking([CharacterClass::DarkKnight->value, CharacterClass::BladeKnight->value], 'experience', 10, 0);
        self::assertSame([1, ['Arthur']], [$knights['total'], array_column($knights['rows'], 'name')]);

        self::assertSame(['Arthur'], array_column($this->characters->ranking([], 'experience', 1, 1)['rows'], 'name'), 'segunda página de a uno');
    }

    private function foundKnightsFor(string $name): void
    {
        $this->characters->saveProgress($this->character($name)->id, 10, 0, 900, 60, 60, 2);
        $this->guilds()->create($this->account($name), $this->character($name), 'Mages');
    }

    public function testOnlineListsRecentlySeenCharactersAndWhereTheyFight(): void
    {
        $now = $this->clock->now();
        $since = fn () => $this->clock->now()->modify('-300 seconds');
        self::assertSame(0, $this->characters->countOnline($since()));

        $this->characters->touchSeen($this->character('Arthur')->id, $now);
        $this->characters->touchSeen($this->character('Merlin')->id, $now);
        new PresenceRepository($this->pdo)->touch($this->character('Merlin')->id, 'devias:spot33', 0, $now);

        self::assertSame(2, $this->characters->countOnline($since()));
        $online = array_column($this->characters->online($since()), 'location', 'name');
        self::assertSame(['Arthur' => null, 'Merlin' => 'devias:spot33'], $online);

        // dentro del mismo minuto no vuelve a escribir; pasados 5 minutos sin actividad deja de contar
        $this->clock->advance('+30 seconds');
        $this->characters->touchSeen($this->character('Arthur')->id, $this->clock->now());
        self::assertSame($now->format('Y-m-d H:i:s'), $this->pdo->query("SELECT last_seen_at FROM characters WHERE name = 'Arthur'")->fetchColumn());

        $this->clock->advance('+5 minutes');
        $this->characters->touchSeen($this->character('Arthur')->id, $this->clock->now());
        self::assertSame(['Arthur'], array_column($this->characters->online($since()), 'name'));
    }
}
