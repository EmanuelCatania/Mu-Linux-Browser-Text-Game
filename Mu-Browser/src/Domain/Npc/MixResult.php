<?php

declare(strict_types=1);

namespace Mu\Domain\Npc;

use Mu\Domain\Item\Item;

/** Resultado de una combinación en un NPC: qué ítems quedan y si salió bien. */
final readonly class MixResult
{
    /** @param list<Item> $items lo que queda en la mesa (puede ser nada, o el ítem degradado si falló) */
    public function __construct(public bool $success, public array $items)
    {
    }

    public static function success(Item ...$items): self
    {
        return new self(true, array_values($items));
    }

    public static function failure(Item ...$items): self
    {
        return new self(false, array_values($items));
    }
}
