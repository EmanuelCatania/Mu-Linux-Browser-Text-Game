<?php

declare(strict_types=1);

namespace Mu\Domain\Stats;

use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Character\MasterTree;
use Mu\Domain\Item\Item;

/** Port de calculate_stats.php: atributos base por clase más lo que suma cada pieza de equipo. */
final class StatsCalculator
{
    private const array ARMOR_SLOTS = [
        EquipmentSlot::Helm->value,
        EquipmentSlot::Armor->value,
        EquipmentSlot::Gloves->value,
        EquipmentSlot::Pants->value,
        EquipmentSlot::Boots->value,
    ];

    /** Subtipo de colgante → elemento que resiste. */
    private const array NECKLACE_ELEMENTS = ['1' => 'wind', '2' => 'fire', '3' => 'ice', '4' => 'lightning', '5' => 'water'];

    /** Subtipo de anillo → elemento que resiste. */
    private const array RING_ELEMENTS = ['1' => 'wind', '2' => 'fire', '3' => 'ice', '4' => 'earth', '5' => 'poison'];

    /** @param array<int, Item> $equipment ítems puestos, por número de EquipmentSlot */
    public function calculate(Character $c, array $equipment = []): CharacterStats
    {
        $base = $this->base($c);

        $a = [
            'defense' => 0, 'shieldDefenseRate' => 0, 'luck' => 0,
            'hpRegen' => 0, 'mpRegen' => 0,
            'damageLevel20' => 0.0, 'damage2p' => 0.0, 'defenseRate10p' => 0.0, 'damageDecrease' => 0.0,
            'hp4p' => 0.0, 'mp4p' => 0.0, 'excellent' => 0, 'reflect' => 0,
            'afterHp' => 0, 'afterMp' => 0, 'afterZen' => 0,
            'hMinDmg' => 0, 'hMaxDmg' => 0, 'hDefense' => 0, 'hHp' => 0, 'hDefRate' => 0, 'hDmgDec' => 0.0,
            'hCritical' => 0, 'hSkill' => 0,
            'petDamage' => 0.0, 'wingsDamage' => 0.0, 'ringDamage' => 0.0,
            'hpBonus' => 0, 'mpBonus' => 0, 'ignore' => 0, 'absorb' => 0.0,
            'curse' => 0,
        ];
        $resist = ['wind' => 0, 'fire' => 0, 'ice' => 0, 'lightning' => 0, 'water' => 0, 'earth' => 0, 'poison' => 0];
        $skills = [];
        $raven = [0, 0];
        $hand = [1 => ['min' => 0, 'max' => 0, 'staffOption' => 0, 'staffRise' => 0], 2 => ['min' => 0, 'max' => 0, 'staffOption' => 0, 'staffRise' => 0]];

        $addSkill = static function (Item $item) use (&$skills): void {
            if ($item->skill !== '0' && !in_array($item->skill, $skills, true)) {
                $skills[] = $item->skill;
            }
        };
        $excAttack = static function (Item $item) use (&$a, $c): void {
            match ($item->excellent) {
                1 => $a['excellent'] += 10,
                2 => $a['damage2p'] += 0.02,
                4 => $a['damageLevel20'] += $c->level / 20,
                5 => $a['afterHp']++,
                6 => $a['afterMp']++,
                default => null, // 3 es velocidad de ataque: no existe en este juego
            };
        };
        $excDefense = static function (Item $item) use (&$a): void {
            match ($item->excellent) {
                1 => $a['damageDecrease'] += 0.04,
                2 => $a['defenseRate10p'] += 0.1,
                3 => $a['hp4p'] += 0.04,
                4 => $a['mp4p'] += 0.04,
                5 => $a['afterZen']++,
                6 => $a['reflect'] += 5,
                default => null,
            };
        };
        // $full = false para el escudo, que no tiene las opciones de regeneración.
        $harmonyDefense = static function (Item $item, bool $full = true) use (&$a): void {
            $v = $item->harmonyValue;
            match (true) {
                $item->harmonyType === 1 => $a['hDefense'] += $v * 5,
                $item->harmonyType === 2 => $a['hHp'] += $v * 6,
                $item->harmonyType === 3 && $full => $a['hpRegen'] += $v,
                $item->harmonyType === 4 && $full => $a['mpRegen'] += $v,
                $item->harmonyType === 5 => $a['hDefRate'] += $v * 2,
                $item->harmonyType === 6 => $a['hDmgDec'] += $v / 100,
                default => null,
            };
        };
        $harmonyAttack = static function (Item $item) use (&$a): void {
            $v = $item->harmonyValue;
            match ($item->harmonyType) {
                1 => $a['hMinDmg'] += $v * 3,
                2 => $a['hMaxDmg'] += $v * 5,
                5 => $a['hCritical'] += $v * 6,
                6 => $a['hSkill'] += $v * 5,
                default => null,
            };
        };
        $isStaff = static fn (Item $i): bool => ($i->type === '1' || $i->type === '2') && $i->subType === '3';
        $isBook = static fn (Item $i): bool => $i->type === '1' && $i->subType === '5';

        // mascota
        if (isset($equipment[EquipmentSlot::Pet->value])) {
            $item = $equipment[EquipmentSlot::Pet->value];
            match ($item->subType) {
                '1' => [$a['absorb'] += 0.2, $a['hpBonus'] += 50],
                '2' => $a['petDamage'] = 0.3,
                '4' => [$a['petDamage'] = 0.1, $a['absorb'] += 0.15],
                '6' => $a['absorb'] += 0.1,
                '7' => $a['petDamage'] = 0.1,
                '8' => [$a['absorb'] += 0.3, $a['hpBonus'] += 50],
                '9' => $a['petDamage'] = 0.4,
                // Dark Horse: mejora con el Comando del Dark Lord
                'A' => [$a['absorb'] += 0.15, $a['defense'] += intdiv($c->command, 20)],
                default => null,
            };
            $addSkill($item);
        }

        // casco, armadura, guantes, pantalón, botas
        foreach (self::ARMOR_SLOTS as $slot) {
            if (!isset($equipment[$slot])) {
                continue;
            }
            $item = $equipment[$slot];
            $a['defense'] += $item->power() + $item->option * 4;
            $a['luck'] += $item->luck;
            $excDefense($item);
            $harmonyDefense($item);
        }

        // alas
        if (isset($equipment[EquipmentSlot::Wings->value])) {
            $item = $equipment[EquipmentSlot::Wings->value];
            $a['luck'] += $item->luck;
            $a['hpRegen'] += $item->option;
            match ($item->skill) {
                '1' => $a['hpBonus'] += 50 + $item->level * 5,
                '2' => $a['mpBonus'] += 50 + $item->level * 5,
                '3' => $a['ignore'] += 3,
                default => null,
            };
            [$wingDamage, $wingAbsorb] = $item->wingRates();
            $a['wingsDamage'] = $wingDamage / 100;
            $a['absorb'] += $wingAbsorb / 100;
            $a['defense'] += (int) $item->defense();
            $harmonyDefense($item);
        }

        // mano 1
        if (isset($equipment[EquipmentSlot::Hand1->value])) {
            $item = $equipment[EquipmentSlot::Hand1->value];
            $a['luck'] += $item->luck;
            $excAttack($item);
            $harmonyAttack($item);
            $hand[1]['min'] = intdiv($item->power() * 5, 6) + $item->option * 4;
            $hand[1]['max'] = $item->power() + $item->option * 4;
            if ($isStaff($item)) {
                $hand[1]['staffOption'] = $item->option * 4;
                $hand[1]['staffRise'] = intdiv($item->power() * 5, 6);
            }
            if ($isBook($item)) {
                $a['curse'] = $item->power();
            }
            $addSkill($item);
        }

        // mano 2: segunda arma o escudo
        if (isset($equipment[EquipmentSlot::Hand2->value])) {
            $item = $equipment[EquipmentSlot::Hand2->value];
            $a['luck'] += $item->luck;
            if ($item->type === 'C') {
                // Dark Raven: no suma al golpe del personaje, ataca por su cuenta cada turno (ver BattleEngine).
                $raven = [50 + intdiv($c->command, 4), 80 + intdiv($c->command, 3)];
            } elseif ($item->type === '9') {
                $a['defense'] += (int) $item->defense();
                $a['shieldDefenseRate'] = (int) $item->defenseRate() + $item->option * 5;
                $excDefense($item);
                $harmonyDefense($item, false);
            } else {
                if ($isStaff($item)) {
                    $hand[2]['staffOption'] = $item->option * 4;
                    $hand[2]['staffRise'] = intdiv($item->power() * 5, 6);
                }
                if ($isBook($item)) {
                    $a['curse'] = max($a['curse'], $item->power());
                }
                $excAttack($item);
                $harmonyAttack($item);
                $hand[2]['min'] = intdiv($item->power() * 5, 6) + $item->option * 4;
                $hand[2]['max'] = $item->power() + $item->option * 4;
            }
            $addSkill($item);
        }

        // colgante
        if (isset($equipment[EquipmentSlot::Necklace->value])) {
            $item = $equipment[EquipmentSlot::Necklace->value];
            $a['hpRegen'] += $item->option;
            $excAttack($item);
            $element = self::NECKLACE_ELEMENTS[$item->subType] ?? null;
            if ($element !== null) {
                $resist[$element] += $item->level + 1 + ($item->isExcellent() ? 5 : 0);
            }
        }

        // anillos
        foreach ([EquipmentSlot::Ring1->value, EquipmentSlot::Ring2->value] as $slot) {
            if (!isset($equipment[$slot])) {
                continue;
            }
            $item = $equipment[$slot];
            if ($item->subType === '7') {
                $a['mp4p'] += $item->option / 100; // ring of magic
            } else {
                $a['hpRegen'] += $item->option;
            }
            if ($item->subType === '9') {
                $a['ringDamage'] = 0.1; // wizard's ring
            }
            $excDefense($item);
            $element = self::RING_ELEMENTS[$item->subType] ?? null;
            if ($element !== null) {
                $resist[$element] += $item->level + 1 + ($item->isExcellent() ? 5 : 0);
            }
        }

        // arco/ballesta, su munición y la vida de la mascota
        $bow = $equipment[EquipmentSlot::Hand1->value] ?? null;
        $usesArrows = $bow !== null && $bow->type === '2' && ($bow->subType === '8' || $bow->subType === '9');
        $ammo = $equipment[EquipmentSlot::Hand2->value] ?? null;
        $hasAmmo = $usesArrows && $ammo !== null && $ammo->type === '1' && ($ammo->subType === '6' || $ammo->subType === '7');
        $pet = $equipment[EquipmentSlot::Pet->value] ?? null;

        // Sólo cuenta el arma más fuerte de las dos manos.
        $weapon = $hand[1]['min'] >= $hand[2]['min'] ? $hand[1] : $hand[2];

        $damageMax = (int) floor($base['damageMax'] + $weapon['max'] + $a['damageLevel20'] + $a['hMaxDmg']);
        $damageMin = min($damageMax, (int) floor($base['damageMin'] + $weapon['min'] + $a['damageLevel20'] + $a['hMinDmg']));

        $staffOptions = $hand[1]['staffOption'] + $hand[2]['staffOption'];
        $wizMax = (int) floor($base['wizMax'] + $staffOptions + $a['damageLevel20'] + $a['hMaxDmg']);
        $wizMin = min($wizMax, (int) floor($base['wizMin'] + $staffOptions + $a['damageLevel20'] + $a['hMinDmg']));

        return MasterTree::apply(new CharacterStats(
            energy: $c->energy,
            command: $c->command,
            ravenMin: $raven[0],
            ravenMax: $raven[1],
            comboDamage: EvolutionService::hasCombo($c) ? intdiv($c->strength + $c->agility + $c->energy, 2) : 0,
            hpMax: (int) floor(($base['hp'] + $a['hpBonus'] + $a['hHp']) * (1 + $a['hp4p'])),
            mpMax: (int) floor(($base['mp'] + $a['mpBonus']) * (1 + $a['mp4p'])),
            damageMin: $damageMin,
            damageMax: $damageMax,
            damageBonus: (int) floor(round(($a['petDamage'] + $a['wingsDamage'] + $a['damage2p'] + $a['ringDamage']) * 100, 6)),
            damageRate: $base['damageRate'],
            wizDamageMin: $wizMin,
            wizDamageMax: $wizMax,
            staffRise: max($hand[1]['staffRise'], $hand[2]['staffRise']),
            curseIncrement: $a['curse'],
            defense: $base['defense'] + $a['defense'] + $a['hDefense'],
            defenseRate: (int) floor(($base['defenseRate'] + $a['shieldDefenseRate'] + $a['hDefRate']) * (1 + $a['defenseRate10p'])),
            damageAbsorb: (int) floor(round($a['absorb'] * 100, 6)),
            damageDecrease: (int) floor(round(($a['damageDecrease'] + $a['hDmgDec']) * 100, 6)),
            luck: $a['luck'] * 5,
            excellentRate: $a['excellent'],
            reflect: $a['reflect'],
            ignoreDefense: $a['ignore'],
            criticalDamageBonus: $a['hCritical'],
            skillDamageBonus: $a['hSkill'],
            hpRegeneration: $a['hpRegen'],
            mpRegeneration: $a['mpRegen'],
            afterHuntHp: $a['afterHp'],
            afterHuntMp: $a['afterMp'],
            afterHuntZen: $a['afterZen'],
            elementResist: $resist,
            // Infinity Arrow: la enseña Marlon a la Muse Elf
            skills: array_values(array_unique([...$skills, ...$c->skills, ...(EvolutionService::hasInfinityArrow($c) ? ['F'] : [])])),
            usesArrows: $usesArrows,
            arrows: $hasAmmo ? $ammo->durability : -1,
            petLife: $pet === null ? -1 : $pet->durability,
        ), $c->master);
    }

    /** @return array{damageMin: int, damageMax: int, damageRate: int, defense: int, defenseRate: int, hp: int, mp: int, wizMin: int, wizMax: int} */
    private function base(Character $c): array
    {
        $str = $c->strength;
        $agi = $c->agility;
        $sta = $c->stamina;
        $ene = $c->energy;
        $lvl = $c->level;
        // "dividido por 2, porque originalmente era demasiado alto" (comentario del original)
        $damageRate = (int) floor(($lvl * 5 + ($agi * 3) / 2 + $str / 4) / 2);

        return match ($c->class->base()) {
            CharacterClass::DarkKnight => [
                'damageMax' => intdiv($str, 4), 'damageMin' => intdiv($str, 6), 'damageRate' => $damageRate,
                'defense' => intdiv($agi, 3), 'defenseRate' => intdiv($agi, 3),
                'hp' => 35 + $lvl * 2 + $sta * 3, 'mp' => (int) floor(10 + $lvl * 0.5 + $ene),
                'wizMax' => 0, 'wizMin' => 0,
            ],
            CharacterClass::DarkWizard => [
                'damageMax' => intdiv($str, 8), 'damageMin' => intdiv($str, 12), 'damageRate' => $damageRate,
                'defense' => intdiv($agi, 4), 'defenseRate' => intdiv($agi, 3),
                'hp' => 30 + $lvl + $sta * 2, 'mp' => $lvl * 2 + $ene * 2,
                'wizMax' => intdiv($ene, 4), 'wizMin' => intdiv($ene, 9),
            ],
            CharacterClass::FairyElf => [
                'damageMax' => (int) floor($str / 8 + $agi / 4), 'damageMin' => (int) floor($str / 14 + $agi / 7),
                'damageRate' => $damageRate,
                'defense' => intdiv($agi, 10), 'defenseRate' => intdiv($agi, 4),
                'hp' => 40 + $lvl + $sta * 2, 'mp' => (int) floor(6 + $lvl * 1.5 + $ene * 1.5),
                'wizMax' => 0, 'wizMin' => 0,
            ],
            CharacterClass::Summoner => [
                'damageMax' => intdiv($str, 8), 'damageMin' => intdiv($str, 12), 'damageRate' => $damageRate,
                'defense' => intdiv($agi, 3), 'defenseRate' => intdiv($agi, 4),
                'hp' => 33 + $lvl + $sta * 2, 'mp' => (int) floor(4 + $lvl * 1.5 + $ene * 1.5),
                'wizMax' => intdiv($ene, 4), 'wizMin' => intdiv($ene, 9),
            ],
            // Fórmulas oficiales del servidor; vida y maná parten de los valores con los que nace cada clase.
            CharacterClass::MagicGladiator => [
                'damageMax' => (int) floor($str / 4 + $ene / 8), 'damageMin' => (int) floor($str / 6 + $ene / 12),
                'damageRate' => $damageRate,
                'defense' => intdiv($agi, 4), 'defenseRate' => intdiv($agi, 3),
                'hp' => 57 + $lvl + $sta * 2, 'mp' => 7 + $lvl + $ene * 2,
                'wizMax' => intdiv($ene, 4), 'wizMin' => intdiv($ene, 9),
            ],
            CharacterClass::DarkLord => [
                'damageMax' => (int) floor($str / 5 + $ene / 10), 'damageMin' => (int) floor($str / 7 + $ene / 14),
                'damageRate' => $damageRate,
                'defense' => intdiv($agi, 7), 'defenseRate' => intdiv($agi, 7),
                'hp' => (int) floor(48.5 + $lvl * 1.5 + $sta * 2), 'mp' => (int) floor(16.5 + $lvl + $ene * 1.5),
                'wizMax' => 0, 'wizMin' => 0,
            ],
            default => throw new \LogicException('Clase sin estadísticas base: ' . $c->class->label()),
        };
    }
}
