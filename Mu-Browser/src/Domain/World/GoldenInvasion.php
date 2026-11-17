<?php

declare(strict_types=1);

namespace Mu\Domain\World;

use DateTimeImmutable;
use Mu\Data\GoldenRepository;

/**
 * Golden Invasion: a ciertas horas (UTC) y durante una hora, en cada mapa puede aparecer su monstruo dorado.
 * Cada dorado aparece una sola vez por invasión, para el primer jugador que lo encuentre.
 */
final class GoldenInvasion
{
    private const int DURATION_SECONDS = 3600;

    /** @param list<int> $hours horas UTC en que empieza */
    public function __construct(private readonly GoldenRepository $spawns, private readonly array $hours)
    {
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        return in_array((int) $now->format('G'), $this->hours, true);
    }

    /** Segundos hasta que termine la invasión actual, o hasta que empiece la próxima. */
    public function secondsToChange(DateTimeImmutable $now): int
    {
        $hour = (int) $now->format('G');
        $intoHour = (int) $now->format('i') * 60 + (int) $now->format('s');
        if ($this->isActive($now)) {
            return self::DURATION_SECONDS - $intoHour;
        }

        $waits = array_map(static fn (int $h): int => (($h - $hour + 24) % 24) * 3600 - $intoHour, $this->hours);

        return min($waits);
    }

    /** Monstruo dorado que todavía puede aparecer ahora en el mapa, o null. */
    public function availableIn(string $map, DateTimeImmutable $now): ?string
    {
        if (!$this->isActive($now)) {
            return null;
        }
        foreach ($this->spawns->all() as $spawn) {
            if ($spawn['map'] === $map && !$this->seenRecently($spawn['encountered_at'], $now, self::DURATION_SECONDS)) {
                return $spawn['monster'];
            }
        }

        return null;
    }

    /** @return bool false si otro jugador se lo llevó primero */
    public function claim(string $monster, string $characterName, DateTimeImmutable $now): bool
    {
        return $this->spawns->claim($monster, $characterName, $now, $now->modify('-' . self::DURATION_SECONDS . ' seconds'));
    }

    /**
     * Estado para mostrar: dónde se puede encontrar cada dorado o quién lo encontró último.
     *
     * @return list<array{monster: string, map: ?string, by: ?string}>
     */
    public function status(DateTimeImmutable $now): array
    {
        $active = $this->isActive($now);
        $out = [];
        foreach ($this->spawns->all() as $spawn) {
            $free = !$this->seenRecently($spawn['encountered_at'], $now, self::DURATION_SECONDS);
            $out[] = [
                'monster' => $spawn['monster'],
                // Durante la invasión se muestra el mapa de los que siguen libres.
                'map' => $active && $free ? $spawn['map'] : null,
                // Después de 9 horas ya no se muestra quién lo encontró.
                'by' => $this->seenRecently($spawn['encountered_at'], $now, 9 * 3600) ? $spawn['encountered_by'] : null,
            ];
        }

        return $out;
    }

    private function seenRecently(?DateTimeImmutable $at, DateTimeImmutable $now, int $seconds): bool
    {
        return $at !== null && $now->getTimestamp() - $at->getTimestamp() <= $seconds;
    }
}
