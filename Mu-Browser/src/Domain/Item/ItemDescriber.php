<?php

declare(strict_types=1);

namespace Mu\Domain\Item;

use Mu\Domain\Character\Character;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Support\Translator;

/**
 * Arma la ficha de un ítem (lo que el original mostraba en el tooltip, functions.overlib.js → load_items),
 * ya traducida. Si se pasa un personaje, marca en rojo lo que no cumple.
 */
final class ItemDescriber
{
    public function __construct(
        private readonly Translator $translator,
        private readonly SkillCatalog $skills,
    ) {
    }

    /**
     * @return array{
     *     name: string,
     *     color: string,
     *     lines: list<array{text: string, color: string, bold: bool}>,
     *     classes: list<array{text: string, ok: bool}>
     * }
     */
    public function describe(Item $item, string $locale, ?Character $character = null): array
    {
        $t = fn (string $key, array $params = []): string => $this->translator->translate($key, $locale, $params);
        $lines = [];
        $add = static function (string $text, string $color = '', bool $bold = false) use (&$lines): void {
            $lines[] = ['text' => $text, 'color' => $color, 'bold' => $bold];
        };
        $type = $item->type;
        $sub = $item->subType;
        $excBlue = $item->isExcellent() ? 'blue' : '';

        // 1. descripción propia del tipo de ítem
        foreach ($this->typeNotes($item) as [$key, $params, $color]) {
            $add($t($key, $params), $color);
        }

        // 2. efecto principal
        $range = $item->damageRange();
        if ($item->effect !== 0) {
            if ($range !== null && !($type === '1' && $sub === '5')) {
                $key = $type === '1' ? 'item.damage_one_handed' : 'item.damage_two_handed';
                $add($t($key, ['min' => $range[0], 'max' => $range[1]]), $excBlue);
            } elseif (in_array($type, ['3', '4', '5', '6', '7'], true)) {
                $add($t('item.defense', ['n' => $item->defense()]), $excBlue);
            } elseif ($type === '8') {
                [$wingDamage, $wingAbsorb] = $item->wingRates();
                $add($t('item.defense', ['n' => $item->defense()]));
                $add($t('item.increase_damage_pct', ['n' => $wingDamage]));
                $add($t('item.absorb_damage_pct', ['n' => $wingAbsorb]));
                $add($t('item.increase_speed'));
            } elseif ($type === '9') {
                $add($t('item.defense_rate', ['n' => $item->defenseRate()]), $excBlue);
            } elseif ($type === 'F') {
                if (in_array($sub, ['1', '2', '3', '4', '5'], true)) {
                    $add($t('item.restores_hp', ['n' => $item->effect]));
                } elseif (in_array($sub, ['6', '7', '8', '9'], true)) {
                    $add($t('item.restores_mp', ['n' => $item->effect]));
                } elseif ($sub === 'A') {
                    $add($t('item.cures_poison'));
                }
            }
        }

        // 3. durabilidad, vida de la mascota o cantidad apilada
        if ($item->durabilityMax !== 0 && $item->durability !== 0) {
            if ($type === 'C') {
                $add($t('item.life', ['n' => $item->durability]));
            } elseif ($type === 'I' || $type === 'F' || ($type === '1' && ($sub === '6' || $sub === '7'))) {
                $add($t('item.count', ['cur' => $item->durability, 'max' => $item->durabilityMax]));
            } else {
                $add($t('item.durability', ['cur' => $item->durability, 'max' => $item->durabilityMax]));
            }
        }

        // 4. requisitos
        foreach ($item->requirements() as ['kind' => $kind, 'value' => $value]) {
            $text = $t('item.req_' . strtolower($kind->name), ['n' => $value]);
            $lacking = $character === null ? 0 : $value - $kind->valueOf($character);
            if ($lacking > 0) {
                $add($text . ' ' . $t('item.lacking', ['n' => $lacking]), 'red');
            } else {
                $add($text);
            }
        }

        // 5. bonus propios de bastones, libros, guantes y botas
        if (($type === '1' || $type === '2') && $sub === '3') {
            $add($t('item.wizardry_rise', ['n' => intdiv($item->power() * 5, 6)]), 'blue', true);
        }
        if ($type === '1' && $sub === '5') {
            $add($t('item.curse_increment', ['n' => $item->power()]), 'blue', true);
        }
        if ($type === '6' && $item->level > 3) {
            $add($t('item.swim_speed'), 'blue', true);
        }
        if ($type === '7' && $item->level > 3) {
            $add($t('item.move_speed'), 'blue', true);
        }

        // 6. harmony
        if ($item->harmonyType !== 0) {
            $weapon = $type === '1' || $type === '2';
            $armor = in_array($type, ['3', '4', '5', '6', '7', '8', '9'], true);
            $factor = $weapon
                ? [1 => 3, 2 => 5, 3 => 8, 4 => 8, 5 => 6, 6 => 5][$item->harmonyType] ?? null
                : [1 => 5, 2 => 6, 3 => 1, 4 => 1, 5 => 2, 6 => 1][$item->harmonyType] ?? null;
            if (($weapon || $armor) && $factor !== null) {
                $key = 'item.harmony_' . ($weapon ? 'weapon' : 'armor') . '_' . $item->harmonyType;
                $add($t($key, ['n' => $item->harmonyValue * $factor]), 'yellow');
            }
        }

        // 7. habilidad del arma
        if ($item->skill !== '0' && $type !== '8') {
            $add($t('item.skill', ['name' => $this->skills->name($item->skill)]), 'blue');
        }

        // 8. luck
        if ($item->luck !== 0) {
            $add($t('item.luck_soul'), 'blue');
            $add($t('item.luck_critical'), 'blue');
        }

        // 9. opción adicional
        if ($item->option !== 0) {
            $key = match (true) {
                ($type === '1' || $type === '2') && $sub === '7' => ['item.option_wizardry', 4],
                $type === '1', $type === '2' => ['item.option_damage', 4],
                in_array($type, ['3', '4', '5', '6', '7'], true) => ['item.option_defense', 4],
                $type === '9' => ['item.option_defense_rate', 5],
                $type === 'B' && $sub === '7' => ['item.option_mana', 1],
                in_array($type, ['8', 'A', 'B'], true) => ['item.option_hp_recovery', 1],
                default => null,
            };
            if ($key !== null) {
                $add($t($key[0], ['n' => $item->option * $key[1]]), 'blue');
            }
        }

        // 10. opción propia de las alas
        if ($type === '8' && $item->skill !== '0') {
            match ($item->skill) {
                '1' => $add($t('item.wing_hp', ['n' => 50 + $item->level * 5]), 'blue'),
                '2' => $add($t('item.wing_mp', ['n' => 50 + $item->level * 5]), 'blue'),
                '3' => $add($t('item.wing_ignore'), 'blue'),
                default => null,
            };
        }

        // 11. opción excellent
        if ($item->excellent >= 1 && $item->excellent <= 6) {
            $group = in_array($type, ['1', '2', 'A'], true) ? 'attack' : 'defense';
            $add($t('item.excellent_' . $group . '_' . $item->excellent), 'blue');
        }

        // 12. sockets
        if ($item->hasSockets()) {
            $add($t('item.socket_info'), 'purple');
            foreach ([1, 2, 3] as $n) {
                if (substr($item->socket, ($n - 1) * 2, 2) === '01') {
                    $add($t('item.socket_empty', ['n' => $n]), 'gray');
                }
            }
        }

        $classes = [];
        foreach ($item->allowedClasses() as $class) {
            $classes[] = [
                'text' => $t('item.class', ['class' => $class->label()]),
                'ok' => $character === null
                    || in_array($class, $character->class->lineage(), true),
            ];
        }

        return [
            'name' => $this->prefix($item, $t) . $item->displayName(),
            'color' => $this->nameColor($item),
            'lines' => $lines,
            'classes' => $classes,
        ];
    }

    /** @param \Closure(string, array<string, int|string>=): string $t */
    private function prefix(Item $item, \Closure $t): string
    {
        if ($this->nameColor($item) === 'brown') {
            return $t('item.prefix_ancient') . ' ';
        }

        return $this->nameColor($item) === 'green' ? $t('item.prefix_excellent') . ' ' : '';
    }

    /** Color del nombre, con la misma prioridad que el original. */
    private function nameColor(Item $item): string
    {
        return match (true) {
            $item->type === 'D', $item->type === 'E' => 'yellow',
            $item->type === 'B' && ($item->subType === '8' || $item->subType === '9') => 'yellow',
            str_contains($item->name, 'divine') => 'pink',
            $item->socket !== '000000' => 'purple',
            $item->isAncient() => 'brown',
            $item->isExcellent() => 'green',
            $item->level > 6 => 'yellow',
            $item->level > 3, $item->option !== 0, $item->luck !== 0, $item->skill !== '0' => 'blue',
            default => '',
        };
    }

    /**
     * Texto fijo según tipo y subtipo.
     *
     * @return list<array{string, array<string, int>, string}> clave, parámetros, color
     */
    private function typeNotes(Item $item): array
    {
        $sub = $item->subType;
        $resistance = ['n' => 1 + $item->level + ($item->isExcellent() ? 5 : 0)];

        $notes = match ($item->type) {
            '9' => [['item.defense', ['n' => (int) $item->defense()]]],
            'A' => [['item.resistance', $resistance]],
            'B' => match ($sub) {
                '9' => [
                    ['item.cannot_repair', [], 'red'],
                    ['item.increase_wizardry_pct', ['n' => 10]],
                    ['item.increase_damage_pct', ['n' => 10]],
                    ['item.increase_attack_speed', ['n' => 10]],
                ],
                '7' => [['item.no_resistance']],
                default => [['item.resistance', $resistance]],
            },
            'C' => match ($sub) {
                '1' => [['item.absorb_damage_pct', ['n' => 20]], ['item.max_hp', ['n' => 50]]],
                '2' => [['item.increase_wizardry_pct', ['n' => 30]], ['item.increase_damage_pct', ['n' => 30]]],
                '4' => [['item.absorb_damage_pct', ['n' => 10]], ['item.increase_damage_pct', ['n' => 15]]],
                '6' => [['item.absorb_final_pct', ['n' => 10], 'blue'], ['item.increase_speed', [], 'blue']],
                '7' => [['item.increase_final_pct', ['n' => 10], 'blue'], ['item.increase_speed', [], 'blue']],
                '8' => [['item.absorb_damage_pct', ['n' => 30], 'blue'], ['item.max_hp', ['n' => 50]]],
                '9' => [['item.increase_wizardry_pct', ['n' => 40], 'blue'], ['item.increase_damage_pct', ['n' => 40], 'blue']],
                'A' => [['item.absorb_damage_pct', ['n' => 15]], ['item.pet_horse']],
                'B' => [['item.pet_raven']],
                default => [],
            },
            'D' => match ($sub) {
                '1' => [['item.jewel_bless']],
                '2' => [['item.jewel_soul']],
                '3' => [['item.jewel_life']],
                '4' => [['item.jewel_harmony']],
                '5', '6' => [['item.jewel_refine']],
                default => [],
            },
            'E' => match ($sub) {
                '1' => [['item.mat_chaos']],
                '2' => [['item.mat_fruits']],
                '3' => [['item.mat_gemstone']],
                '4', '5' => [['item.mat_pack']],
                '6' => [['item.mat_upgrade']],
                '7' => [['item.mat_wings2']],
                '8', '9' => [['item.mat_wings3']],
                default => [],
            },
            'H' => [['item.box']],
            'I' => match ($sub) {
                '1', '2', '3' => [['item.ev_cherry']],
                '4', '5' => [['item.ev_cloak']],
                '6', '7' => [['item.ev_devil']],
                '8', '9' => [['item.ev_blood']],
                'A' => [['item.ev_map']],
                'B' => [['item.ev_sign']],
                'C', 'D' => [['item.ev_horn_fragment']],
                'E', 'F' => [['item.ev_broken_horn']],
                'G' => [['item.ev_fenrir']],
                'K', 'L' => [['item.ev_sealed_box']],
                'M', 'N' => [['item.ev_key']],
                default => [],
            },
            'J' => [['item.fruit']],
            'K' => $sub === '9'
                ? [['item.cannot_repair', [], 'red'], ['item.ticket_kanturu']]
                : [['item.ticket']],
            default => [],
        };

        return array_map(static fn (array $n): array => [$n[0], $n[1] ?? [], $n[2] ?? ''], $notes);
    }
}
