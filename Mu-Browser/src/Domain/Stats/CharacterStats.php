<?php

declare(strict_types=1);

namespace Mu\Domain\Stats;

/** Valores de combate de un personaje con su equipo puesto (lo que calculate_stats.php dejaba en la sesión). */
final readonly class CharacterStats
{
    /**
     * @param array<string, int> $elementResist resistencia por elemento (wind, fire, ice, lightning, water, earth, poison)
     * @param list<string>       $skills        habilidades disponibles: las del equipo y las aprendidas
     */
    public function __construct(
        public int $energy,
        public int $hpMax,
        public int $mpMax,
        public int $damageMin,
        public int $damageMax,
        /** Daño extra en % (mascota, alas, anillo, opción excellent). */
        public int $damageBonus,
        public int $damageRate,
        public int $wizDamageMin,
        public int $wizDamageMax,
        /** Aumento en % del daño mágico por el bastón. */
        public int $staffRise,
        /** Aumento en % del daño de maldición por el libro. */
        public int $curseIncrement,
        public int $defense,
        public int $defenseRate,
        /** Daño absorbido en % por mascota y alas. */
        public int $damageAbsorb,
        /** Daño reducido en % por opciones excellent y harmony. */
        public int $damageDecrease,
        /** Probabilidad en % de golpe crítico. */
        public int $luck,
        /** Probabilidad en % de golpe excellent. */
        public int $excellentRate,
        public int $reflect,
        /** Probabilidad en % de ignorar la defensa enemiga. */
        public int $ignoreDefense,
        public int $criticalDamageBonus,
        public int $skillDamageBonus,
        public int $hpRegeneration,
        public int $mpRegeneration,
        public int $afterHuntHp,
        public int $afterHuntMp,
        public int $afterHuntZen,
        public array $elementResist,
        public array $skills,
        /** Tiene un arco o una ballesta en la mano: sin munición no puede atacar. */
        public bool $usesArrows = false,
        /** Flechas o virotes que le quedan, o -1 si no lleva munición. */
        public int $arrows = -1,
        /** Vida de la mascota, o -1 si no tiene. */
        public int $petLife = -1,
        /** Comando del Dark Lord: potencia Critical Damage. */
        public int $command = 0,
        /** Daño del Dark Raven por turno (0 = no lleva). */
        public int $ravenMin = 0,
        public int $ravenMax = 0,
        /** Golpe extra del Combo del Dark Knight (0 = no lo tiene). */
        public int $comboDamage = 0,
    ) {
    }
}
