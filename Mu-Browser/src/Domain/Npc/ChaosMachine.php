<?php

declare(strict_types=1);

namespace Mu\Domain\Npc;

use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;
use Mu\Support\Rng;

/**
 * Recetas de la Chaos Machine (port de la sección chaos_machine de function.npc32_transform.php).
 *
 *   Jewel of Creation + Jewel of Chaos                         → una fruta (80%)
 *   Blood Bone + Scroll of Archangel del mismo nivel + Chaos   → Cloak of Invisibility (80%)
 *   Devil's Eye + Devil's Key + Chaos                          → Devil's Invitation (80%)
 *   10 Horn of Uniria + Chaos                                  → Horn of Dinorant (80%)
 *   ítem +9 a +12 + Chaos + 1 a 4 Bless y Soul                 → sube un nivel (50%, 75% con luck); si falla vuelve a +0
 *   ítems +4 a +8 con opción + Chaos (+ Bless/Soul)            → arma Chaos
 *   arma Chaos +4 a +8 con opción + Chaos (+ ítems)            → alas de 1.er nivel
 *   alas de 1.er nivel + Loch Feather + Chaos (+ excellent)    → alas de 2.º nivel
 *   alas de 2.º nivel + Condor Feather + Condor Flame + Chaos  → alas de 3.er nivel de la misma clase
 */
final class ChaosMachine
{
    private const array UPGRADABLE = ['1', '2', '3', '4', '5', '6', '7', '9'];

    private const array FRUITS = [
        'J.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.strength_fruit',
        'J.11.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.agility_fruit',
        'J.11.3.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.stamina_fruit',
        'J.11.4.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.energy_fruit',
    ];
    private const string CLOAK = 'K.22.2.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.cloak_of_invisibility';
    private const string INVITATION = 'K.11.3.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.devil_square_invitation';
    private const string DINORANT = 'C.11.4.0000.00.0.4110.0000.00.2.0.00.00.0.255.255.000000.horn_of_dinorant';
    private const array CHAOS_WEAPONS = [
        1 => '2.24.2.0130.00.0.1335.2132.15.0.0.00.00.0.080.080.000000.chaos_dragon_axe',
        2 => '2.24.3.0048.00.0.1155.2042.14.0.0.00.00.0.070.070.000000.chaos_lighting_staff',
        3 => '2.24.8.0106.00.0.1110.2357.03.0.0.00.00.0.068.068.000000.chaos_nature_bow',
    ];
    private const array WINGS_1 = [
        1 => '8.42.1.0010.00.0.4180.0000.01.0.0.00.00.0.200.200.000000.satan_wings',
        2 => '8.42.1.0010.00.0.4180.0000.02.0.0.00.00.0.200.200.000000.heaven_wings',
        3 => '8.42.1.0010.00.0.4180.0000.03.0.0.00.00.0.200.200.000000.fairy_wings',
        4 => '8.42.1.0010.00.0.4180.0000.04.0.0.00.00.0.200.200.000000.mystery_wings',
    ];
    private const array WINGS_2 = [
        1 => '8.53.2.0030.00.0.4215.0000.21.0.0.00.00.0.220.220.000000.dragon_wings',
        2 => '8.53.2.0030.00.0.4215.0000.22.0.0.00.00.0.220.220.000000.archangel_wings',
        3 => '8.53.2.0030.00.0.4215.0000.23.0.0.00.00.0.220.220.000000.spirit_wings',
        4 => '8.53.2.0030.00.0.4215.0000.24.0.0.00.00.0.220.220.000000.despair_wings',
        5 => '8.53.2.0030.00.0.4215.0000.05.0.0.00.00.0.220.220.000000.darkness_wings',
        6 => '8.23.4.0015.00.0.4180.0000.06.0.0.00.00.0.200.200.000000.cape_of_lord',
    ];
    /** Clase requerida de las alas de 2.º nivel → alas de 3.er nivel que salen de ellas. */
    private const array WINGS_3 = [
        21 => '8.53.3.0060.00.0.4400.0000.31.0.0.00.00.0.240.240.000000.wing_of_storm',
        22 => '8.53.3.0045.00.0.4400.0000.32.0.0.00.00.0.240.240.000000.wing_of_eternal',
        23 => '8.53.3.0045.00.0.4400.0000.33.0.0.00.00.0.240.240.000000.wing_of_illusion',
        24 => '8.53.3.0042.00.0.4400.0000.34.0.0.00.00.0.240.240.000000.wing_of_dimension',
        5 => '8.53.3.0055.00.0.4400.0000.35.0.0.00.00.0.240.240.000000.wing_of_ruin',
        6 => '8.23.5.0045.00.0.4400.0000.36.0.0.00.00.0.240.240.000000.cape_of_emperor',
    ];
    private const int MAX_RATE = 90;

    /**
     * @param list<Item> $items lo que el jugador puso en la máquina
     *
     * @throws InventoryException si esos ítems no forman ninguna receta (no se gasta nada)
     */
    public static function mix(array $items, Rng $rng): MixResult
    {
        $n = ['chaos' => 0, 'creation' => 0, 'bless' => 0, 'soul' => 0, 'uniria' => 0, 'bone' => 0, 'archangel' => 0, 'loch' => 0, 'feather' => 0, 'flame' => 0, 'eye' => 0, 'key' => 0];
        $wings = [];        // alas de 1.er nivel
        $wings2 = [];       // alas de 2.º nivel, sólo cuando van con materiales de Condor
        $condor = array_filter($items, static fn (Item $i): bool => $i->type === 'E' && ($i->subType === '8' || $i->subType === '9')) !== [];
        $high = [];         // ítems +9 o más
        $regular = [];      // +4 a +8 con opción, no excellent
        $chaosWeapons = []; // armas Chaos entre los anteriores
        $rate = 0;          // aporte de los ítems comunes a la probabilidad
        $excellentRate = 0; // aporte de los ítems excellent (sólo para alas de 2.º nivel)
        $excellentCount = 0;
        $eventLevels = ['bone' => null, 'archangel' => null];

        foreach ($items as $item) {
            $key = match (true) {
                $item->type === 'E' && $item->subType === '1' => 'chaos',
                $item->type === 'E' && $item->subType === '2' => 'creation',
                $item->type === 'D' && $item->subType === '1' => 'bless',
                $item->type === 'D' && $item->subType === '2' => 'soul',
                $item->type === 'C' && $item->subType === '3' => 'uniria',
                $item->type === 'I' && $item->subType === '4' => 'bone',
                $item->type === 'I' && $item->subType === '5' => 'archangel',
                $item->type === 'I' && $item->subType === '6' => 'eye',
                $item->type === 'I' && $item->subType === '7' => 'key',
                $item->type === 'E' && $item->subType === '7' => 'loch',
                $item->type === 'E' && $item->subType === '8' => 'feather',
                $item->type === 'E' && $item->subType === '9' => 'flame',
                default => null,
            };
            $inRange = $item->level > 3 && $item->level < 9;

            if ($item->type === '8' && $item->subType === '1') {
                $wings[] = $item;
            } elseif ($condor && $item->type === '8' && ($item->subType === '2' || $item->subType === '4')) {
                $wings2[] = $item;
            } elseif ($key !== null) {
                $n[$key]++;
                if (array_key_exists($key, $eventLevels)) {
                    $eventLevels[$key] = $item->level;
                }
            } elseif (in_array($item->type, [...self::UPGRADABLE, '8'], true) && $item->level > 8) {
                $high[] = $item;
            } elseif (in_array($item->type, self::UPGRADABLE, true) && $inRange && $item->isExcellent()) {
                $excellentCount++;
                $excellentRate += (int) floor($item->effect / 10 * ($item->level + 3));
            } elseif (in_array($item->type, self::UPGRADABLE, true) && $inRange && $item->option > 0) {
                $regular[] = $item;
                $rate += (int) floor($item->effect / 10 * $item->level);
                if (str_starts_with($item->name, 'chaos_')) {
                    $chaosWeapons[] = $item;
                }
            } else {
                throw new InventoryException('npc.error_recipe');
            }
        }

        $count = count($items);
        $oneInFiveFails = static fn (): bool => $rng->int(0, 4) === 1;

        // frutas
        if ($n['creation'] === 1 && $n['chaos'] === 1 && $count === 2) {
            $fruit = Item::parse(self::FRUITS[$rng->int(0, 3)]);

            return $oneInFiveFails() ? MixResult::failure() : MixResult::success($fruit);
        }

        // Cloak of Invisibility (entrada a Blood Castle), del nivel de sus ingredientes
        if ($n['bone'] === 1 && $n['archangel'] === 1 && $n['chaos'] === 1 && $count === 3 && $eventLevels['bone'] === $eventLevels['archangel']) {
            $cloak = Item::parse(self::CLOAK)->with(['level' => (int) $eventLevels['archangel']]);

            return $oneInFiveFails() ? MixResult::failure() : MixResult::success($cloak);
        }

        // Devil's Invitation (entrada a Devil Square)
        if ($n['eye'] === 1 && $n['key'] === 1 && $n['chaos'] === 1 && $count === 3) {
            return $oneInFiveFails() ? MixResult::failure() : MixResult::success(Item::parse(self::INVITATION));
        }

        // Horn of Dinorant
        if ($n['uniria'] === 10 && $n['chaos'] === 1 && $count === 11) {
            return $oneInFiveFails() ? MixResult::failure() : MixResult::success(Item::parse(self::DINORANT));
        }

        // de +9 a +13: hace falta una Bless y una Soul por cada nivel por encima de +9
        if (count($high) === 1 && $n['chaos'] === 1) {
            $item = $high[0];
            $jewels = $item->level - 8;
            if ($item->level <= 12 && $n['bless'] === $jewels && $n['soul'] === $jewels && $count === 2 + $jewels * 2) {
                $failed = ($item->luck > 0 ? $rng->int(0, 3) : $rng->int(0, 1)) === 1;

                return $failed
                    ? MixResult::failure($item->with(['level' => 0]))
                    : MixResult::success($item->with(['level' => $item->level + 1]));
            }
        }

        $jewelRate = $n['bless'] * 4 + $n['soul'] * 3 + $n['chaos'] * 2;

        // arma Chaos
        if ($regular !== [] && $n['chaos'] > 0 && $high === [] && $wings === [] && $chaosWeapons === []) {
            $weapon = Item::parse(self::CHAOS_WEAPONS[$rng->int(1, 3)]);

            $level = match (true) {
                $rng->int(0, 1) < 1 => 1,
                $rng->int(0, 2) < 1 => 2,
                $rng->int(0, 4) < 1 => 3,
                $rng->int(0, 9) < 1 => 4,
                default => 0,
            };
            $option = match (true) {
                $rng->int(0, 1) < 1 => 1,
                $rng->int(0, 9) < 1 => 2,
                default => 0,
            };
            $weapon = $weapon->with(['level' => $level, 'option' => $option, 'luck' => $rng->int(0, 1)]);

            return $rng->int(1, 100) > min(self::MAX_RATE, $rate + $jewelRate)
                ? MixResult::failure($regular[0]->with(['level' => 0]))
                : MixResult::success($weapon);
        }

        // alas de 1.er nivel, a partir de un arma Chaos
        if ($chaosWeapons !== [] && $n['chaos'] > 0 && $wings === [] && $high === []) {
            $made = Item::parse(self::WINGS_1[$rng->int(1, 4)])->with(['option' => $rng->int(0, 2), 'luck' => $rng->int(0, 1)]);

            return $rng->int(1, 100) > min(self::MAX_RATE, $rate + $jewelRate)
                ? MixResult::failure($chaosWeapons[array_key_last($chaosWeapons)]->with(['level' => 0]))
                : MixResult::success($made);
        }

        // alas de 2.º nivel, a partir de unas de 1.º y una Loch Feather; los ítems excellent suben la probabilidad
        if (
            $n['loch'] === 1 && $n['chaos'] === 1 && count($wings) === 1 && $high === [] && $regular === []
            && $n['bless'] === 0 && $n['soul'] === 0
        ) {
            $made = Item::parse(self::WINGS_2[$rng->int(1, 6)])->with(['option' => $rng->int(0, 2), 'luck' => $rng->int(0, 1)]);
            if ($rng->int(1, 3) === 1) {
                $made = $made->with(['skill' => (string) $rng->int(1, 3)]); // opción propia de las alas
            }

            return $rng->int(1, 100) > min(self::MAX_RATE, 10 * ($wings[0]->level + 1) + $excellentRate + 2)
                ? MixResult::failure($wings[0]->with(['level' => 0]))
                : MixResult::success($made);
        }

        // alas de 3.er nivel: salen las de la misma clase que las de 2.º que se entregan
        if (
            $n['feather'] === 1 && $n['flame'] === 1 && $n['chaos'] === 1 && count($wings2) === 1 && $count === 4
            && isset(self::WINGS_3[$wings2[0]->requiredClass])
        ) {
            $made = Item::parse(self::WINGS_3[$wings2[0]->requiredClass])->with(['option' => $rng->int(0, 2), 'luck' => $rng->int(0, 1)]);

            return $rng->int(1, 100) > min(self::MAX_RATE, 40 + $wings2[0]->level * 5)
                ? MixResult::failure($wings2[0]->with(['level' => 0]))
                : MixResult::success($made);
        }

        throw new InventoryException('npc.error_recipe');
    }
}
