<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Support\Clock;
use PDO;

final class RememberTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        string $selector,
        string $tokenHash,
        int $accountId,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): void {
        $this->pdo->prepare('DELETE FROM remember_tokens WHERE expires_at < ?')
            ->execute([$now->format(Clock::SQL_FORMAT)]);

        $this->pdo->prepare(
            'INSERT INTO remember_tokens (selector, token_hash, account_id, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $selector,
            $tokenHash,
            $accountId,
            $expiresAt->format(Clock::SQL_FORMAT),
            $now->format(Clock::SQL_FORMAT),
        ]);
    }

    /** @return array{token_hash: string, account_id: int|string, expires_at: string}|null */
    public function find(string $selector): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT token_hash, account_id, expires_at FROM remember_tokens WHERE selector = ?'
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function delete(string $selector): void
    {
        $this->pdo->prepare('DELETE FROM remember_tokens WHERE selector = ?')->execute([$selector]);
    }

    public function deleteForAccount(int $accountId): void
    {
        $this->pdo->prepare('DELETE FROM remember_tokens WHERE account_id = ?')->execute([$accountId]);
    }
}
