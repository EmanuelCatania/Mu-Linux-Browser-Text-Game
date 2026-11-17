<?php

declare(strict_types=1);

namespace Mu\Domain\Npc;

use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;
use Mu\Support\Rng;

/** Lahap, Cherry Blossom Spirit, Goblin Osbourne y Goblin Jerridon (port de function.npc32_transform.php). */
final class SpecialNpcs
{
    private const string BLESS = 'D.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless';
    private const string SOUL = 'D.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul';
    private const string CHAOS = 'E.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_chaos';
    private const string BLESS_PACK = 'E.11.4.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_bless_x_10';
    private const string SOUL_PACK = 'E.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_soul_x_10';
    private const string LOST_MAP = 'K.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.lost_map';
    private const string LOWER_STONE = 'D.11.5.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.lower_refining_stone';
    private const string HIGHER_STONE = 'D.11.6.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.higher_refining_stone';

    /** Niveles de Kalima que existen. */
    public const int KALIMA_LEVELS = 7;

    public function __construct(private readonly BoxOpener $boxes)
    {
    }

    /**
     * Lahap: arma y desarma paquetes de 10 joyas, junta 5 Symbol of Kundun en un Lost Map y abre cajas.
     *
     * @param list<Item> $items
     */
    public function lahap(array $items, Rng $rng): MixResult
    {
        if (count($items) === 1) {
            $item = $items[0];
            $table = BoxOpener::tableFor($item);
            if ($table !== null) {
                $prize = $this->boxes->open($table, $rng);

                // una de cada diez cajas viene vacía
                return $rng->int(0, 9) < 1 ? MixResult::failure() : MixResult::success($prize);
            }
            if ($item->type === 'E' && ($item->subType === '4' || $item->subType === '5')) {
                $jewel = Item::parse($item->subType === '4' ? self::BLESS : self::SOUL);

                return MixResult::success(...array_fill(0, 10, $jewel));
            }
        }

        if (count($items) === 10) {
            foreach (['1' => self::BLESS_PACK, '2' => self::SOUL_PACK] as $subType => $pack) {
                if (self::all($items, static fn (Item $i): bool => $i->type === 'D' && $i->subType === (string) $subType)) {
                    return MixResult::success(Item::parse($pack));
                }
            }
        }

        if (count($items) === 5 && self::all($items, static fn (Item $i): bool => $i->type === 'I' && $i->subType === 'A')) {
            $level = $items[0]->level;
            if ($level >= 1 && $level <= self::KALIMA_LEVELS && self::all($items, static fn (Item $i): bool => $i->level === $level)) {
                return MixResult::success(Item::parse(self::LOST_MAP)->with(['level' => $level]));
            }
        }

        throw new InventoryException('npc.error_recipe');
    }

    /**
     * Cherry Blossom Spirit: cinco ramas del mismo color por una joya.
     *
     * @param list<Item> $items
     */
    public function cherryBlossom(array $items): MixResult
    {
        if (count($items) === 5) {
            foreach (['1' => self::CHAOS, '2' => self::BLESS, '3' => self::SOUL] as $subType => $reward) {
                if (self::all($items, static fn (Item $i): bool => $i->type === 'I' && $i->subType === (string) $subType)) {
                    return MixResult::success(Item::parse($reward));
                }
            }
        }

        throw new InventoryException('npc.error_recipe');
    }

    /**
     * Goblin Osbourne: refina un arma o escudo en una Refining Stone. El ítem se pierde, salga o no.
     *
     * @param list<Item> $items
     */
    public function osbourne(array $items, Rng $rng): MixResult
    {
        if (count($items) !== 1 || !in_array($items[0]->type, ['1', '2', '9'], true)) {
            throw new InventoryException('npc.error_recipe');
        }
        $item = $items[0];

        if ($item->isExcellent()) {
            return $rng->int(0, 1) === 1 ? MixResult::success(Item::parse(self::HIGHER_STONE)) : MixResult::failure();
        }
        if ($item->option !== 0 && $item->level > 3) {
            return $rng->int(0, 4) === 1 ? MixResult::success(Item::parse(self::LOWER_STONE)) : MixResult::failure();
        }

        throw new InventoryException('npc.error_recipe');
    }

    /**
     * Goblin Jerridon: quita la opción harmony de un ítem.
     *
     * @param list<Item> $items
     */
    public function jerridon(array $items): MixResult
    {
        if (count($items) !== 1 || $items[0]->harmonyType === 0) {
            throw new InventoryException('npc.error_recipe');
        }

        return MixResult::success($items[0]->with(['harmonyType' => 0, 'harmonyValue' => 0]));
    }

    /**
     * @param list<Item>           $items
     * @param callable(Item): bool $test
     */
    private static function all(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if (!$test($item)) {
                return false;
            }
        }

        return true;
    }
}
