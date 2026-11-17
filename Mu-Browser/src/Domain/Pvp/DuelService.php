<?php

declare(strict_types=1);

namespace Mu\Domain\Pvp;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\DuelRepository;
use Mu\Data\ItemRepository;
use Mu\Data\NotificationRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\StoredItem;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Support\Clock;
use Mu\Support\Rng;

/**
 * Desafíos de PvP con apuesta.
 *
 * Del fork (function.pvp_request.php) sale la parte del desafío: uno solo a la vez, y no se puede desafiar
 * a quien ya tiene uno pendiente. El resto es nuevo, porque el fork no llegó a tener pelea:
 *
 *  1. El retador arma su apuesta (zen y los ítems que deje en la mesa de apuestas) y desafía.
 *  2. El desafiado ve esa apuesta, arma la suya en ítems (el zen es el mismo para los dos) y acepta, o rechaza.
 *  3. Si hay ítems en juego, el retador ve lo que puso el otro y confirma o cancela. Sin ítems se pelea al aceptar.
 *  4. El duelo se resuelve solo (DuelEngine). El ganador se lleva el zen y los ítems del perdedor, que le
 *     llegan a "Premios"; en un empate nadie pierde nada.
 *
 * El zen no se retiene por adelantado: se verifica al desafiar, al aceptar y otra vez justo antes de pelear;
 * si a alguno ya no le alcanza, el duelo se anula. Los ítems sí quedan trabados en la mesa (InventoryService).
 */
final class DuelService
{
    /** Segundos sin actividad tras los que un personaje ya no cuenta como conectado. */
    public const int ONLINE_SECONDS = 300;

    public const string OUTCOME_ANSWERED = 'answered';
    public const string OUTCOME_FINISHED = 'finished';
    /** A alguno ya no le alcanzaba el zen: el duelo quedó anulado. */
    public const string OUTCOME_VOID = 'void';

    public function __construct(
        private readonly DuelRepository $duels,
        private readonly DuelEngine $engine,
        private readonly CharacterRepository $characters,
        private readonly AccountRepository $accounts,
        private readonly ItemRepository $items,
        private readonly StatsCalculator $calculator,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly Rng $rng,
        private readonly int $maxZen = 99_999_999,
        private readonly ?NotificationRepository $notifications = null,
    ) {
    }

    public function open(Account $account): ?Duel
    {
        return $this->duels->openFor($account->id, $this->clock->now());
    }

    public function find(int $id): ?Duel
    {
        return $this->duels->find($id);
    }

    /** @return list<Duel> */
    public function history(Character $character, int $limit = 10): array
    {
        return $this->duels->history($character->id, $limit);
    }

    /** @return array{wins: int, losses: int} */
    public function record(Character $character): array
    {
        return $this->duels->record($character->id);
    }

    public function incoming(Account $account): int
    {
        return $this->duels->countIncoming($account->id, $this->clock->now());
    }

    /** @return array<int, StoredItem> lo que una cuenta tiene en su mesa de apuestas */
    public function stake(int $accountId): array
    {
        return $this->items->stored(Container::Duel, $accountId);
    }

    /**
     * Desafía a otro personaje apostando $zen y lo que haya en la mesa de apuestas.
     *
     * @return int id del duelo
     *
     * @throws InventoryException
     */
    public function challenge(Account $account, Character $character, string $targetName, int $zen): int
    {
        $target = $this->characters->findByName(trim($targetName)) ?? throw new InventoryException('pvp.error_target');
        if ($target->accountId === $account->id) {
            throw new InventoryException('pvp.error_self');
        }
        if ($zen < 0 || $zen > $this->maxZen) {
            throw new InventoryException('pvp.error_zen');
        }

        return $this->transaction->runMany([$account->id, $target->accountId], function () use ($account, $character, $target, $zen): int {
            $now = $this->clock->now();
            $this->duels->expireOld($now);

            if ($this->duels->openFor($account->id, $now) !== null) {
                throw new InventoryException('pvp.error_busy_self');
            }
            if ($this->duels->openFor($target->accountId, $now) !== null) {
                throw new InventoryException('pvp.error_busy_target');
            }
            if (!$this->duels->isOnline($target->id, $now->modify('-' . self::ONLINE_SECONDS . ' seconds'))) {
                throw new InventoryException('pvp.error_offline');
            }
            $this->assertCanBet($account->id, $zen);

            $id = $this->duels->insert($character->id, $account->id, $target->id, $target->accountId, $zen, $now);
            $this->notifications?->notify($target->accountId, 'notify.duel_challenged', ['name' => $character->name, 'zen' => $zen], '/pvp');

            return $id;
        });
    }

    /**
     * El desafiado acepta, apostando el mismo zen y lo que tenga en su mesa.
     *
     * @return string OUTCOME_ANSWERED si falta la confirmación del retador, OUTCOME_FINISHED u OUTCOME_VOID si ya se resolvió
     *
     * @throws InventoryException
     */
    public function accept(Account $account, int $duelId): string
    {
        return $this->locked($account, $duelId, function (Duel $duel) use ($account): string {
            if ($duel->isChallenger($account->id) || $duel->status !== Duel::PENDING) {
                throw new InventoryException('pvp.error_gone');
            }
            $this->assertCanBet($account->id, $duel->zen);

            // Sin ítems de ningún lado no hay nada que el retador tenga que revisar.
            if ($this->stake($duel->challengerAccountId) === [] && $this->stake($duel->targetAccountId) === []) {
                return $this->resolve($duel);
            }
            $this->duels->setStatus($duel->id, Duel::ANSWERED, $this->clock->now());
            $this->notifications?->notify($duel->challengerAccountId, 'notify.duel_answered', ['name' => $duel->targetName], '/pvp');

            return self::OUTCOME_ANSWERED;
        });
    }

    /** El retador da por buena la apuesta del desafiado y se pelea. @throws InventoryException */
    public function confirm(Account $account, int $duelId): string
    {
        return $this->locked($account, $duelId, function (Duel $duel) use ($account): string {
            if (!$duel->isChallenger($account->id) || $duel->status !== Duel::ANSWERED) {
                throw new InventoryException('pvp.error_gone');
            }

            return $this->resolve($duel);
        });
    }

    /** El desafiado rechaza, o el retador retira el desafío. Nadie pierde nada. @throws InventoryException */
    public function withdraw(Account $account, int $duelId): void
    {
        $this->locked($account, $duelId, function (Duel $duel) use ($account): string {
            $byChallenger = $duel->isChallenger($account->id);
            $this->duels->setStatus($duel->id, $byChallenger ? Duel::CANCELLED : Duel::DECLINED, $this->clock->now());
            $this->notifications?->notify(
                $byChallenger ? $duel->targetAccountId : $duel->challengerAccountId,
                $byChallenger ? 'notify.duel_withdrawn' : 'notify.duel_declined',
                ['name' => $byChallenger ? $duel->challengerName : $duel->targetName],
                '/pvp',
            );

            return '';
        });
    }

    /**
     * Corre $work con las dos cuentas bloqueadas y el duelo recién leído, que tiene que ser el abierto de $account.
     *
     * @param callable(Duel): string $work
     */
    private function locked(Account $account, int $duelId, callable $work): string
    {
        $duel = $this->duels->find($duelId);
        if ($duel === null || ($duel->challengerAccountId !== $account->id && $duel->targetAccountId !== $account->id)) {
            throw new InventoryException('pvp.error_gone');
        }

        return $this->transaction->runMany([$duel->challengerAccountId, $duel->targetAccountId], function () use ($account, $duelId, $work): string {
            // Con las cuentas bloqueadas se vuelve a leer: pudo vencer, cancelarse o resolverse recién.
            $open = $this->duels->openFor($account->id, $this->clock->now());
            if ($open === null || $open->id !== $duelId) {
                throw new InventoryException('pvp.error_gone');
            }

            return $work($open);
        });
    }

    /** Para apostar hay que tener el zen y haber retirado los premios anteriores (ahí llega lo que se gane). */
    private function assertCanBet(int $accountId, int $zen): void
    {
        if (($this->accounts->findById($accountId)->zen ?? 0) < $zen) {
            throw new InventoryException('shop.error_no_money');
        }
        if ($this->items->stored(Container::Prize, $accountId) !== []) {
            throw new InventoryException('pvp.error_prize_pending');
        }
    }

    private function resolve(Duel $duel): string
    {
        $now = $this->clock->now();
        $fighters = [$this->characters->findById($duel->challengerId), $this->characters->findById($duel->targetId)];
        $accountIds = [$duel->challengerAccountId, $duel->targetAccountId];
        if ($fighters[0] === null || $fighters[1] === null) {
            throw new InventoryException('pvp.error_gone');
        }

        foreach ($accountIds as $accountId) {
            if (($this->accounts->findById($accountId)->zen ?? 0) < $duel->zen) {
                $this->duels->setStatus($duel->id, Duel::CANCELLED, $now);
                foreach ($accountIds as $id) {
                    $this->notifications?->notify($id, 'notify.duel_void', [], '/pvp');
                }

                return self::OUTCOME_VOID;
            }
        }

        $fight = $this->engine->fight([
            $this->calculator->calculate($fighters[0], $this->items->items(Container::Equipment, $fighters[0]->id)),
            $this->calculator->calculate($fighters[1], $this->items->items(Container::Equipment, $fighters[1]->id)),
        ], $this->rng);

        $stakes = [$this->stake($accountIds[0]), $this->stake($accountIds[1])];
        $winner = $fight['winner'];
        if ($winner !== null) {
            $loser = 1 - $winner;
            if ($duel->zen > 0 && $this->accounts->spendZen($accountIds[$loser], $duel->zen)) {
                $this->accounts->addZen($accountIds[$winner], $duel->zen);
            }
            // Mismo lugar que tenían en la mesa del perdedor: "Premios" está vacío y mide lo mismo, así que entran.
            foreach ($stakes[$loser] as $stored) {
                $this->items->relocate($stored->id, Container::Prize, $accountIds[$winner], $stored->slot);
            }
            $this->duels->addResult($fighters[$winner]->id, $fighters[$loser]->id);
        }

        $codes = static fn (array $stake): array => array_values(array_map(static fn (StoredItem $s): string => $s->item->toCode(), $stake));
        $this->duels->finish($duel->id, $winner === null ? null : $fighters[$winner]->id, $fight + [
            'names' => [$fighters[0]->name, $fighters[1]->name],
            'classes' => [$fighters[0]->class->label(), $fighters[1]->class->label()],
            'levels' => [$fighters[0]->level, $fighters[1]->level],
            'zen' => $duel->zen,
            'stakes' => [$codes($stakes[0]), $codes($stakes[1])],
        ], $now);

        // Los dos se enteran del resultado, estén o no conectados.
        foreach ([0, 1] as $side) {
            $this->notifications?->notify(
                $accountIds[$side],
                $winner === null ? 'notify.duel_draw' : ($winner === $side ? 'notify.duel_won' : 'notify.duel_lost'),
                ['name' => $fighters[1 - $side]->name, 'zen' => $duel->zen],
                '/pvp/' . $duel->id,
            );
        }

        return self::OUTCOME_FINISHED;
    }
}
