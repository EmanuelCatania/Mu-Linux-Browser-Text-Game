<?php

declare(strict_types=1);

namespace Mu\Domain\Quest;

use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Support\Clock;

/** Misiones del Shadow Phantom Soldier (port de quest.php). También cura gratis, como en el original. */
final class QuestService
{
    public function __construct(
        private readonly QuestCatalog $quests,
        private readonly CharacterRepository $characters,
        private readonly ItemRepository $items,
        private readonly BattleRepository $battles,
        private readonly StatsCalculator $calculator,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    public function current(Character $character): ?Quest
    {
        return $character->questId === null ? null : $this->quests->get($character->questId);
    }

    /** @return list<Quest> las que puede aceptar ahora: de su nivel y que no haya hecho */
    public function available(Character $character): array
    {
        $done = $this->characters->questsDone($character->id);

        return array_values(array_filter(
            $this->quests->all(),
            static fn (Quest $q): bool => $q->isFor($character->level) && !in_array($q->id, $done, true),
        ));
    }

    /** Acepta una misión; si tenía otra a medias, la reemplaza y pierde el progreso. @throws InventoryException */
    public function accept(Account $account, Character $character, int $questId): void
    {
        $this->transaction->run($account->id, function () use ($character, $questId): void {
            $this->assertNotInBattle($character);
            $ids = array_map(static fn (Quest $q): int => $q->id, $this->available($character));
            if (!in_array($questId, $ids, true)) {
                throw new InventoryException('quest.error_unavailable');
            }
            $this->characters->setQuest($character->id, $questId);
        });
    }

    public function giveUp(Account $account, Character $character): void
    {
        $this->transaction->run($account->id, function () use ($character): void {
            $this->assertNotInBattle($character);
            $this->characters->setQuest($character->id, null);
        });
    }

    /**
     * Cobra el premio de la misión terminada.
     *
     * @return 'level'|'item'
     *
     * @throws InventoryException
     */
    public function claim(Account $account, Character $character): string
    {
        return $this->transaction->run($account->id, function () use ($character): string {
            $this->assertNotInBattle($character);
            $quest = $this->current($character) ?? throw new InventoryException('quest.error_none');
            if ($character->questScore < $quest->amount) {
                throw new InventoryException('quest.error_unfinished');
            }

            if ($quest->reward === null && $character->level >= Battle::MAX_LEVEL) {
                throw new InventoryException('quest.error_max_level');
            }
            if ($quest->reward === null) {
                // Sube un nivel y arranca el siguiente desde cero, como en el original.
                $this->characters->saveProgress(
                    $character->id,
                    $character->level + 1,
                    5,
                    Battle::experienceFor($character->level),
                    $character->hpCur,
                    $character->mpCur,
                    0,
                );
                $kind = 'level';
            } else {
                if ($this->items->addToInventory($character->id, $quest->reward, $this->clock->now()) === null) {
                    throw new InventoryException('inventory.error_no_space');
                }
                $kind = 'item';
            }

            $this->characters->completeQuest($character->id, $quest->id);

            return $kind;
        });
    }

    /** El soldado restaura toda la vida y el maná. @throws InventoryException */
    public function restore(Account $account, Character $character): void
    {
        $this->transaction->run($account->id, function () use ($character): void {
            $this->assertNotInBattle($character);
            $stats = $this->calculator->calculate($character, $this->items->items(Container::Equipment, $character->id));
            $this->characters->setVitals($character->id, $stats->hpMax, $stats->mpMax);
        });
    }

    private function assertNotInBattle(Character $character): void
    {
        if ($this->battles->find($character->id) !== null) {
            throw new InventoryException('inventory.error_in_battle');
        }
    }
}
