<?php

declare(strict_types=1);

namespace Mu\Domain\Battle;

/**
 * Estado de una batalla en curso. Se guarda entero como JSON (tabla battles) entre un turno y el siguiente;
 * el progreso del personaje (experiencia, nivel, zen) recién pasa a sus tablas al terminar cada ronda.
 */
final class Battle
{
    /** Nivel máximo: al llegar, la experiencia deja de subir hasta que el personaje haga un reset. */
    public const int MAX_LEVEL = 400;

    public string $map = '';
    public string $spot = '';
    /** Turnos jugados desde que se entró al spot. */
    public int $turn = 1;
    /** Ya apareció un jefe o un monstruo dorado en esta visita (como mucho uno). */
    public bool $bossSeen = false;
    public int $level = 1;
    public int $experience = 0;
    public int $hp = 0;
    public int $mp = 0;
    /** Efecto activo sobre el jugador: código de la habilidad que lo causó, o ''. */
    public string $effect = '';
    public int $effectTurns = 0;
    /** Experiencia base ganada desde que se entró al spot (para calcular el ritmo por turno). */
    public int $expEarned = 0;
    /** Porcentaje de experiencia que recibe, según cuántos más pelean en el mismo spot (10 a 150). */
    public int $expPercent = 100;
    /** Pendientes de guardar al cerrar la ronda. */
    public int $kills = 0;
    public int $zen = 0;
    public int $levelUps = 0;
    /** Tercera clase: en el nivel máximo la experiencia va al nivel master. masterExp es lo acumulado dentro del nivel. */
    public bool $master = false;
    public int $masterLevel = 0;
    public int $masterExp = 0;
    public int $masterUps = 0;
    /** Munición y vida de la mascota durante la batalla (-1 = no tiene). Se pasan al equipo al cerrar la ronda. */
    public int $arrows = -1;
    public int $petLife = -1;
    /** Mapa de evento (Kalima): se avanza de un spot al siguiente y al final espera un jefe. */
    public bool $event = false;
    /** Spots que faltan en el evento; en 0 la ronda en curso es la del jefe. */
    public int $eventCountdown = 0;
    /** Monstruo de la misión en curso ('' = ninguna) y cuántos cayeron desde el último guardado. */
    public string $questTarget = '';
    public int $questKills = 0;
    /**
     * Misión de clase activa (ver EvolutionService::hunt): ítems que pueden caer, mapa donde cuentan las bajas
     * y cuántas faltan de cada monstruo. evoFound y evoKilled son lo conseguido desde el último guardado.
     *
     * @var list<array{item: string, min: int, max: int, monster: string}>
     */
    public array $evoDrops = [];
    public string $evoMap = '';
    /** @var array<string, int> */
    public array $evoKills = [];
    /** @var list<string> */
    public array $evoFound = [];
    /** @var array<string, int> */
    public array $evoKilled = [];
    /** Habilidades usadas seguidas, para el Combo del Dark Knight. @var list<string> */
    public array $combo = [];
    /** Invocación de la Fairy Elf: habilidad que la creó ('' = no hay) y su vida. Dura hasta que la maten o se salga del spot. */
    public string $summon = '';
    public int $summonHp = 0;
    /** Party: experiencia propia ya repartida a los compañeros, y lo que ellos le dejaron al empezar esta ronda. */
    public int $expShared = 0;
    public int $partyExp = 0;
    public bool $defeated = false;
    /** @var list<array<string, mixed>> lo que pasó en el último turno */
    public array $log = [];
    /** @var list<array<string, mixed>> */
    public array $enemies = [];

    public function roundOver(): bool
    {
        foreach ($this->enemies as $enemy) {
            if (!$enemy['dead']) {
                return false;
            }
        }

        return true;
    }

    /** Experiencia necesaria para pasar del nivel dado al siguiente (acumulada). */
    public static function experienceFor(int $level): int
    {
        return ($level + 9) * $level * $level;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $battle = new self();
        foreach (get_object_vars($battle) as $key => $default) {
            if (array_key_exists($key, $data) && gettype($data[$key]) === gettype($default)) {
                $battle->$key = $data[$key];
            }
        }

        return $battle;
    }
}
