<?php

declare(strict_types=1);

namespace Mu\Tests\Support;

use Mu\Support\Rng;

/**
 * Azar controlado para tests: devuelve en orden los valores de la cola (ajustados al rango pedido)
 * y, cuando se acaba, siempre el mínimo o siempre el máximo según $fallback.
 */
final class FakeRng implements Rng
{
    /** @var list<array{int, int}> rangos pedidos, para poder verificar el orden de las tiradas */
    public array $calls = [];

    /** @param list<int> $queue */
    public function __construct(private array $queue = [], private readonly string $fallback = 'max')
    {
    }

    public function int(int $min, int $max): int
    {
        $this->calls[] = [$min, $max];
        if ($max <= $min) {
            return $min;
        }
        if ($this->queue !== []) {
            return max($min, min($max, array_shift($this->queue)));
        }

        return $this->fallback === 'min' ? $min : $max;
    }
}
