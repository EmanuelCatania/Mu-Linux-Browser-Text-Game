<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

use RuntimeException;

/** Movimiento, uso, compra o venta que no se puede hacer. El mensaje es una clave de traducción. */
final class InventoryException extends RuntimeException
{
}
