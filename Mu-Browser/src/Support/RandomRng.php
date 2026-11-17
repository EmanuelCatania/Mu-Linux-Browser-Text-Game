<?php

declare(strict_types=1);

namespace Mu\Support;

final class RandomRng implements Rng
{
    public function int(int $min, int $max): int
    {
        return $max <= $min ? $min : random_int($min, $max);
    }
}
