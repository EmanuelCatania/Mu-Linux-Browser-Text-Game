<?php

declare(strict_types=1);

namespace Mu\Domain\Npc;

use Mu\Domain\Item\Item;
use Mu\Support\Rng;
use RuntimeException;

/** Qué sale de una caja (port de include.open_box.php). Las tablas están en data/drop/box_*.xml. */
final class BoxOpener
{
    private const array WEARABLE = ['1', '2', '3', '4', '5', '6', '7', '9'];

    public function __construct(private readonly string $dir)
    {
    }

    /** Tabla que corresponde a una caja, o null si el ítem no es una caja que se pueda abrir. */
    public static function tableFor(Item $box): ?string
    {
        if ($box->type !== 'H') {
            return null;
        }

        return match (true) {
            $box->subType === '3' => 'cherry_blossom_box',
            $box->subType === '1' => 'box_of_luck',
            $box->subType === '2' && $box->level === 1 => 'box_of_kundun1',
            $box->subType === '2' && $box->level === 2 => 'box_of_kundun2',
            default => null,
        };
    }

    public function open(string $table, Rng $rng): Item
    {
        $xml = simplexml_load_file($this->dir . '/' . $table . '.xml');
        if ($xml === false || count($xml->item) === 0) {
            throw new RuntimeException("No se pudo leer la tabla de la caja $table.");
        }
        $codes = [];
        foreach ($xml->item as $node) {
            $codes[] = trim((string) $node);
        }
        $item = Item::parse($codes[$rng->int(0, count($codes) - 1)]);

        $wearable = in_array($item->type, self::WEARABLE, true);
        $jewelry = $item->type === 'A' || $item->type === 'B';
        $changes = [];

        if ($wearable || $jewelry) {
            // En las tablas, excellent "01" significa "excellent con una opción al azar".
            $wasExcellent = $item->excellent > 0;
            if ($item->excellent === 1) {
                $changes['excellent'] = $rng->int(1, 6);
            }

            $level = $item->level;
            if ($rng->int(0, 2) < 1) {
                $level++;
            } elseif ($rng->int(0, 9) < 1) {
                $level += 2;
            }
            $changes['level'] = $wasExcellent ? 0 : $level; // los excellent siempre salen +0

            $option = 0;
            if ($wearable) {
                if ($rng->int(0, 4) < 1) {
                    $option = 1;
                } elseif ($rng->int(0, 19) < 1) {
                    $option = 2;
                }
            } else {
                if ($rng->int(0, 2) < 1) {
                    $option = 1;
                } elseif ($rng->int(0, 4) < 1) {
                    $option = 2;
                } elseif ($rng->int(0, 9) < 1) {
                    $option = 3;
                } elseif ($rng->int(0, 49) < 1) {
                    $option = 4;
                }
            }
            $changes['option'] = $option;
        }

        // Las armas y escudos de caja siempre traen su habilidad.
        if (in_array($item->type, ['1', '2', '9'], true)) {
            $weapon = $item->type !== '9';
            $changes['skill'] = match (true) {
                $weapon && $item->subType === '1' => (string) $rng->int(3, 6),
                $weapon && $item->subType === '2' => '7',
                $item->type === '2' && in_array($item->subType, ['8', '9'], true) => '9',
                $item->type === '9' => '8',
                $item->type === '1' && $item->subType === '5' && $item->effect === 23 => 'A',
                $item->type === '1' && $item->subType === '5' && $item->effect === 29 => 'B',
                $item->type === '1' && $item->subType === '5' && $item->effect === 36 => 'C',
                default => '0',
            };
        }

        if ($wearable && $rng->int(0, 2) < 1) {
            $changes['luck'] = 1;
        }

        return $item->with($changes);
    }
}
