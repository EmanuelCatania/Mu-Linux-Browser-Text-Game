<?php

declare(strict_types=1);

namespace Mu\Domain\Pvp;

use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Support\Rng;

/**
 * Resuelve un duelo entero entre dos personajes, sin intervención de los jugadores.
 *
 * Ni el fork ni el original llegaron a programar la pelea, así que las reglas salen del combate contra
 * monstruos (BattleEngine): mismo cálculo de golpe, crítico, excellent, defensa, absorción, resistencia
 * elemental, reflejo y probabilidad de fallar. Los dos empiezan con la vida y el maná al máximo, se sortea
 * quién pega primero y en cada turno el personaje usa la habilidad de daño más fuerte que su maná le permita
 * (o el ataque normal). No cambia nada del personaje: ni vida, ni maná, ni munición.
 */
final class DuelEngine
{
    /** Tipos de `battle` que hacen daño; los de efecto, cura e invocación no se usan en un duelo. */
    private const array DAMAGE_KINDS = ['phys', 'fixed', 'wiz', 'curse', 'plain'];

    public function __construct(private readonly SkillCatalog $skills, private readonly int $maxRounds = 30)
    {
    }

    /**
     * @param array{CharacterStats, CharacterStats} $stats el retador y el desafiado, en ese orden
     *
     * @return array{
     *     winner: ?int, first: int, hpMax: array{int, int}, hp: array{int, int},
     *     log: list<array{by: int, skill: ?string, n: int, tag: string, reflect: int, hp: array{int, int}}>
     * } winner es 0, 1 o null si empataron; en cada golpe n = 0 significa que falló
     */
    public function fight(array $stats, Rng $rng): array
    {
        $hpMax = [max(1, $stats[0]->hpMax), max(1, $stats[1]->hpMax)];
        $hp = $hpMax;
        $mp = [$stats[0]->mpMax, $stats[1]->mpMax];
        $first = $rng->int(0, 1);
        $log = [];

        for ($turn = 0; $turn < $this->maxRounds * 2 && $hp[0] > 0 && $hp[1] > 0; $turn++) {
            $by = ($first + $turn) % 2;
            $foe = 1 - $by;
            $attacker = $stats[$by];
            $defender = $stats[$foe];

            [$code, $definition] = $this->chooseAction($attacker, $mp[$by]);
            if ($code !== null) {
                $mp[$by] -= (int) ($definition['mana'] ?? 0);
            }
            [$min, $max] = BattleEngine::damageRange($attacker, $definition['battle'] ?? null);

            $hit = self::strike($attacker, $defender, $min, $max, $code !== null, $definition['attr'] ?? null, $rng);
            $reflected = 0;
            if ($hit !== null) {
                $hp[$foe] -= $hit['damage'];
                $reflected = intdiv($hit['damage'] * $defender->reflect, 100);
                // El reflejo no remata: si lo hiciera, un mismo golpe podría dejar a los dos en cero.
                $reflected = min($reflected, $hp[$by] - 1);
                $hp[$by] -= max(0, $reflected);
            }

            $log[] = [
                'by' => $by,
                'skill' => $code,
                'n' => $hit['damage'] ?? 0,
                'tag' => $hit['tag'] ?? '',
                'reflect' => max(0, $reflected),
                'hp' => [max(0, $hp[0]), max(0, $hp[1])],
            ];
        }

        $hp = [max(0, $hp[0]), max(0, $hp[1])];
        // Sin caídos: se comparan las fracciones de vida restante (multiplicando en cruz para no usar decimales).
        $winner = match (true) {
            $hp[1] === 0 => 0,
            $hp[0] === 0 => 1,
            default => match ($hp[0] * $hpMax[1] <=> $hp[1] * $hpMax[0]) {
                1 => 0,
                -1 => 1,
                default => null,
            },
        };

        return ['winner' => $winner, 'first' => $first, 'hpMax' => $hpMax, 'hp' => $hp, 'log' => $log];
    }

    /**
     * La habilidad de daño con mejor promedio que el maná alcanza a pagar, si supera al ataque normal.
     *
     * @return array{?string, array<string, mixed>} código y definición (null y [] para el ataque normal)
     */
    private function chooseAction(CharacterStats $stats, int $mp): array
    {
        $catalog = $this->skills->all();
        $bestCode = null;
        $best = [];
        // Un arco sin flechas no dispara: el ataque normal vale cero y sólo queda lo que no dependa del arma.
        $bestAverage = $stats->usesArrows && $stats->arrows < 1 ? 0 : $stats->damageMin + $stats->damageMax;

        foreach ($stats->skills as $code) {
            $definition = $catalog[$code] ?? null;
            $kind = $definition['battle'][0] ?? null;
            if ($definition === null || !in_array($kind, self::DAMAGE_KINDS, true) || (int) ($definition['mana'] ?? 0) > $mp) {
                continue;
            }
            if ($stats->usesArrows && $stats->arrows < 1 && in_array($kind, ['phys', 'plain'], true)) {
                continue;
            }
            [$min, $max] = BattleEngine::damageRange($stats, $definition['battle']);
            $average = $min + $max + 2 * $stats->skillDamageBonus;
            if ($average > $bestAverage) {
                $bestAverage = $average;
                $bestCode = (string) $code;
                $best = $definition;
            }
        }

        return [$bestCode, $best];
    }

    /** @return array{damage: int, tag: string}|null null si falló */
    private static function strike(CharacterStats $a, CharacterStats $d, int $min, int $max, bool $isSkill, ?string $attribute, Rng $rng): ?array
    {
        if (!$isSkill && $a->usesArrows && $a->arrows < 1) {
            return null;
        }

        $damage = $rng->int($min, $max);
        $tag = '';
        if ($a->excellentRate !== 0 && $rng->int(1, 100) <= $a->excellentRate) {
            $top = $max + $a->criticalDamageBonus;
            $damage = $top + (int) floor($top * 0.2);
            $tag = 'exc';
        }
        if ($a->luck !== 0 && $tag === '' && $rng->int(1, 100) <= $a->luck) {
            $damage = $max + $a->criticalDamageBonus;
            $tag = 'crt';
        }
        if ($isSkill) {
            $damage += $a->skillDamageBonus;
        }

        $defense = max(0, $d->defense);
        if ($a->ignoreDefense > 0 && $rng->int(1, 100) <= $a->ignoreDefense) {
            $defense = 0;
            $tag = trim($tag . ' ign');
        }
        $damage -= $defense;
        $damage += (int) floor($damage * $a->damageBonus / 100);
        $damage -= (int) floor($damage * $d->damageAbsorb / 100);
        $damage -= (int) floor($damage * $d->damageDecrease / 100);
        if ($attribute !== null) {
            $damage -= ($d->elementResist[$attribute] ?? 0) * 5;
        }
        $damage = max(1, $damage);

        $missChance = max(5, min(95, intdiv($d->defenseRate * 50, max(1, $a->damageRate))));
        if ($rng->int(1, 100) <= $missChance) {
            return null;
        }

        return ['damage' => $damage, 'tag' => $tag];
    }
}
