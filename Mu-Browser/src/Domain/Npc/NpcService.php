<?php

declare(strict_types=1);

namespace Mu\Domain\Npc;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Inventory\StoredItem;
use Mu\Support\Clock;
use Mu\Support\Rng;

/**
 * Combinar ítems en la Chaos Machine y en los NPC especiales. El jugador pone los ingredientes en la mesa
 * de combinación (Container::Mix), paga, y el resultado queda en esa misma mesa para que lo retire.
 */
final class NpcService
{
    public const int PRICE = 1000;

    /** NPC en el orden del menú. */
    public const array NPCS = [
        'chaos_machine' => 'Chaos Machine',
        'lahap' => 'Lahap',
        'cblossom' => 'Cherry Blossom Spirit',
        'osbourne' => 'Goblin Osbourne',
        'jerridon' => 'Goblin Jerridon',
    ];

    public function __construct(
        private readonly SpecialNpcs $npcs,
        private readonly ItemRepository $items,
        private readonly AccountRepository $accounts,
        private readonly BattleRepository $battles,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly Rng $rng,
    ) {
    }

    /** @throws InventoryException si no hay receta, zen o no se puede ahora (no se gasta nada) */
    public function mix(Account $account, Character $character, string $npc): MixResult
    {
        if (!isset(self::NPCS[$npc])) {
            throw new InventoryException('npc.error_recipe');
        }

        return $this->transaction->run($account->id, function () use ($account, $character, $npc): MixResult {
            if ($this->battles->find($character->id) !== null) {
                throw new InventoryException('inventory.error_in_battle');
            }

            $table = $this->items->stored(Container::Mix, $account->id);
            if ($table === []) {
                throw new InventoryException('npc.error_empty');
            }
            $ingredients = array_values(array_map(static fn (StoredItem $s) => $s->item, $table));

            // Si la receta no existe se lanza una excepción y la transacción deja todo como estaba.
            $result = match ($npc) {
                'chaos_machine' => ChaosMachine::mix($ingredients, $this->rng),
                'lahap' => $this->npcs->lahap($ingredients, $this->rng),
                'cblossom' => $this->npcs->cherryBlossom($ingredients),
                'osbourne' => $this->npcs->osbourne($ingredients, $this->rng),
                'jerridon' => $this->npcs->jerridon($ingredients),
            };

            if (!$this->accounts->spendZen($account->id, self::PRICE)) {
                throw new InventoryException('shop.error_no_money');
            }

            foreach ($table as $stored) {
                $this->items->delete($stored->id);
            }
            foreach ($result->items as $item) {
                if ($this->items->addFirstFree(Container::Mix, $account->id, $item, $this->clock->now()) === null) {
                    throw new \LogicException('El resultado no entra en la mesa de combinación.');
                }
            }

            return $result;
        });
    }
}
