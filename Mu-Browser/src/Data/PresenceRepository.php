<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Support\Clock;
use PDO;
use PDOException;

/** Quién está peleando en cada spot. Un personaje deja de contar si pasa 5 minutos sin jugar un turno. */
final class PresenceRepository
{
    public const int TIMEOUT_SECONDS = 300;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function touch(int $characterId, string $location, int $expRate, DateTimeImmutable $now): void
    {
        $at = $now->format(Clock::SQL_FORMAT);
        $update = $this->pdo->prepare('UPDATE presence SET location = ?, exp_rate = ?, updated_at = ? WHERE character_id = ?');
        $update->execute([$location, $expRate, $at, $characterId]);
        if ($update->rowCount() > 0 || $this->exists($characterId)) {
            return;
        }

        try {
            $this->pdo->prepare('INSERT INTO presence (character_id, location, exp_rate, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$characterId, $location, $expRate, $at]);
        } catch (PDOException $e) {
            // Otro pedido del mismo personaje la insertó primero: no pasa nada.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    public function remove(int $characterId): void
    {
        $this->pdo->prepare('DELETE FROM presence WHERE character_id = ?')->execute([$characterId]);
    }

    /** @return list<array{character_id: int, name: string, exp_rate: int, guild_id: ?int}> personajes activos en el lugar */
    public function at(string $location, DateTimeImmutable $now): array
    {
        $this->pdo->prepare('DELETE FROM presence WHERE updated_at < ?')
            ->execute([$now->modify('-' . self::TIMEOUT_SECONDS . ' seconds')->format(Clock::SQL_FORMAT)]);

        $stmt = $this->pdo->prepare(
            'SELECT p.character_id, c.name, p.exp_rate, c.guild_id
               FROM presence p JOIN characters c ON c.id = p.character_id
              WHERE p.location = ? ORDER BY c.name'
        );
        $stmt->execute([$location]);

        return array_map(
            static fn (array $r): array => [
                'character_id' => (int) $r['character_id'],
                'name' => $r['name'],
                'exp_rate' => (int) $r['exp_rate'],
                'guild_id' => $r['guild_id'] === null ? null : (int) $r['guild_id'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function exists(int $characterId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM presence WHERE character_id = ?');
        $stmt->execute([$characterId]);

        return $stmt->fetchColumn() !== false;
    }
}
