<?php

declare(strict_types=1);

namespace Mu\Domain\Character;

final readonly class Character
{
    /** Atributos a los que se pueden asignar puntos. */
    public const array STATS = ['strength', 'agility', 'stamina', 'energy'];

    /** Todas las columnas de atributo que existen; cuáles usa cada clase lo dice CharacterClass::stats(). */
    public const array ALL_STATS = ['strength', 'agility', 'stamina', 'energy', 'command'];

    /** @param list<string> $skills códigos de habilidad aprendidos */
    public function __construct(
        public int $id,
        public int $accountId,
        public string $name,
        public CharacterClass $class,
        public int $level,
        public int $strength,
        public int $agility,
        public int $stamina,
        public int $energy,
        public int $points,
        public int $fruits,
        public int $experience,
        public int $hpCur,
        public int $mpCur,
        public array $skills = [],
        /** Misión en curso y cuántos monstruos lleva cazados. */
        public ?int $questId = null,
        public int $questScore = 0,
        /** Mapa de evento al que tiene entrada (por ejemplo kalima_3), o null. */
        public ?string $eventMap = null,
        public ?int $guildId = null,
        /** Clan que lo invitó y todavía no respondió. */
        public ?int $guildInviteId = null,
        /** Veces que volvió al nivel 1 tras llegar al máximo. */
        public int $resets = 0,
        /** Avance en la misión de evolución de clase: ver EvolutionService. */
        public int $evoStep = 0,
        /** Comando: quinto atributo, propio del Dark Lord. */
        public int $command = 0,
        /** Nivel master, experiencia dentro de ese nivel y puntos sin gastar: sólo para la tercera clase. */
        public int $masterLevel = 0,
        public int $masterExp = 0,
        public int $masterPoints = 0,
        /** @var array<string, int> rangos en el árbol master, por habilidad (ver MasterTree) */
        public array $master = [],
        /** @var array{items?: list<string>, kills?: array<string, int>} avance de la misión de clase activa */
        public array $evoProgress = [],
    ) {
    }
}
