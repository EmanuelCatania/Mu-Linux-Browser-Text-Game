<?php

declare(strict_types=1);

namespace Mu\Domain\Shop;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;
use Mu\Support\Clock;

/** Comprar y vender en las tiendas NPC (port de function.buy_item.php y function.sell_item.php). */
final class ShopService
{
    public function __construct(
        private readonly ShopCatalog $shops,
        private readonly ItemRepository $items,
        private readonly AccountRepository $accounts,
        private readonly BattleRepository $battles,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
    ) {
    }

    /** @throws InventoryException */
    public function buy(Account $account, Character $character, string $shopId, int $slot): Item
    {
        $item = $this->shops->stock($shopId)[$slot] ?? throw new InventoryException('shop.error_no_item');

        return $this->transaction->run($account->id, function () use ($account, $character, $item): Item {
            $this->assertNotInBattle($character);

            // El zen se descuenta sólo si alcanza, en la misma sentencia: no se puede quedar en negativo.
            if (!$this->accounts->spendZen($account->id, $item->buyPrice())) {
                throw new InventoryException('shop.error_no_money');
            }
            if ($this->items->addToInventory($character->id, $item, $this->clock->now()) === null) {
                throw new InventoryException('inventory.error_no_space'); // la transacción devuelve el zen
            }

            return $item;
        });
    }

    /**
     * Vende un ítem del inventario o del equipo. La tienda paga la mitad del precio de compra.
     *
     * @return int zen recibido
     *
     * @throws InventoryException
     */
    public function sell(Account $account, Character $character, Container $from, int $slot): int
    {
        if ($from !== Container::Inventory && $from !== Container::Equipment) {
            throw new InventoryException('inventory.error_no_item');
        }

        return $this->transaction->run($account->id, function () use ($account, $character, $from, $slot): int {
            $this->assertNotInBattle($character);
            $stored = $this->items->find($from, $character->id, $slot) ?? throw new InventoryException('inventory.error_no_item');

            $price = $stored->item->sellPrice();
            $this->items->delete($stored->id);
            $this->accounts->addZen($account->id, $price);

            return $price;
        });
    }

    private function assertNotInBattle(Character $character): void
    {
        if ($this->battles->find($character->id) !== null) {
            throw new InventoryException('inventory.error_in_battle');
        }
    }
}
