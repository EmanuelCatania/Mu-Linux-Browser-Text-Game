<?php

declare(strict_types=1);

namespace Mu\Domain\World;

/** Zona de un mapa donde se pelea contra un grupo fijo de monstruos. */
final readonly class Spot
{
    /**
     * @param list<string> $encounters monstruos comunes que pueden aparecer
     * @param ?string      $boss       jefe que aparece cada tanto, si el spot tiene uno
     */
    public function __construct(
        public string $id,
        public int $x,
        public int $y,
        public array $encounters,
        public ?string $boss,
        public int $bossStars,
    ) {
    }

    /** "spot12" → 12. */
    public function number(): int
    {
        return (int) substr($this->id, 4);
    }
}
