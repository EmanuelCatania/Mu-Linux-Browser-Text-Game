<?php

declare(strict_types=1);

namespace Mu\Domain\Item;

use Mu\Domain\Character\Character;

/** Tipo de requisito de un ítem: primer dígito de cada campo de requisito. */
enum Requirement: int
{
    case Strength = 1;
    case Agility = 2;
    case Energy = 3;
    case Level = 4;
    case Stamina = 5;
    case Command = 6;

    /** Valor del personaje contra el que se compara. */
    public function valueOf(Character $character): int
    {
        return match ($this) {
            self::Strength => $character->strength,
            self::Agility => $character->agility,
            self::Energy => $character->energy,
            self::Level => $character->level,
            self::Stamina => $character->stamina,
            self::Command => $character->command,
        };
    }
}
