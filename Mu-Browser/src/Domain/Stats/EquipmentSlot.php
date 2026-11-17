<?php

declare(strict_types=1);

namespace Mu\Domain\Stats;

/** Slots de equipo, con el mismo orden que el array `equipment` del juego original. */
enum EquipmentSlot: int
{
    case Pet = 0;
    case Helm = 1;
    case Wings = 2;
    case Hand1 = 3;
    case Necklace = 4;
    case Armor = 5;
    case Hand2 = 6;
    case Gloves = 7;
    case Ring1 = 8;
    case Pants = 9;
    case Ring2 = 10;
    case Boots = 11;
}
