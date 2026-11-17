<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

use Mu\Domain\Item\Item;

/** Un ítem guardado: su fila en la tabla items y dónde está. */
final readonly class StoredItem
{
    public function __construct(
        public int $id,
        public Container $container,
        public int $slot,
        public Item $item,
        /** Precio pedido, sólo para ítems en la tienda personal. */
        public ?int $price = null,
    ) {
    }
}
