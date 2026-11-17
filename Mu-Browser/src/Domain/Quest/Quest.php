<?php

declare(strict_types=1);

namespace Mu\Domain\Quest;

use Mu\Domain\Item\Item;

/** Una misión del Shadow Phantom Soldier: matar cierta cantidad de un monstruo. */
final readonly class Quest
{
    public function __construct(
        public int $id,
        public int $levelMin,
        public int $levelMax,
        /** Monstruo a cazar. */
        public string $target,
        public int $amount,
        /** Ítem de premio, o null si el premio es subir un nivel. */
        public ?Item $reward,
    ) {
    }

    public function isFor(int $level): bool
    {
        return $level >= $this->levelMin && $level <= $this->levelMax;
    }
}
