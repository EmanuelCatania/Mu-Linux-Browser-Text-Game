<?php

declare(strict_types=1);

/*
 * Simulador de balance: hace pelear a un personaje "tipo" de cada clase, con el motor real de batalla,
 * en todos los mapas y a distintos niveles, y dice dónde le conviene cazar, cuánto tarda en subir y si se traba.
 *
 * Supuestos (los mismos para todas las clases, así que sirven para comparar, no como números absolutos):
 *  - reparte los puntos con una receta fija por clase y evoluciona al nivel 150;
 *  - lleva el mejor equipo del catálogo que puede usar, en +N con N = nivel/30 (máximo +9), sin opciones;
 *  - conoce todas las habilidades cuyo orbe o pergamino puede usar, y siempre tira la que más daño hace;
 *  - empieza cada ronda con vida y maná llenos (usa pociones entre rondas, no durante).
 *
 * Uso (dentro del contenedor de tests): php tools/balance.php [rondas por spot=12] [semilla=7] [exp_hp_divisor]
 */

use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemCatalog;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Domain\Stats\EquipmentSlot;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\MapCatalog;
use Mu\Support\Rng;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$rounds = (int) ($argv[1] ?? 12);
mt_srand((int) ($argv[2] ?? 7));

$rng = new class implements Rng {
    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : mt_rand($min, $max);
    }
};

$config = require $root . '/data/game.php';
if (isset($argv[3])) {
    $config['exp_hp_divisor'] = (int) $argv[3];
}
echo "exp_hp_divisor = {$config['exp_hp_divisor']}
";
$skills = new SkillCatalog($root . '/data/skills.php');
$catalog = new ItemCatalog($root . '/data/items');
$maps = new MapCatalog($root . '/data/maps');
$engine = new BattleEngine($skills, new DropGenerator($root . '/data/drop', $config['drop']), $config);
$calculator = new StatsCalculator();
$now = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));

// clase → [evolución, reparto de puntos en % (fuerza, agilidad, vitalidad, energía, comando), tipo de arma]
$builds = [
    'Dark Knight' => [CharacterClass::DarkKnight, CharacterClass::BladeKnight, [45, 25, 25, 5, 0], 'melee'],
    'Dark Wizard' => [CharacterClass::DarkWizard, CharacterClass::SoulMaster, [5, 20, 20, 55, 0], 'staff'],
    'Fairy Elf' => [CharacterClass::FairyElf, CharacterClass::MuseElf, [20, 50, 20, 10, 0], 'bow'],
    'Summoner' => [CharacterClass::Summoner, CharacterClass::BloodySummoner, [5, 20, 20, 55, 0], 'staff'],
    'Magic Gladiator' => [CharacterClass::MagicGladiator, null, [45, 25, 25, 5, 0], 'melee'],
    'Dark Lord' => [CharacterClass::DarkLord, null, [40, 15, 20, 10, 15], 'melee'],
];
$levels = [1, 10, 20, 30, 40, 50, 60, 80, 100, 120, 150, 180, 220, 260, 300, 350, 400];

$sane = static fn (Item $i): bool => $i->effect < 9000 && !str_contains($i->name, 'game_master');
$usable = static function (Item $i, Character $c): bool {
    if (!$i->canBeUsedBy($c->class)) {
        return false;
    }
    foreach ($i->requirements() as $r) {
        if ($r['value'] > $r['kind']->valueOf($c)) {
            return false;
        }
    }

    return true;
};

$character = static function (CharacterClass $base, ?CharacterClass $evolved, array $weights, int $level, array $known = [], int $resets = 0): Character {
    $class = $evolved !== null && ($level >= 150 || $resets > 0) ? $evolved : $base;
    $start = $base->startingStats();
    // cada reset conserva los puntos ganados hasta el nivel máximo
    $points = ($level - 1 + $resets * (Battle::MAX_LEVEL - 1)) * $base->pointsPerLevel();
    $add = array_map(static fn (int $w): int => intdiv($points * $w, 100), $weights);

    return new Character(
        1, 1, 'Sim', $class, $level,
        $start['strength'] + $add[0], $start['agility'] + $add[1], $start['stamina'] + $add[2], $start['energy'] + $add[3],
        0, 0, Battle::experienceFor($level - 1), 1, 1, $known,
        resets: $resets,
        command: $start['command'] + $add[4],
    );
};

/** Mejor equipo del catálogo que el personaje puede usar. */
$equip = static function (Character $c, string $weaponKind) use ($catalog, $calculator, $sane, $usable): array {
    $plus = $c->resets > 0 ? 9 : min(9, intdiv($c->level, 30));
    $fit = static fn (string $type): array => array_values(array_filter(
        array_map(static fn (Item $i): Item => $i->with(['level' => $plus]), array_filter($catalog->ofType($type), $sane)),
        static fn (Item $i): bool => $usable($i, $c),
    ));
    $best = static function (array $items, callable $score): ?Item {
        usort($items, static fn (Item $a, Item $b): int => $score($b) <=> $score($a));

        return $items[0] ?? null;
    };

    $gear = [];
    foreach (['5' => EquipmentSlot::Helm, '3' => EquipmentSlot::Armor, '6' => EquipmentSlot::Gloves, '4' => EquipmentSlot::Pants, '7' => EquipmentSlot::Boots] as $type => $slot) {
        $gear[$slot->value] = $best($fit((string) $type), static fn (Item $i): int => (int) $i->defense());
    }
    $gear[EquipmentSlot::Wings->value] = $best($fit('8'), static fn (Item $i): int => $i->wingRates()[0]);

    $weapons = [...$fit('1'), ...$fit('2')];
    $weapons = array_filter($weapons, static fn (Item $i): bool => match ($weaponKind) {
        'bow' => $i->type === '2' && $i->subType === '8',
        'staff' => $i->subType === '3' || ($i->type === '1' && $i->subType === '5'),
        default => !in_array($i->subType, ['3', '5', '6', '7', '8', '9'], true),
    });
    $weapon = $best($weapons, static function (Item $i) use ($c, $calculator): int {
        $s = $calculator->calculate($c, [EquipmentSlot::Hand1->value => $i]);

        return max($s->damageMax, (int) ($s->wizDamageMax * (100 + max($s->staffRise, $s->curseIncrement)) / 100));
    });
    $gear[EquipmentSlot::Hand1->value] = $weapon;

    if ($weaponKind === 'bow' && $weapon !== null) {
        $arrows = array_filter($catalog->ofType('1'), static fn (Item $i): bool => $i->subType === '6');
        $gear[EquipmentSlot::Hand2->value] = array_values($arrows)[0] ?? null;
    } elseif ($c->class === CharacterClass::DarkLord) {
        $pets = array_filter($catalog->ofType('C'), static fn (Item $i): bool => $usable($i, $c));
        foreach ($pets as $pet) {
            $gear[$pet->subType === 'B' ? EquipmentSlot::Hand2->value : EquipmentSlot::Pet->value] = $pet;
        }
    } elseif ($weapon !== null && $weapon->type === '1') {
        $gear[EquipmentSlot::Hand2->value] = $best($fit('9'), static fn (Item $i): int => (int) $i->defense());
    }

    return array_filter($gear);
};

/** Habilidades que puede aprender: las de todo orbe o pergamino que puede usar. */
$learn = static function (Character $c) use ($catalog, $usable, $skills): array {
    $known = $c->class->base()->startingStats()['skills'];
    foreach ($catalog->ofType('G') as $scroll) {
        $code = (string) $scroll->durabilityMax;
        if ($skills->has($code) && $usable($scroll, $c)) {
            $known[] = $code;
        }
    }

    return array_values(array_unique($known));
};

/** La acción que más daño promete con el maná que queda. */
$choose = static function (Battle $b, CharacterStats $s) use ($skills): string {
    $alive = count(array_filter($b->enemies, static fn (array $e): bool => !$e['dead']));
    [$min, $max] = BattleEngine::damageRange($s, null);
    $bestAction = BattleEngine::ATTACK;
    $bestScore = $s->usesArrows || $max > 0 ? ($min + $max) / 2 : 0;
    foreach ($s->skills as $code) {
        $def = $skills->all()[$code] ?? null;
        $battle = $def['battle'] ?? null;
        if ($battle === null || !in_array($battle[0], ['phys', 'fixed', 'wiz', 'curse', 'plain'], true) || (int) $def['mana'] > $b->mp) {
            continue;
        }
        [$min, $max] = BattleEngine::damageRange($s, $battle);
        $score = ($min + $max) / 2 * min($alive, (int) ($def['targets'] ?? 1));
        if ($score > $bestScore) {
            [$bestAction, $bestScore] = [$code, $score];
        }
    }

    return $bestAction;
};

/** @return array{win: float, turns: float, exp: float, hp: float} promedio de las rondas en un spot */
$fight = static function (Character $c, CharacterStats $s, string $mapId, string $spotId) use ($maps, $engine, $rng, $now, $rounds, $choose): array {
    $map = $maps->get($mapId);
    $spot = $map->spot($spotId);
    $wins = $turns = $exp = 0;
    $lowest = 100.0;
    for ($r = 0; $r < $rounds; $r++) {
        $b = new Battle();
        $b->map = $mapId;
        $b->spot = $spotId;
        $b->level = $c->level;
        $b->experience = $c->experience;
        $b->hp = $s->hpMax;
        $b->mp = $s->mpMax;
        $b->arrows = $s->usesArrows ? 9999 : -1;
        $b->bossSeen = true; // sin jefes al azar: miden la suerte, no el balance
        $engine->startRound($b, $map, $spot, null, $rng);
        $t = 0;
        while (!$b->roundOver() && !$b->defeated && $t < 80) {
            $target = array_key_first(array_filter($b->enemies, static fn (array $e): bool => !$e['dead']));
            $engine->act($b, $s, $choose($b, $s), $target, $rng, $now);
            $t++;
            $lowest = min($lowest, max(0, $b->hp) * 100 / max(1, $s->hpMax));
        }
        $turns += $t;
        if ($b->roundOver() && !$b->defeated) {
            $wins++;
            $exp += $b->expEarned;
        }
    }

    return ['win' => $wins / $rounds, 'turns' => $turns / $rounds, 'exp' => $turns > 0 ? $exp / $turns : 0.0, 'hp' => $lowest];
};

$spotsOf = static function (string $mapId) use ($maps): array {
    $ids = array_keys($maps->get($mapId)->spots);
    $n = count($ids);

    return array_values(array_unique([$ids[0], $ids[intdiv($n, 2)], $ids[$n - 1]]));
};

$summary = [];
foreach ($builds as $label => [$base, $evolved, $weights, $weaponKind]) {
    echo "\n## $label\n\n";
    echo "| Nivel | Daño | Vida | Defensa | Mejor lugar | Gana | Turnos/ronda | Exp/turno | Turnos para subir | Vida mínima |\n|---|---|---|---|---|---|---|---|---|---|\n";
    $total = 0.0;
    $previous = null;
    foreach ($levels as $level) {
        $c = $character($base, $evolved, $weights, $level);
        $c = $character($base, $evolved, $weights, $level, $learn($c));
        $s = $calculator->calculate($c, $equip($c, $weaponKind));

        $best = null;
        foreach (array_keys(MapCatalog::MAPS) as $mapId) {
            foreach ($spotsOf($mapId) as $spotId) {
                $r = $fight($c, $s, $mapId, $spotId);
                if ($r['win'] >= 0.9 && ($best === null || $r['exp'] > $best['exp'])) {
                    $best = $r + ['place' => "$mapId/$spotId"];
                }
            }
        }

        $damage = max($s->damageMax, (int) ($s->wizDamageMax * (100 + max($s->staffRise, $s->curseIncrement)) / 100));
        $need = Battle::experienceFor($level) - Battle::experienceFor($level - 1);
        if ($best === null) {
            echo "| $level | $damage | {$s->hpMax} | {$s->defense} | **ninguno: pierde en todos lados** | | | | | |\n";
            $summary[$label][$level] = 0;
            continue;
        }
        $toLevel = $best['exp'] > 0 ? $need / $best['exp'] : INF;
        // turnos acumulados entre este punto y el anterior, suponiendo el ritmo promedio de los dos
        if ($previous !== null) {
            $span = Battle::experienceFor($level - 1) - Battle::experienceFor($previous['level'] - 1);
            $total += $span / max(1, ($best['exp'] + $previous['exp']) / 2);
        }
        $previous = ['level' => $level, 'exp' => $best['exp']];
        $summary[$label][$level] = $best['exp'];
        printf(
            "| %d | %d | %d | %d | %s | %d%% | %.1f | %.0f | %s | %d%% |\n",
            $level, $damage, $s->hpMax, $s->defense, $best['place'], $best['win'] * 100, $best['turns'], $best['exp'],
            number_format($toLevel, 0, ',', '.'), $best['hp'],
        );
    }
    printf("\nTurnos estimados de 1 a 400: **%s**\n", number_format($total, 0, ',', '.'));
    $summary[$label]['total'] = $total;
}

echo "\n## Con resets (nivel 400, equipo +9)\n\nCada reset conserva los puntos, así que el personaje sigue creciendo. Mejor lugar donde gana al menos 9 de cada 10 rondas.\n\n";
echo '| Resets | ' . implode(' | ', array_keys($builds)) . " |\n|---|" . str_repeat('---|', count($builds)) . "\n";
foreach ([0, 1, 2, 3, 5, 8, 12] as $resets) {
    $cells = [];
    foreach ($builds as $label => [$base, $evolved, $weights, $weaponKind]) {
        $c = $character($base, $evolved, $weights, Battle::MAX_LEVEL, [], $resets);
        $c = $character($base, $evolved, $weights, Battle::MAX_LEVEL, $learn($c), $resets);
        $s = $calculator->calculate($c, $equip($c, $weaponKind));
        $best = null;
        foreach (array_keys(MapCatalog::MAPS) as $mapId) {
            foreach ($spotsOf($mapId) as $spotId) {
                $r = $fight($c, $s, $mapId, $spotId);
                if ($r['win'] >= 0.9 && ($best === null || $r['exp'] > $best['exp'])) {
                    $best = $r + ['place' => "$mapId/$spotId"];
                }
            }
        }
        $cells[] = $best === null ? 'ninguno' : sprintf('%s (%.0f)', $best['place'], $best['exp']);
    }
    echo "| $resets | " . implode(' | ', $cells) . " |\n";
}

echo "\n## Comparación entre clases (exp por turno)\n\n| Nivel | " . implode(' | ', array_keys($builds)) . " |\n|---|" . str_repeat('---|', count($builds)) . "\n";
foreach ($levels as $level) {
    echo "| $level | " . implode(' | ', array_map(static fn (string $l): string => (string) round($summary[$l][$level]), array_keys($builds))) . " |\n";
}
echo '| **turnos 1→400** | ' . implode(' | ', array_map(static fn (string $l): string => number_format($summary[$l]['total'], 0, ',', '.'), array_keys($builds))) . " |\n";
