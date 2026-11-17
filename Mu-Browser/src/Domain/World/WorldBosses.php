<?php

declare(strict_types=1);

namespace Mu\Domain\World;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Support\Clock;
use PDO;

/**
 * Jefes de mapa (Selupan, Medusa, Nightmare…): hay uno solo por mapa para todo el servidor.
 * Mientras está vivo puede aparecerle a cualquiera que pelee en ese mapa; cuando alguien lo vence
 * tarda un tiempo fijo en volver.
 */
final class WorldBosses
{
    public function __construct(private readonly PDO $pdo, private readonly int $respawnSeconds)
    {
    }

    /** Jefe que está vivo ahora en el mapa, o null. */
    public function aliveIn(string $map, DateTimeImmutable $now): ?string
    {
        foreach ($this->status($now) as $boss) {
            if ($boss['map'] === $map && $boss['alive']) {
                return $boss['monster'];
            }
        }

        return null;
    }

    /** @return bool false si otro jugador lo venció primero */
    public function defeat(string $monster, string $characterName, DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE world_bosses SET defeated_at = ?, defeated_by = ?
              WHERE monster = ? AND (defeated_at IS NULL OR defeated_at <= ?)'
        );
        $stmt->execute([
            $now->format(Clock::SQL_FORMAT),
            $characterName,
            $monster,
            $now->modify('-' . $this->respawnSeconds . ' seconds')->format(Clock::SQL_FORMAT),
        ]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @return list<array{monster: string, map: string, alive: bool, seconds: int, by: ?string}>
     *         seconds = lo que falta para que vuelva (0 si está vivo)
     */
    public function status(DateTimeImmutable $now): array
    {
        $rows = $this->pdo->query('SELECT monster, map, defeated_at, defeated_by FROM world_bosses ORDER BY monster')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function (array $r) use ($now): array {
            $wait = $r['defeated_at'] === null
                ? 0
                : $this->respawnSeconds - ($now->getTimestamp() - new DateTimeImmutable($r['defeated_at'], new DateTimeZone('UTC'))->getTimestamp());

            return [
                'monster' => $r['monster'],
                'map' => $r['map'],
                'alive' => $wait <= 0,
                'seconds' => max(0, $wait),
                'by' => $r['defeated_by'],
            ];
        }, $rows);
    }
}
