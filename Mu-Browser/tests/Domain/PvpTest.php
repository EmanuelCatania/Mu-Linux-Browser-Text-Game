<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\DuelRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Item\Item;
use Mu\Domain\Pvp\Duel;
use Mu\Domain\Pvp\DuelEngine;
use Mu\Domain\Pvp\DuelService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Support\Rng;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** PvP: motor del duelo, desafíos, apuestas de zen e ítems y ranking. */
final class PvpTest extends TestCase
{
    private const string KRIS = '1.12.1.0011.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.kris';
    private const string HELM = '5.22.0.0005.00.0.0000.0000.00.0.0.00.00.0.022.022.000000.bronze_helm';
    private const string BLESS = 'D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless';
    private const int EXPIRE = 600;

    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private ItemRepository $items;
    private DuelRepository $duels;
    private SkillCatalog $skills;
    /** @var array<string, array{Account, Character}> */
    private array $players = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->items = new ItemRepository($this->pdo);
        $this->duels = new DuelRepository($this->pdo, self::EXPIRE);
        $this->skills = new SkillCatalog(dirname(__DIR__, 2) . '/data/skills.php');

        foreach (['Arthur' => CharacterClass::DarkKnight, 'Merlin' => CharacterClass::DarkWizard, 'Robin' => CharacterClass::FairyElf] as $name => $class) {
            $account = $this->accounts->insert("u$name", "$name@example.test", 'hash', 'es', '10.0.0.1', $this->clock->now());
            $character = $this->characters->insert($account->id, $name, $class, $this->clock->now());
            $this->characters->touchSeen($character->id, $this->clock->now());
            $this->players[$name] = [$account, $character];
        }
        // Arthur es mucho más fuerte: con el azar fijo gana siempre.
        $this->pdo->exec("UPDATE characters SET strength = 600, agility = 300, stamina = 300 WHERE name = 'Arthur'");
    }

    // --- motor ---

    public function testTheStrongerFighterWinsAndTheLogEndsWithAKnockout(): void
    {
        $fight = $this->engine()->fight([self::stats(damage: 50, hp: 200), self::stats(damage: 10, hp: 100)], new FakeRng([0]));

        self::assertSame(0, $fight['winner']);
        self::assertSame(0, $fight['first']);
        self::assertSame([200, 100], $fight['hpMax']);
        self::assertSame(0, $fight['hp'][1]);
        self::assertSame([0, 1, 0], array_column($fight['log'], 'by'), 'pegan por turnos: dos golpes de 50 alcanzan');
        self::assertSame([190, 0], end($fight['log'])['hp']);
    }

    public function testDefenseAbsorptionAndMissesAreApplied(): void
    {
        // 50 de daño - 20 de defensa = 30; absorbe 50% → 15.
        $tank = self::stats(damage: 1, hp: 100, defense: 20, absorb: 50);
        $fight = $this->engine(1)->fight([self::stats(damage: 50, hp: 100), $tank], new FakeRng([0]));
        self::assertSame(15, $fight['log'][0]['n']);
        self::assertSame(1, $fight['log'][1]['n'], 'el daño nunca baja de 1');

        // Tirada 1 de 100: siempre queda dentro de la probabilidad mínima de fallar (5%).
        $fight = $this->engine(1)->fight([self::stats(damage: 50, hp: 100), $tank], new FakeRng([0], 'min'));
        self::assertSame([0, 0], array_column($fight['log'], 'n'));
        self::assertNull($fight['winner'], 'nadie se tocó: empate');
    }

    public function testUsesTheStrongestSkillTheManaAllowsThenFallsBackToAttack(): void
    {
        // Twisting Slash (22): +60~110 por 10 de maná. Impale (21): +70~120 por 8. Con 12 de maná alcanza una sola vez.
        $knight = self::stats(damage: 10, hp: 500, mp: 12, skills: ['21', '22']);
        $fight = $this->engine(3)->fight([$knight, self::stats(damage: 1, hp: 5000)], new FakeRng([0]));

        $mine = array_values(array_filter($fight['log'], static fn (array $e): bool => $e['by'] === 0));
        self::assertSame(['21', null, null], array_column($mine, 'skill'));
        self::assertSame(130, $mine[0]['n']);
        self::assertSame(10, $mine[1]['n']);
    }

    public function testWithoutAKnockoutTheBestRemainingLifeFractionWins(): void
    {
        // 3 turnos por lado: el primero pierde 30 de 100 (70%), el segundo 30 de 1000 (97%).
        $fight = $this->engine(3)->fight([self::stats(damage: 10, hp: 100), self::stats(damage: 10, hp: 1000)], new FakeRng([0]));
        self::assertSame(1, $fight['winner']);
        self::assertCount(6, $fight['log']);

        $draw = $this->engine(3)->fight([self::stats(damage: 10, hp: 100), self::stats(damage: 10, hp: 100)], new FakeRng([1]));
        self::assertNull($draw['winner']);
        self::assertSame(1, $draw['first']);
    }

    public function testReflectHurtsTheAttackerButNeverFinishesHim(): void
    {
        $fight = $this->engine(2)->fight([self::stats(damage: 40, hp: 30), self::stats(damage: 1, hp: 500, reflect: 50)], new FakeRng([0]));

        self::assertSame(20, $fight['log'][0]['reflect']);
        self::assertSame(10, $fight['log'][0]['hp'][0]);
        self::assertSame(8, $fight['log'][2]['reflect'], 'le quedaban 9: el reflejo lo deja en 1');
        self::assertSame(1, $fight['log'][2]['hp'][0]);
    }

    public function testAnArcherWithoutArrowsCannotShoot(): void
    {
        $archer = self::stats(damage: 80, hp: 100, usesArrows: true, arrows: 0);
        $fight = $this->engine(2)->fight([$archer, self::stats(damage: 5, hp: 100)], new FakeRng([0]));

        self::assertSame([0, 5, 0, 5], array_column($fight['log'], 'n'));
        self::assertSame(1, $fight['winner']);
    }

    // --- desafíos ---

    public function testChallengeRules(): void
    {
        $service = $this->service();
        $arthur = fn (string $rival, int $zen = 0) => $this->errorOf(fn () => $service->challenge($this->account('Arthur'), $this->character('Arthur'), $rival, $zen));

        self::assertSame('pvp.error_target', $arthur('Nadie'));
        self::assertSame('pvp.error_self', $arthur('Arthur'));
        self::assertSame('pvp.error_zen', $arthur('Merlin', -1));
        self::assertSame('pvp.error_zen', $arthur('Merlin', 100_000_000));
        self::assertSame('shop.error_no_money', $arthur('Merlin', 5001));

        $this->clock->advance('+10 minutes');
        self::assertSame('pvp.error_offline', $arthur('Merlin'));
        $this->characters->touchSeen($this->character('Merlin')->id, $this->clock->now());
        $this->characters->touchSeen($this->character('Robin')->id, $this->clock->now());

        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), ' merlin ', 100);
        $duel = $service->open($this->account('Merlin')) ?? self::fail('sin duelo');
        self::assertSame([$id, Duel::PENDING, 'Arthur', 'Merlin', 100], [$duel->id, $duel->status, $duel->challengerName, $duel->targetName, $duel->zen]);
        self::assertSame(1, $service->incoming($this->account('Merlin')));
        self::assertSame(0, $service->incoming($this->account('Arthur')));

        // Como en el fork: un desafío a la vez, y no a quien ya tiene uno.
        self::assertSame('pvp.error_busy_self', $arthur('Robin'));
        self::assertSame('pvp.error_busy_target', $this->errorOf(fn () => $service->challenge($this->account('Robin'), $this->character('Robin'), 'Merlin', 0)));
        self::assertSame('pvp.error_busy_target', $this->errorOf(fn () => $service->challenge($this->account('Robin'), $this->character('Robin'), 'Arthur', 0)));
        self::assertSame('pvp.error_busy_self', $this->errorOf(fn () => $service->challenge($this->account('Merlin'), $this->character('Merlin'), 'Robin', 0)));
    }

    public function testADuelWithoutItemsIsFoughtOnAcceptAndMovesTheZen(): void
    {
        $service = $this->service();
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 1500);
        self::assertSame(5000, $this->account('Arthur')->zen, 'el zen no se retiene por adelantado');

        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->accept($this->account('Arthur'), $id)), 'el retador no puede aceptarse a sí mismo');
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->accept($this->account('Robin'), $id)));
        self::assertSame(DuelService::OUTCOME_FINISHED, $service->accept($this->account('Merlin'), $id));

        self::assertSame(6500, $this->account('Arthur')->zen);
        self::assertSame(3500, $this->account('Merlin')->zen);
        self::assertSame(['wins' => 1, 'losses' => 0], $service->record($this->character('Arthur')));
        self::assertSame(['wins' => 0, 'losses' => 1], $service->record($this->character('Merlin')));
        self::assertNull($service->open($this->account('Arthur')));

        $duel = $service->find($id) ?? self::fail('sin duelo');
        self::assertSame(Duel::FINISHED, $duel->status);
        self::assertSame($this->character('Arthur')->id, $duel->winnerId);
        self::assertSame(['Arthur', 'Merlin'], $duel->result['names']);
        self::assertSame(1500, $duel->result['zen']);
        self::assertNotEmpty($duel->result['log']);
        self::assertSame([$id], array_map(static fn (Duel $d): int => $d->id, $service->history($this->character('Merlin'))));

        // No cambia la vida ni el maná de nadie.
        self::assertSame($this->players['Merlin'][1]->hpCur, $this->character('Merlin')->hpCur);
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->accept($this->account('Merlin'), $id)), 'no se pelea dos veces');
    }

    public function testBothPlayersAreNotifiedEvenIfTheyAreOffline(): void
    {
        $notes = new \Mu\Data\NotificationRepository($this->pdo, $this->clock);
        $service = $this->service();
        $arthur = $this->account('Arthur')->id;
        $merlin = $this->account('Merlin')->id;
        $note = static fn (array $n): array => [$n['key'], $n['params'], $n['link']];

        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 100);
        self::assertSame(1, $notes->unread($merlin));
        self::assertSame(['notify.duel_challenged', ['name' => 'Arthur', 'zen' => 100], '/pvp'], $note($notes->latest($merlin)[0]));

        $service->accept($this->account('Merlin'), $id);
        self::assertSame(['notify.duel_won', ['name' => 'Merlin', 'zen' => 100], '/pvp/' . $id], $note($notes->latest($arthur)[0]));
        self::assertSame('notify.duel_lost', $notes->latest($merlin)[0]['key']);

        $notes->markAllRead($merlin);
        self::assertSame(0, $notes->unread($merlin));
        self::assertTrue($notes->latest($merlin)[0]['read']);
        $notes->clear($merlin);
        self::assertSame([], $notes->latest($merlin));

        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 0);
        $service->withdraw($this->account('Merlin'), $id);
        self::assertSame('notify.duel_declined', $notes->latest($arthur)[0]['key']);
    }

    public function testItemBetsNeedTheChallengersConfirmationAndGoToTheWinnersPrizes(): void
    {
        $service = $this->service();
        $inventory = $this->inventory();
        $toTable = function (string $name, string $code, int $slot) use ($inventory): void {
            $from = $this->give($name, $code);
            $inventory->move($this->account($name), $this->character($name), Container::Inventory, $from, Container::Duel, $slot);
        };

        $toTable('Arthur', self::KRIS, 0);
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 0);

        // La apuesta del retador queda trabada; el desafiado todavía arma la suya.
        self::assertSame('pvp.error_stake_locked', $this->errorOf(fn () => $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Duel, 0, Container::Inventory, 0)));
        $toTable('Merlin', self::HELM, 3);
        $toTable('Merlin', self::BLESS, 10);

        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->confirm($this->account('Arthur'), $id)), 'todavía no hay nada que confirmar');
        self::assertSame(DuelService::OUTCOME_ANSWERED, $service->accept($this->account('Merlin'), $id));
        self::assertSame(Duel::ANSWERED, $service->open($this->account('Arthur'))?->status);
        self::assertSame('pvp.error_stake_locked', $this->errorOf(fn () => $inventory->move($this->account('Merlin'), $this->character('Merlin'), Container::Duel, 3, Container::Inventory, 0)));
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->confirm($this->account('Merlin'), $id)), 'confirma el retador, no el desafiado');

        self::assertSame(DuelService::OUTCOME_FINISHED, $service->confirm($this->account('Arthur'), $id));

        self::assertSame(['kris'], $this->names(Container::Duel, 'Arthur'), 'el ganador conserva su apuesta');
        self::assertSame([3 => 'bronze_helm', 10 => 'jewel_of_bless'], $this->slots(Container::Prize, 'Arthur'), 'mismo lugar que en la mesa del perdedor');
        self::assertSame([], $this->names(Container::Duel, 'Merlin'));
        self::assertSame([], $this->names(Container::Prize, 'Merlin'));
        self::assertCount(2, $service->find($id)?->result['stakes'][1] ?? []);

        // Con premios sin retirar no se apuesta de nuevo; en Premios no se deja nada, sólo se saca.
        self::assertSame('pvp.error_prize_pending', $this->errorOf(fn () => $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Robin', 0)));
        self::assertSame('pvp.error_prize_only_out', $this->errorOf(fn () => $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Duel, 0, Container::Prize, 20)));
        $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Prize, 3, Container::Inventory, 0);
        $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Prize, 10, Container::Inventory, 2);
        $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Duel, 0, Container::Inventory, 3);
        self::assertSame(['bronze_helm', 'jewel_of_bless', 'kris'], $this->names(Container::Inventory, 'Arthur'));
        $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Robin', 0);
    }

    public function testTheTargetCannotAcceptWithUnclaimedPrizesOrWithoutTheZen(): void
    {
        $service = $this->service();
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 4000);

        $this->accounts->spendZen($this->account('Merlin')->id, 2000);
        self::assertSame('shop.error_no_money', $this->errorOf(fn () => $service->accept($this->account('Merlin'), $id)));
        $this->accounts->addZen($this->account('Merlin')->id, 2000);

        $this->items->put(Container::Prize, $this->account('Merlin')->id, 0, Item::parse(self::KRIS), $this->clock->now());
        self::assertSame('pvp.error_prize_pending', $this->errorOf(fn () => $service->accept($this->account('Merlin'), $id)));
        self::assertSame(Duel::PENDING, $service->open($this->account('Merlin'))?->status, 'sigue pendiente: puede retirar el premio y aceptar');
    }

    public function testDecliningCancellingAndExpiringLeaveEverythingInPlace(): void
    {
        $service = $this->service();
        $inventory = $this->inventory();
        $slot = $this->give('Arthur', self::KRIS);
        $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Inventory, $slot, Container::Duel, 0);
        $back = fn () => $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Duel, 0, Container::Duel, 1);

        // rechaza el desafiado
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 500);
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->withdraw($this->account('Robin'), $id)));
        $service->withdraw($this->account('Merlin'), $id);
        self::assertSame(Duel::DECLINED, $service->find($id)?->status);
        $back();

        // cancela el retador, incluso después de que el otro aceptó
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 500);
        $service->accept($this->account('Merlin'), $id);
        $service->withdraw($this->account('Arthur'), $id);
        self::assertSame(Duel::CANCELLED, $service->find($id)?->status);
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->confirm($this->account('Arthur'), $id)));

        // vence solo
        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 500);
        $this->clock->advance('+' . (self::EXPIRE + 1) . ' seconds');
        self::assertNull($service->open($this->account('Arthur')));
        self::assertSame(0, $service->incoming($this->account('Merlin')));
        self::assertSame('pvp.error_gone', $this->errorOf(fn () => $service->accept($this->account('Merlin'), $id)));
        $inventory->move($this->account('Arthur'), $this->character('Arthur'), Container::Duel, 1, Container::Inventory, 0);

        $this->characters->touchSeen($this->character('Robin')->id, $this->clock->now());
        $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Robin', 0);
        self::assertSame(Duel::EXPIRED, $service->find($id)?->status, 'el próximo desafío deja marcado el vencido');

        self::assertSame([5000, 5000], [$this->account('Arthur')->zen, $this->account('Merlin')->zen]);
        self::assertSame(['wins' => 0, 'losses' => 0], $service->record($this->character('Arthur')));
    }

    public function testTheDuelIsVoidedIfSomeoneSpentTheZenBeforeTheFight(): void
    {
        $service = $this->service();
        $slot = $this->give('Merlin', self::HELM);
        $this->inventory()->move($this->account('Merlin'), $this->character('Merlin'), Container::Inventory, $slot, Container::Duel, 0);

        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 3000);
        self::assertSame(DuelService::OUTCOME_ANSWERED, $service->accept($this->account('Merlin'), $id));
        $this->accounts->spendZen($this->account('Merlin')->id, 4000);

        self::assertSame(DuelService::OUTCOME_VOID, $service->confirm($this->account('Arthur'), $id));
        self::assertSame(Duel::CANCELLED, $service->find($id)?->status);
        self::assertSame(5000, $this->account('Arthur')->zen);
        self::assertSame(['bronze_helm'], $this->names(Container::Duel, 'Merlin'));
        self::assertSame(['wins' => 0, 'losses' => 0], $service->record($this->character('Arthur')));
    }

    public function testADrawMovesNothing(): void
    {
        // Dos personajes idénticos que apenas se rasguñan: se acaban los turnos con la misma vida.
        $this->pdo->exec('UPDATE characters SET class = ' . CharacterClass::DarkKnight->value . ", strength = 30, agility = 90, stamina = 200, energy = 10 WHERE name IN ('Arthur', 'Merlin')");
        $service = $this->service();
        $slot = $this->give('Merlin', self::HELM);
        $this->inventory()->move($this->account('Merlin'), $this->character('Merlin'), Container::Inventory, $slot, Container::Duel, 0);

        $id = $service->challenge($this->account('Arthur'), $this->character('Arthur'), 'Merlin', 1000);
        $service->accept($this->account('Merlin'), $id);
        self::assertSame(DuelService::OUTCOME_FINISHED, $service->confirm($this->account('Arthur'), $id));

        $duel = $service->find($id) ?? self::fail('sin duelo');
        self::assertNull($duel->winnerId);
        self::assertNull($duel->result['winner']);
        self::assertSame([5000, 5000], [$this->account('Arthur')->zen, $this->account('Merlin')->zen]);
        self::assertSame(['bronze_helm'], $this->names(Container::Duel, 'Merlin'));
        self::assertSame([], $this->names(Container::Prize, 'Arthur'));
        self::assertSame(['wins' => 0, 'losses' => 0], $service->record($this->character('Merlin')));
    }

    public function testRankingByDuelsWon(): void
    {
        $service = $this->service();
        foreach (['Merlin', 'Robin'] as $rival) {
            $service->accept($this->account($rival), $service->challenge($this->account('Arthur'), $this->character('Arthur'), $rival, 0));
        }
        $this->pdo->exec("UPDATE characters SET pvp_wins = 1 WHERE name = 'Robin'");

        $rows = $this->characters->ranking([], 'pvp', 10, 0)['rows'];
        self::assertSame(['Arthur', 'Robin', 'Merlin'], array_column($rows, 'name'));
        self::assertSame([2, 0], [$rows[0]['pvp_wins'], $rows[0]['pvp_losses']]);
        self::assertSame([1, 1], [$rows[1]['pvp_wins'], $rows[1]['pvp_losses']]);
    }

    // --- ayudas ---

    private function engine(int $maxRounds = 30): DuelEngine
    {
        return new DuelEngine($this->skills, $maxRounds);
    }

    /** Con el azar fijo en el máximo nadie falla y cada golpe hace su daño más alto. */
    private function service(?Rng $rng = null): DuelService
    {
        return new DuelService(
            $this->duels,
            $this->engine(),
            $this->characters,
            $this->accounts,
            $this->items,
            new StatsCalculator(),
            new Transaction($this->pdo),
            $this->clock,
            $rng ?? new FakeRng(),
            99_999_999,
            new \Mu\Data\NotificationRepository($this->pdo, $this->clock),
        );
    }

    private function inventory(): InventoryService
    {
        return new InventoryService(
            $this->items,
            $this->characters,
            new BattleRepository($this->pdo),
            new StatsCalculator(),
            $this->skills,
            new Transaction($this->pdo),
            new FakeRng(),
            $this->duels,
            $this->clock,
        );
    }

    /** @param list<string> $skills */
    private static function stats(
        int $damage,
        int $hp,
        int $mp = 0,
        int $defense = 0,
        int $absorb = 0,
        int $reflect = 0,
        array $skills = [],
        bool $usesArrows = false,
        int $arrows = -1,
    ): CharacterStats {
        return new CharacterStats(
            energy: 0, hpMax: $hp, mpMax: $mp, damageMin: $damage, damageMax: $damage, damageBonus: 0, damageRate: 100,
            wizDamageMin: 0, wizDamageMax: 0, staffRise: 0, curseIncrement: 0, defense: $defense, defenseRate: 10,
            damageAbsorb: $absorb, damageDecrease: 0, luck: 0, excellentRate: 0, reflect: $reflect, ignoreDefense: 0,
            criticalDamageBonus: 0, skillDamageBonus: 0, hpRegeneration: 0, mpRegeneration: 0, afterHuntHp: 0,
            afterHuntMp: 0, afterHuntZen: 0, elementResist: [], skills: $skills, usesArrows: $usesArrows, arrows: $arrows,
        );
    }

    private function account(string $name): Account
    {
        return $this->accounts->findById($this->players[$name][0]->id) ?? self::fail('cuenta inexistente');
    }

    private function character(string $name): Character
    {
        return $this->characters->findById($this->players[$name][1]->id) ?? self::fail('personaje inexistente');
    }

    private function give(string $name, string $code): int
    {
        return $this->items->addToInventory($this->players[$name][1]->id, Item::parse($code), $this->clock->now()) ?? self::fail('sin lugar');
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

    /** @return array<int, string> nombre del ítem por slot */
    private function slots(Container $container, string $player): array
    {
        $owner = $container->ownedByAccount() ? $this->account($player)->id : $this->character($player)->id;

        return array_map(static fn (Item $i): string => $i->name, $this->items->items($container, $owner));
    }

    /** @return list<string> */
    private function names(Container $container, string $player): array
    {
        return array_values($this->slots($container, $player));
    }
}
