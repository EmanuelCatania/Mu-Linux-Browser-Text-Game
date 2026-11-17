<?php

declare(strict_types=1);

namespace Mu\Domain\Market;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\ItemRepository;
use Mu\Data\NotificationRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;

/**
 * Tienda personal y mercado (port de function.pstore.php y function.market.php).
 * Cada cuenta tiene una tienda de 8x4 donde pone ítems con precio. Mientras está cerrada puede cambiarla;
 * al abrirla (cuesta zen) sus ítems aparecen en el mercado y cualquiera puede comprarlos.
 */
final class StoreService
{
    public const int OPEN_PRICE = 1000;
    public const int MAX_PRICE = 99_999_999;

    /** Filtro por tipo → tipos de ítem. */
    public const array TYPE_FILTERS = [
        'weapon' => ['1', '2'], 'armor' => ['3'], 'pants' => ['4'], 'helm' => ['5'], 'gloves' => ['6'], 'boots' => ['7'],
        'wings' => ['8'], 'shield' => ['9'], 'jewelry' => ['A', 'B'], 'pet' => ['C'], 'jewel' => ['D', 'E'],
        'skill' => ['G'], 'other' => ['F', 'H', 'I', 'J', 'K'],
    ];
    public const array ORDERS = ['newest', 'name', 'excellent', 'price'];

    public function __construct(
        private readonly ItemRepository $items,
        private readonly AccountRepository $accounts,
        private readonly BattleRepository $battles,
        private readonly Transaction $transaction,
        private readonly ?NotificationRepository $notifications = null,
    ) {
    }

    /** Pone en la tienda un ítem del inventario o del equipo, con su precio. @throws InventoryException */
    public function add(Account $account, Character $character, Container $from, int $slot, int $price): void
    {
        if ($from !== Container::Inventory && $from !== Container::Equipment) {
            throw new InventoryException('inventory.error_no_item');
        }
        if ($price < 1 || $price > self::MAX_PRICE) {
            throw new InventoryException('store.error_price');
        }

        $this->transaction->run($account->id, function () use ($account, $character, $from, $slot, $price): void {
            $this->assertCanEdit($account->id, $character);
            $stored = $this->items->find($from, $character->id, $slot) ?? throw new InventoryException('inventory.error_no_item');

            $free = Grid::findSpace(
                ItemRepository::placed($this->items->stored(Container::Store, $account->id)),
                $stored->item->width,
                $stored->item->height,
                Container::Store->rows(),
            ) ?? throw new InventoryException('inventory.error_no_space');

            $this->items->relocate($stored->id, Container::Store, $account->id, $free);
            $this->items->setPrice($stored->id, $price);
        });
    }

    /** Cambia de lugar un ítem dentro de la tienda propia (sólo con la tienda cerrada). @throws InventoryException */
    public function move(Account $account, Character $character, int $slot, int $toSlot): void
    {
        $this->transaction->run($account->id, function () use ($account, $character, $slot, $toSlot): void {
            $this->assertCanEdit($account->id, $character);
            $all = $this->items->stored(Container::Store, $account->id);
            $stored = $this->items->find(Container::Store, $account->id, $slot) ?? throw new InventoryException('inventory.error_no_item');
            // el propio ítem no cuenta como obstáculo: puede correrse una celda sobre sí mismo
            $others = array_values(array_filter($all, static fn ($s): bool => $s->id !== $stored->id));
            if (!Grid::fits(ItemRepository::placed($others), $toSlot, $stored->item->width, $stored->item->height, Container::Store->rows())) {
                throw new InventoryException('inventory.error_no_space');
            }
            $this->items->relocate($stored->id, Container::Store, $account->id, $toSlot);
        });
    }

    /** Devuelve al inventario un ítem de la tienda propia. @throws InventoryException */
    public function remove(Account $account, Character $character, int $slot): void
    {
        $this->transaction->run($account->id, function () use ($account, $character, $slot): void {
            $this->assertCanEdit($account->id, $character);
            $stored = $this->items->find(Container::Store, $account->id, $slot) ?? throw new InventoryException('inventory.error_no_item');

            $free = Grid::findSpace(
                ItemRepository::placed($this->items->stored(Container::Inventory, $character->id)),
                $stored->item->width,
                $stored->item->height,
                Container::Inventory->rows(),
            ) ?? throw new InventoryException('inventory.error_no_space');
            $this->items->relocate($stored->id, Container::Inventory, $character->id, $free);
        });
    }

    /** Abre la tienda: sus ítems pasan a verse en el mercado. @throws InventoryException */
    public function open(Account $account): void
    {
        $this->transaction->run($account->id, function () use ($account): void {
            if ($this->accounts->findById($account->id)?->storeOpen) {
                throw new InventoryException('store.error_already_open');
            }
            if ($this->items->stored(Container::Store, $account->id) === []) {
                throw new InventoryException('store.error_empty');
            }
            if (!$this->accounts->spendZen($account->id, self::OPEN_PRICE)) {
                throw new InventoryException('shop.error_no_money');
            }
            $this->accounts->setStoreOpen($account->id, true);
        });
    }

    public function close(Account $account): void
    {
        $this->transaction->run($account->id, fn () => $this->accounts->setStoreOpen($account->id, false));
    }

    /**
     * Lo que hay a la venta, filtrado y ordenado.
     *
     * @param ?string         $type  clave de TYPE_FILTERS, o null para todo
     * @param ?CharacterClass $class sólo lo que esa clase puede usar
     *
     * @return list<array{id: int, account_id: int, seller: string, slot: int, price: int, item: Item}>
     */
    public function listings(?string $type = null, ?CharacterClass $class = null, string $order = 'newest'): array
    {
        $types = $type === null ? null : (self::TYPE_FILTERS[$type] ?? []);
        $rows = array_values(array_filter(
            $this->items->listings(),
            static fn (array $r): bool => ($types === null || in_array($r['item']->type, $types, true))
                && ($class === null || ($r['item']->allowedClasses() !== [] && $r['item']->canBeUsedBy($class))),
        ));

        usort($rows, match ($order) {
            'name' => static fn (array $a, array $b): int => [$a['item']->name, $a['price']] <=> [$b['item']->name, $b['price']],
            'excellent' => static fn (array $a, array $b): int => [$b['item']->isExcellent(), $b['item']->level] <=> [$a['item']->isExcellent(), $a['item']->level],
            'price' => static fn (array $a, array $b): int => $a['price'] <=> $b['price'],
            default => static fn (array $a, array $b): int => $b['id'] <=> $a['id'],
        });

        return $rows;
    }

    /**
     * Compra un ítem del mercado: el zen pasa al vendedor y el ítem al inventario del comprador.
     *
     * @throws InventoryException
     */
    public function buy(Account $buyer, Character $character, int $itemId): Item
    {
        $listing = $this->findListing($itemId) ?? throw new InventoryException('market.error_gone');
        if ($listing['account_id'] === $buyer->id) {
            throw new InventoryException('market.error_own');
        }

        return $this->transaction->runMany([$buyer->id, $listing['account_id']], function () use ($buyer, $character, $itemId): Item {
            if ($this->battles->find($character->id) !== null) {
                throw new InventoryException('inventory.error_in_battle');
            }
            // Se vuelve a leer con las cuentas bloqueadas: puede haberse vendido o cambiado recién.
            $listing = $this->findListing($itemId) ?? throw new InventoryException('market.error_gone');
            $item = $listing['item'];

            if (!$this->accounts->spendZen($buyer->id, $listing['price'])) {
                throw new InventoryException('shop.error_no_money');
            }
            $free = Grid::findSpace(
                ItemRepository::placed($this->items->stored(Container::Inventory, $character->id)),
                $item->width,
                $item->height,
                Container::Inventory->rows(),
            ) ?? throw new InventoryException('inventory.error_no_space');

            $this->items->relocate($listing['id'], Container::Inventory, $character->id, $free);
            $this->accounts->addZen($listing['account_id'], $listing['price']);
            $this->notifications?->notify($listing['account_id'], 'notify.market_sold', ['item' => $item->displayName(), 'zen' => $listing['price'], 'name' => $character->name], '/store');

            // Sin nada más que vender, la tienda se cierra sola.
            if ($this->items->stored(Container::Store, $listing['account_id']) === []) {
                $this->accounts->setStoreOpen($listing['account_id'], false);
            }

            return $item;
        });
    }

    /** @return array{id: int, account_id: int, seller: string, slot: int, price: int, item: Item}|null */
    private function findListing(int $itemId): ?array
    {
        foreach ($this->items->listings() as $listing) {
            if ($listing['id'] === $itemId) {
                return $listing;
            }
        }

        return null;
    }

    /** La tienda sólo se modifica cerrada, y nunca con una batalla abierta. */
    private function assertCanEdit(int $accountId, Character $character): void
    {
        if ($this->battles->find($character->id) !== null) {
            throw new InventoryException('inventory.error_in_battle');
        }
        if ($this->accounts->findById($accountId)?->storeOpen) {
            throw new InventoryException('store.error_open');
        }
    }
}
