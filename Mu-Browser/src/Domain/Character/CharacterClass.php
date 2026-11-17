<?php

declare(strict_types=1);

namespace Mu\Domain\Character;

/**
 * Clases de personaje con los mismos números que usa el juego original
 * (columna `class` y campo "clase requerida" del código de ítem).
 */
enum CharacterClass: int
{
    case DarkKnight = 1;
    case DarkWizard = 2;
    case FairyElf = 3;
    case Summoner = 4;
    case MagicGladiator = 5;
    case DarkLord = 6;
    case RageFighter = 7;
    case BladeKnight = 21;
    case SoulMaster = 22;
    case MuseElf = 23;
    case BloodySummoner = 24;
    // tercera clase: se llega al nivel máximo, con la misión de Sebina
    case BladeMaster = 31;
    case GrandMaster = 32;
    case HighElf = 33;
    case DimensionMaster = 34;
    case DuelMaster = 35;
    case LordEmperor = 36;

    /** Clases que se pueden elegir al crear un personaje. */
    public const array CREATABLE = [self::DarkKnight, self::DarkWizard, self::FairyElf, self::Summoner];

    /**
     * Clases que se habilitan para toda la cuenta cuando uno de sus personajes llega a cierto nivel,
     * como en el MU real: clase → nivel.
     */
    public const array UNLOCKABLE = [self::MagicGladiator->value => 220, self::DarkLord->value => 250];

    /** Puntos de atributo por nivel: las clases desbloqueables reciben 7 en vez de 5. */
    public function pointsPerLevel(): int
    {
        return isset(self::UNLOCKABLE[$this->base()->value]) ? 7 : 5;
    }

    /**
     * Atributos a los que esta clase puede asignar puntos. Sólo el Dark Lord tiene Comando.
     *
     * @return list<string>
     */
    public function stats(): array
    {
        return $this->base() === self::DarkLord ? [...Character::STATS, 'command'] : Character::STATS;
    }

    /** Los nombres de clase son nombres propios de MU: no se traducen. */
    public function label(): string
    {
        return match ($this) {
            self::DarkKnight => 'Dark Knight',
            self::DarkWizard => 'Dark Wizard',
            self::FairyElf => 'Fairy Elf',
            self::Summoner => 'Summoner',
            self::MagicGladiator => 'Magic Gladiator',
            self::DarkLord => 'Dark Lord',
            self::RageFighter => 'Rage Fighter',
            self::BladeKnight => 'Blade Knight',
            self::SoulMaster => 'Soul Master',
            self::MuseElf => 'Muse Elf',
            self::BloodySummoner => 'Bloody Summoner',
            self::BladeMaster => 'Blade Master',
            self::GrandMaster => 'Grand Master',
            self::HighElf => 'High Elf',
            self::DimensionMaster => 'Dimension Master',
            self::DuelMaster => 'Duel Master',
            self::LordEmperor => 'Lord Emperor',
        };
    }

    /** Clase inicial de la que proviene una evolución (Blade Knight → Dark Knight). */
    public function base(): self
    {
        $class = $this;
        while (($previous = $class->previous()) !== null) {
            $class = $previous;
        }

        return $class;
    }

    /** Clase desde la que se evoluciona a ésta (Blade Master → Blade Knight → Dark Knight), o null si es inicial. */
    public function previous(): ?self
    {
        return match ($this) {
            self::BladeKnight => self::DarkKnight,
            self::SoulMaster => self::DarkWizard,
            self::MuseElf => self::FairyElf,
            self::BloodySummoner => self::Summoner,
            self::BladeMaster => self::BladeKnight,
            self::GrandMaster => self::SoulMaster,
            self::HighElf => self::MuseElf,
            self::DimensionMaster => self::BloodySummoner,
            self::DuelMaster => self::MagicGladiator,
            self::LordEmperor => self::DarkLord,
            default => null,
        };
    }

    /**
     * Esta clase y todas las anteriores de su línea: un ítem de cualquiera de ellas le sirve.
     *
     * @return list<self>
     */
    public function lineage(): array
    {
        $line = [$this];
        while (($previous = end($line)->previous()) !== null) {
            $line[] = $previous;
        }

        return $line;
    }

    /** 1 = inicial, 2 = segunda clase, 3 = tercera (la única que usa el árbol master). */
    public function tier(): int
    {
        return $this->value >= 30 ? 3 : ($this->value >= 20 ? 2 : 1);
    }

    /** Número de la imagen de retrato: las terceras clases usan la de la clase anterior. */
    public function image(): int
    {
        return $this->tier() === 3 ? ($this->previous()?->value ?? $this->value) : $this->value;
    }

    /**
     * Valores con los que nace un personaje (function.char.php del original).
     *
     * @return array{strength: int, agility: int, stamina: int, energy: int, command: int, hp: int, mp: int, skills: list<string>}
     */
    public function startingStats(): array
    {
        return match ($this->base()) {
            // Valores oficiales (DefaultClassInfo) de las dos clases que el original no tenía.
            self::MagicGladiator => ['strength' => 26, 'agility' => 26, 'stamina' => 26, 'energy' => 26, 'hp' => 110, 'mp' => 60, 'skills' => []],
            self::DarkLord => ['strength' => 26, 'agility' => 20, 'stamina' => 20, 'energy' => 15, 'command' => 25, 'hp' => 90, 'mp' => 40, 'skills' => ['80']],
            self::DarkKnight => ['strength' => 28, 'agility' => 20, 'stamina' => 25, 'energy' => 10, 'hp' => 110, 'mp' => 20, 'skills' => []],
            self::DarkWizard => ['strength' => 18, 'agility' => 18, 'stamina' => 15, 'energy' => 30, 'hp' => 60, 'mp' => 60, 'skills' => ['30']],
            self::FairyElf => ['strength' => 22, 'agility' => 25, 'stamina' => 20, 'energy' => 15, 'hp' => 80, 'mp' => 30, 'skills' => []],
            self::Summoner => ['strength' => 21, 'agility' => 21, 'stamina' => 18, 'energy' => 23, 'hp' => 70, 'mp' => 40, 'skills' => []],
            default => throw new \LogicException($this->label() . ' no es una clase inicial.'),
        } + ['command' => 0];
    }
}
