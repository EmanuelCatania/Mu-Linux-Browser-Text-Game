<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

/** Lugares donde puede estar un ítem de un jugador. */
enum Container: string
{
    case Inventory = 'inventory';
    case Equipment = 'equipment';
    case Vault = 'vault';
    /** Mesa de combinación de la Chaos Machine y los NPC especiales. */
    case Mix = 'mix';
    /** Tienda personal: lo que el jugador ofrece en el mercado. */
    case Store = 'store';
    /** Mesa de apuestas del PvP: lo que la cuenta pone en juego en su próximo duelo. */
    case Duel = 'duel';
    /** Lo ganado en duelos, a la espera de que el jugador lo retire. */
    case Prize = 'prize';

    /** Todo salvo el inventario y el equipo es de la cuenta, no de un personaje. */
    public function ownedByAccount(): bool
    {
        return $this !== self::Inventory && $this !== self::Equipment;
    }

    /** Filas de la grilla (siempre de 8 columnas). El equipo no es una grilla: son 12 slots sueltos. */
    public function rows(): int
    {
        return match ($this) {
            self::Inventory => 8,
            self::Vault => 15,
            self::Mix => 4,
            self::Store => 4,
            self::Duel => 4,
            self::Prize => 4,
            self::Equipment => 0,
        };
    }

    public function isGrid(): bool
    {
        return $this->rows() > 0;
    }
}
