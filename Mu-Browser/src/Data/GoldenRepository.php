<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Support\Clock;
use PDO;

final class GoldenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{monster: string, map: string, encountered_at: ?DateTimeImmutable, encountered_by: ?string}> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT monster, map, encountered_at, encountered_by FROM golden_spawns ORDER BY monster')
            ->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): array => [
            'monster' => $r['monster'],
            'map' => $r['map'],
            'encountered_at' => $r['encountered_at'] === null
                ? null
                : new DateTimeImmutable($r['encountered_at'], new DateTimeZone('UTC')),
            'encountered_by' => $r['encountered_by'],
        ], $rows);
    }

    /**
     * Registra el encuentro sólo si nadie lo registró después de $notSince: entre dos jugadores
     * que lo encuentran a la vez, gana uno solo.
     */
    public function claim(string $monster, string $characterName, DateTimeImmutable $now, DateTimeImmutable $notSince): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE golden_spawns SET encountered_at = ?, encountered_by = ?
              WHERE monster = ? AND (encountered_at IS NULL OR encountered_at < ?)'
        );
        $stmt->execute([$now->format(Clock::SQL_FORMAT), $characterName, $monster, $notSince->format(Clock::SQL_FORMAT)]);

        return $stmt->rowCount() === 1;
    }
}
