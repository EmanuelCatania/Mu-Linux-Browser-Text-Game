<?php

declare(strict_types=1);

namespace Mu\Domain\World;

final readonly class GameMap
{
    /**
     * @param array<string, Monster> $monsters por nombre
     * @param array<string, Spot>    $spots    por id
     */
    public function __construct(
        public string $id,
        public array $monsters,
        public array $spots,
    ) {
    }

    public function label(): string
    {
        return Monster::label($this->id);
    }

    public function spot(string $id): ?Spot
    {
        return $this->spots[$id] ?? null;
    }

    public function monster(string $name): ?Monster
    {
        return $this->monsters[$name] ?? null;
    }

    /** @return array{int, int} nivel mínimo y máximo de los monstruos de un spot (sin contar al jefe) */
    public function levelRange(?Spot $spot = null): array
    {
        $names = [];
        foreach ($spot === null ? $this->spots : [$spot] as $s) {
            array_push($names, ...$s->encounters);
        }
        $levels = array_map(fn (string $n): int => $this->monsters[$n]->level, $names);

        return $levels === [] ? [0, 0] : [min($levels), max($levels)];
    }
}
