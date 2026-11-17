<?php

declare(strict_types=1);

namespace Mu\Domain\World;

use DateTimeImmutable;

/**
 * Eventos con horario (Devil Square, Blood Castle): abren a ciertas horas UTC durante unos minutos.
 * Con la entrada (Devil's Invitation o Cloak of Invisibility) se puede pasar sólo mientras están abiertos;
 * el nivel del evento sale del nivel del personaje.
 */
final class ScheduledEvents
{
    /** Nivel del personaje desde el que corresponde cada nivel de evento (1 a 7). */
    private const array TIERS = [1 => 1, 2 => 80, 3 => 130, 4 => 180, 5 => 230, 6 => 280, 7 => 330];

    /** @param array<string, array{hours: list<int>, minutes: int}> $events por id de evento (devil_square, blood_castle) */
    public function __construct(private readonly array $events)
    {
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->events);
    }

    /** Id del evento al que pertenece un mapa ("devil_square_3" → "devil_square"), o null si no tiene horario. */
    public function eventOf(string $map): ?string
    {
        foreach ($this->ids() as $id) {
            if (str_starts_with($map, $id . '_')) {
                return $id;
            }
        }

        return null;
    }

    public static function tierFor(int $level): int
    {
        $tier = 1;
        foreach (self::TIERS as $n => $from) {
            if ($level >= $from) {
                $tier = $n;
            }
        }

        return $tier;
    }

    public static function mapFor(string $event, int $level): string
    {
        return $event . '_' . self::tierFor($level);
    }

    public function isOpen(string $event, DateTimeImmutable $now): bool
    {
        $e = $this->events[$event] ?? null;

        return $e !== null && in_array((int) $now->format('G'), $e['hours'], true) && (int) $now->format('i') < $e['minutes'];
    }

    /** Segundos hasta que cierre (si está abierto) o hasta que vuelva a abrir. */
    public function secondsToChange(string $event, DateTimeImmutable $now): int
    {
        $e = $this->events[$event];
        $intoHour = (int) $now->format('i') * 60 + (int) $now->format('s');
        if ($this->isOpen($event, $now)) {
            return $e['minutes'] * 60 - $intoHour;
        }
        $hour = (int) $now->format('G');
        $waits = [];
        foreach ($e['hours'] as $h) {
            $wait = (($h - $hour + 24) % 24) * 3600 - $intoHour;
            $waits[] = $wait > 0 ? $wait : $wait + 86400;
        }

        return min($waits);
    }
}
