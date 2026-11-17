<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Inventory\StoredItem;
use Mu\Domain\Item\Item;
use Mu\Support\Clock;
use PDO;
use PDOException;

/**
 * Ítems de los jugadores. $ownerId es el id del personaje, salvo en los contenedores de la cuenta
 * (el baúl), donde es el id de la cuenta: ver Container::ownedByAccount().
 */
final class ItemRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<int, StoredItem> por slot */
    public function stored(Container $container, int $ownerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, slot, code, price FROM items WHERE ' . self::ownerColumn($container) . ' = ? AND container = ? ORDER BY slot'
        );
        $stmt->execute([$ownerId, $container->value]);

        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[(int) $row['slot']] = new StoredItem(
                (int) $row['id'],
                $container,
                (int) $row['slot'],
                Item::parse($row['code']),
                $row['price'] === null ? null : (int) $row['price'],
            );
        }

        return $items;
    }

    /** @return array<int, Item> por slot */
    public function items(Container $container, int $ownerId): array
    {
        return array_map(static fn (StoredItem $s): Item => $s->item, $this->stored($container, $ownerId));
    }

    public function find(Container $container, int $ownerId, int $slot): ?StoredItem
    {
        return $this->stored($container, $ownerId)[$slot] ?? null;
    }

    /**
     * Guarda el ítem en el primer lugar libre de una grilla.
     *
     * @return ?int la celda donde quedó, o null si no hay lugar
     */
    public function addFirstFree(Container $container, int $ownerId, Item $item, DateTimeImmutable $now): ?int
    {
        // Dos pedidos simultáneos pueden elegir la misma celda: el índice único rechaza al segundo y se reintenta.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $slot = Grid::findSpace(self::placed($this->stored($container, $ownerId)), $item->width, $item->height, $container->rows());
            if ($slot === null) {
                return null;
            }

            try {
                $this->pdo->prepare(
                    'INSERT INTO items (' . self::ownerColumn($container) . ', container, slot, code, created_at) VALUES (?, ?, ?, ?, ?)'
                )->execute([$ownerId, $container->value, $slot, $item->toCode(), $now->format(Clock::SQL_FORMAT)]);

                return $slot;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return null;
    }

    /** Pone un ítem en un lugar exacto, sin validar reglas de juego. Falla si ese slot ya está ocupado. */
    public function put(Container $container, int $ownerId, int $slot, Item $item, DateTimeImmutable $now): void
    {
        $this->pdo->prepare(
            'INSERT INTO items (' . self::ownerColumn($container) . ', container, slot, code, created_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$ownerId, $container->value, $slot, $item->toCode(), $now->format(Clock::SQL_FORMAT)]);
    }

    /** Atajo para lo más común: sumar un ítem al inventario de un personaje. */
    public function addToInventory(int $characterId, Item $item, DateTimeImmutable $now): ?int
    {
        return $this->addFirstFree(Container::Inventory, $characterId, $item, $now);
    }

    /** Cambia un ítem de lugar (y de dueño, si pasa del personaje al baúl de la cuenta o al revés). */
    public function relocate(int $id, Container $container, int $ownerId, int $slot): void
    {
        $byAccount = $container->ownedByAccount();
        // El precio sólo tiene sentido dentro de la tienda: al mover el ítem se borra.
        $this->pdo->prepare('UPDATE items SET character_id = ?, account_id = ?, container = ?, slot = ?, price = NULL WHERE id = ?')
            ->execute([$byAccount ? null : $ownerId, $byAccount ? $ownerId : null, $container->value, $slot, $id]);
    }

    public function setPrice(int $id, int $price): void
    {
        $this->pdo->prepare('UPDATE items SET price = ? WHERE id = ?')->execute([$price, $id]);
    }

    /**
     * Todo lo que está a la venta: ítems con precio en las tiendas personales abiertas.
     *
     * @return list<array{id: int, account_id: int, seller: string, slot: int, price: int, item: Item}> del más nuevo al más viejo
     */
    public function listings(): array
    {
        $rows = $this->pdo->query(
            "SELECT i.id, i.account_id, a.username, i.slot, i.price, i.code
               FROM items i JOIN accounts a ON a.id = i.account_id
              WHERE i.container = 'store' AND a.store_open = 1 AND i.price IS NOT NULL
              ORDER BY i.id DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'account_id' => (int) $r['account_id'],
            'seller' => $r['username'],
            'slot' => (int) $r['slot'],
            'price' => (int) $r['price'],
            'item' => Item::parse($r['code']),
        ], $rows);
    }

    public function updateCode(int $id, Item $item): void
    {
        $this->pdo->prepare('UPDATE items SET code = ? WHERE id = ?')->execute([$item->toCode(), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM items WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<int, StoredItem> $stored
     *
     * @return list<array{slot: int, width: int, height: int}> para Grid
     */
    public static function placed(array $stored, ?int $exceptId = null): array
    {
        $placed = [];
        foreach ($stored as $s) {
            if ($s->id !== $exceptId) {
                $placed[] = ['slot' => $s->slot, 'width' => $s->item->width, 'height' => $s->item->height];
            }
        }

        return $placed;
    }

    private static function ownerColumn(Container $container): string
    {
        return $container->ownedByAccount() ? 'account_id' : 'character_id';
    }
}
