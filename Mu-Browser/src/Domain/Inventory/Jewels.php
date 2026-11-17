<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

use Mu\Domain\Item\Item;
use Mu\Support\Rng;

/**
 * Mejora de ítems con joyas (port de function.jewel_drop.php). Subtipos de joya:
 * 1 Bless, 2 Soul, 3 Life, 4 Harmony, 5 Lower Refining Stone, 6 Higher Refining Stone.
 *
 * A diferencia del original, si la joya no puede hacer nada sobre ese ítem (por ejemplo Bless sobre un +6)
 * se rechaza y no se gasta.
 */
final class Jewels
{
    private const array UPGRADABLE = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];

    /**
     * @return array{?Item, string} el ítem resultante (null si quedó igual) y el resultado: ok, degraded o nothing
     *
     * @throws InventoryException si esa joya no se puede aplicar a ese ítem
     */
    public static function apply(Item $jewel, Item $target, Rng $rng): array
    {
        if ($jewel->type !== 'D' || !in_array($target->type, self::UPGRADABLE, true)) {
            throw new InventoryException('inventory.error_jewel_target');
        }

        return match ($jewel->subType) {
            '1' => self::bless($target),
            '2' => self::soul($target, $rng),
            '3' => self::life($target, $rng),
            '4' => self::harmony($target, $rng),
            '5' => self::refine($target, $rng->int(0, 1) === 1),
            '6' => self::refine($target, $rng->int(0, 3) > 0),
            default => throw new InventoryException('inventory.error_jewel_target'),
        };
    }

    /** Bless: sube un nivel, siempre, hasta +6. */
    private static function bless(Item $target): array
    {
        if ($target->level >= 6) {
            throw new InventoryException('inventory.error_jewel_useless');
        }

        return [$target->with(['level' => $target->level + 1]), 'ok'];
    }

    /** Soul: sube un nivel hasta +9 con 50% de éxito (75% con luck); si falla, el ítem baja. */
    private static function soul(Item $target, Rng $rng): array
    {
        if ($target->level >= 9) {
            throw new InventoryException('inventory.error_jewel_useless');
        }

        $success = $target->luck === 1 ? $rng->int(0, 3) !== 1 : $rng->int(0, 1) === 1;
        if ($success) {
            return [$target->with(['level' => $target->level + 1]), 'ok'];
        }

        // De +1 a +6 pierde un nivel; de +7 en adelante vuelve a +0.
        $level = $target->level > 6 ? 0 : max(0, $target->level - 1);

        return [$target->with(['level' => $level]), 'degraded'];
    }

    /** Life: sube la opción adicional hasta 4 con 50% de éxito; si falla, baja. */
    private static function life(Item $target, Rng $rng): array
    {
        if ($target->option >= 4) {
            throw new InventoryException('inventory.error_jewel_useless');
        }
        if ($rng->int(0, 1) === 1) {
            return [$target->with(['option' => $target->option + 1]), 'ok'];
        }

        return [$target->with(['option' => max(0, $target->option - 1)]), 'degraded'];
    }

    /** Harmony: da una opción harmony al azar a un ítem que no tenga, con 50% de éxito. */
    private static function harmony(Item $target, Rng $rng): array
    {
        if ($target->harmonyType !== 0) {
            throw new InventoryException('inventory.error_jewel_useless');
        }
        if ($rng->int(0, 1) !== 1) {
            return [null, 'nothing'];
        }

        return [$target->with(['harmonyType' => $rng->int(1, 6), 'harmonyValue' => $rng->int(1, 2)]), 'ok'];
    }

    /** Refining Stone: sube el valor de la opción harmony hasta 5; si falla, baja (mínimo 1). */
    private static function refine(Item $target, bool $success): array
    {
        if ($target->harmonyType === 0 || $target->harmonyValue >= 5) {
            throw new InventoryException('inventory.error_jewel_useless');
        }
        if ($success) {
            return [$target->with(['harmonyValue' => $target->harmonyValue + 1]), 'ok'];
        }

        return [$target->with(['harmonyValue' => max(1, $target->harmonyValue - 1)]), 'degraded'];
    }
}
