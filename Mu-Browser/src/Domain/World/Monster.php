<?php

declare(strict_types=1);

namespace Mu\Domain\World;

final readonly class Monster
{
    public function __construct(
        public string $name,
        public int $level,
        public int $hp,
        public int $damage,
        public int $defense,
        public int $damageRate,
        public int $defenseRate,
        /** Elemento de su ataque (fire, ice, lightning, poison…) o null. */
        public ?string $attribute,
    ) {
    }

    /** "budge_dragon" → "Budge Dragon". Los nombres de monstruos no se traducen. */
    public static function label(string $name): string
    {
        return ucwords(str_replace('_', ' ', $name));
    }
}
