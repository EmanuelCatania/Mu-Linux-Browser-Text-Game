<?php

declare(strict_types=1);

namespace Mu\Support;

/** Fuente de azar del juego. Inyectable para que los tests de combate y drops sean reproducibles. */
interface Rng
{
    /** Entero al azar entre $min y $max, ambos incluidos. Si $max < $min devuelve $min. */
    public function int(int $min, int $max): int;
}
