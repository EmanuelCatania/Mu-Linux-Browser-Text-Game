<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Domain\Battle\Battle;
use Mu\Support\Clock;
use PDO;

final class BattleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{Battle, int}|null la batalla y la versión con la que se leyó */
    public function find(int $characterId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT state, version FROM battles WHERE character_id = ?');
        $stmt->execute([$characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $data = json_decode($row['state'], true);

        return is_array($data) ? [Battle::fromArray($data), (int) $row['version']] : null;
    }

    public function create(int $characterId, Battle $battle, DateTimeImmutable $now): void
    {
        $this->delete($characterId);
        $this->pdo->prepare('INSERT INTO battles (character_id, version, state, updated_at) VALUES (?, 1, ?, ?)')
            ->execute([$characterId, self::encode($battle), $now->format(Clock::SQL_FORMAT)]);
    }

    /**
     * Guarda sólo si nadie más la modificó desde que se leyó con $version.
     * Así un doble clic no puede jugar dos veces el mismo turno.
     */
    public function save(int $characterId, Battle $battle, int $version, DateTimeImmutable $now): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE battles SET state = ?, version = version + 1, updated_at = ? WHERE character_id = ? AND version = ?'
        );
        $stmt->execute([self::encode($battle), $now->format(Clock::SQL_FORMAT), $characterId, $version]);

        return $stmt->rowCount() === 1;
    }

    public function delete(int $characterId): void
    {
        $this->pdo->prepare('DELETE FROM battles WHERE character_id = ?')->execute([$characterId]);
    }

    private static function encode(Battle $battle): string
    {
        return json_encode($battle->toArray(), JSON_THROW_ON_ERROR);
    }
}
