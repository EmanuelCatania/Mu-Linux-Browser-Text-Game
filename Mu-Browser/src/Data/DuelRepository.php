<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Domain\Pvp\Duel;
use Mu\Support\Clock;
use PDO;

/**
 * Desafíos de PvP. Un duelo abierto (pending o answered) que nadie toca durante $expireSeconds se da por
 * vencido: las consultas de "abiertos" directamente no lo ven, sin necesidad de un proceso que lo cierre.
 */
final class DuelRepository
{
    private const string SELECT = 'SELECT d.*, a.name AS challenger_name, b.name AS target_name
          FROM duels d JOIN characters a ON a.id = d.challenger_id JOIN characters b ON b.id = d.target_id';

    public function __construct(private readonly PDO $pdo, private readonly int $expireSeconds = 600)
    {
    }

    public function find(int $id): ?Duel
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE d.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** El duelo abierto de una cuenta, como retadora o como desafiada (nunca hay más de uno). */
    public function openFor(int $accountId, DateTimeImmutable $now): ?Duel
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE (d.challenger_account_id = ? OR d.target_account_id = ?)
               AND d.status IN ('pending', 'answered') AND d.updated_at >= ? ORDER BY d.id DESC LIMIT 1"
        );
        $stmt->execute([$accountId, $accountId, $this->cutoff($now)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    /** Cuántos desafíos esperan respuesta de esta cuenta (para el aviso de la cabecera). */
    public function countIncoming(int $accountId, DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM duels WHERE target_account_id = ? AND status = 'pending' AND updated_at >= ?");
        $stmt->execute([$accountId, $this->cutoff($now)]);

        return (int) $stmt->fetchColumn();
    }

    public function insert(int $challengerId, int $challengerAccountId, int $targetId, int $targetAccountId, int $zen, DateTimeImmutable $now): int
    {
        $this->pdo->prepare(
            "INSERT INTO duels (challenger_id, target_id, challenger_account_id, target_account_id, zen, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, ?)"
        )->execute([$challengerId, $targetId, $challengerAccountId, $targetAccountId, $zen, $now->format(Clock::SQL_FORMAT), $now->format(Clock::SQL_FORMAT)]);

        return (int) $this->pdo->lastInsertId();
    }

    public function setStatus(int $id, string $status, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE duels SET status = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, $now->format(Clock::SQL_FORMAT), $id]);
    }

    /** @param array<string, mixed> $result */
    public function finish(int $id, ?int $winnerId, array $result, DateTimeImmutable $now): void
    {
        $this->pdo->prepare("UPDATE duels SET status = 'finished', winner_id = ?, result = ?, updated_at = ? WHERE id = ?")
            ->execute([$winnerId, json_encode($result, JSON_THROW_ON_ERROR), $now->format(Clock::SQL_FORMAT), $id]);
    }

    /** Marca como vencidos los duelos abiertos que ya nadie puede continuar. */
    public function expireOld(DateTimeImmutable $now): void
    {
        $this->pdo->prepare("UPDATE duels SET status = 'expired' WHERE status IN ('pending', 'answered') AND updated_at < ?")
            ->execute([$this->cutoff($now)]);
    }

    /** @return list<Duel> últimos duelos terminados del personaje, del más nuevo al más viejo */
    public function history(int $characterId, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . " WHERE (d.challenger_id = ? OR d.target_id = ?) AND d.status = 'finished' ORDER BY d.id DESC LIMIT ?"
        );
        $stmt->bindValue(1, $characterId, PDO::PARAM_INT);
        $stmt->bindValue(2, $characterId, PDO::PARAM_INT);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function addResult(int $winnerId, int $loserId): void
    {
        $this->pdo->prepare('UPDATE characters SET pvp_wins = pvp_wins + 1 WHERE id = ?')->execute([$winnerId]);
        $this->pdo->prepare('UPDATE characters SET pvp_losses = pvp_losses + 1 WHERE id = ?')->execute([$loserId]);
    }

    /** @return array{wins: int, losses: int} */
    public function record(int $characterId): array
    {
        $stmt = $this->pdo->prepare('SELECT pvp_wins, pvp_losses FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return ['wins' => (int) ($row['pvp_wins'] ?? 0), 'losses' => (int) ($row['pvp_losses'] ?? 0)];
    }

    public function isOnline(int $characterId, DateTimeImmutable $since): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM characters WHERE id = ? AND last_seen_at >= ?');
        $stmt->execute([$characterId, $since->format(Clock::SQL_FORMAT)]);

        return $stmt->fetchColumn() !== false;
    }

    private function cutoff(DateTimeImmutable $now): string
    {
        return $now->modify('-' . $this->expireSeconds . ' seconds')->format(Clock::SQL_FORMAT);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Duel
    {
        return new Duel(
            id: (int) $row['id'],
            challengerId: (int) $row['challenger_id'],
            targetId: (int) $row['target_id'],
            challengerAccountId: (int) $row['challenger_account_id'],
            targetAccountId: (int) $row['target_account_id'],
            challengerName: $row['challenger_name'],
            targetName: $row['target_name'],
            zen: (int) $row['zen'],
            status: $row['status'],
            winnerId: $row['winner_id'] === null ? null : (int) $row['winner_id'],
            result: $row['result'] === null ? null : json_decode($row['result'], true, 512, JSON_THROW_ON_ERROR),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }
}
