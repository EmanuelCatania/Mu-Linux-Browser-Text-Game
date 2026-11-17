<?php

declare(strict_types=1);

namespace Mu\Domain\Character;

use Mu\Domain\Stats\CharacterStats;

/**
 * Árbol master: lo que viene después del nivel 400 para quien llegó a la tercera clase.
 * La experiencia ya no sube el nivel sino el nivel master; cada nivel master da un punto para el árbol.
 *
 * Tres ramas de cuatro habilidades pasivas, de hasta MAX_RANK rangos cada una. Para abrir una habilidad
 * hay que tener gastados en su rama los puntos que pide su escalón (TIER_POINTS).
 */
final class MasterTree
{
    public const int MAX_LEVEL = 100;
    public const int MAX_RANK = 5;

    /** Puntos gastados en la rama que pide cada escalón. */
    public const array TIER_POINTS = [0, 3, 6, 10];

    /** Rama → habilidades, en orden de escalón. */
    public const array BRANCHES = [
        'protection' => ['hp', 'defense', 'defense_rate', 'decrease'],
        'attack' => ['damage', 'damage_rate', 'critical', 'excellent'],
        'spirit' => ['mp', 'regen_hp', 'regen_mp', 'ignore'],
    ];

    /** Habilidad → cuánto suma cada rango (en % o en puntos, según la habilidad). */
    public const array PER_RANK = [
        'hp' => 3, 'defense' => 3, 'defense_rate' => 3, 'decrease' => 1,
        'damage' => 3, 'damage_rate' => 3, 'critical' => 1, 'excellent' => 1,
        'mp' => 3, 'regen_hp' => 1, 'regen_mp' => 1, 'ignore' => 1,
    ];

    /** Experiencia para pasar del nivel master dado al siguiente. */
    public static function experienceFor(int $masterLevel): int
    {
        return 400_000 + 40_000 * $masterLevel;
    }

    public static function exists(string $node): bool
    {
        return isset(self::PER_RANK[$node]);
    }

    /** @return array{string, int} rama y escalón de la habilidad */
    public static function place(string $node): array
    {
        foreach (self::BRANCHES as $branch => $nodes) {
            $tier = array_search($node, $nodes, true);
            if ($tier !== false) {
                return [$branch, (int) $tier];
            }
        }

        throw new \InvalidArgumentException("Habilidad master desconocida: $node");
    }

    /** @param array<string, int> $ranks */
    public static function spentIn(string $branch, array $ranks): int
    {
        return array_sum(array_intersect_key($ranks, array_flip(self::BRANCHES[$branch])));
    }

    /**
     * Si se le puede sumar un rango (sin mirar si quedan puntos).
     *
     * @param array<string, int> $ranks
     */
    public static function canLearn(string $node, array $ranks): bool
    {
        if (!self::exists($node) || ($ranks[$node] ?? 0) >= self::MAX_RANK) {
            return false;
        }
        [$branch, $tier] = self::place($node);

        return self::spentIn($branch, $ranks) >= self::TIER_POINTS[$tier];
    }

    /**
     * Estadísticas con los bonus del árbol aplicados.
     *
     * @param array<string, int> $ranks
     */
    public static function apply(CharacterStats $s, array $ranks): CharacterStats
    {
        if ($ranks === []) {
            return $s;
        }
        $bonus = static fn (string $node): int => ($ranks[$node] ?? 0) * self::PER_RANK[$node];
        $raise = static fn (int $value, string $node): int => $value + intdiv($value * $bonus($node), 100);

        return new CharacterStats(...array_merge(get_object_vars($s), [
            'hpMax' => $raise($s->hpMax, 'hp'),
            'mpMax' => $raise($s->mpMax, 'mp'),
            'defense' => $raise($s->defense, 'defense'),
            'defenseRate' => $raise($s->defenseRate, 'defense_rate'),
            'damageDecrease' => $s->damageDecrease + $bonus('decrease'),
            'damageMin' => $raise($s->damageMin, 'damage'),
            'damageMax' => $raise($s->damageMax, 'damage'),
            'wizDamageMin' => $raise($s->wizDamageMin, 'damage'),
            'wizDamageMax' => $raise($s->wizDamageMax, 'damage'),
            'damageRate' => $raise($s->damageRate, 'damage_rate'),
            'luck' => $s->luck + $bonus('critical'),
            'excellentRate' => $s->excellentRate + $bonus('excellent'),
            'hpRegeneration' => $s->hpRegeneration + $bonus('regen_hp'),
            'mpRegeneration' => $s->mpRegeneration + $bonus('regen_mp'),
            'ignoreDefense' => $s->ignoreDefense + $bonus('ignore'),
        ]));
    }
}
