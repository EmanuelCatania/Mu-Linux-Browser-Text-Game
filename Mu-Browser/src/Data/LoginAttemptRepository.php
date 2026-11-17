<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Support\Clock;
use PDO;

/** Intentos de login fallidos, para frenar la fuerza bruta por usuario y por IP. */
final class LoginAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $username, string $ip, DateTimeImmutable $at): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (username, ip, attempted_at) VALUES (?, ?, ?)')
            ->execute([$username, $ip, $at->format(Clock::SQL_FORMAT)]);

        // Limpieza perezosa (sin cron, como el juego original): lo que tiene más de un día ya no cuenta para nada.
        $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')
            ->execute([$at->modify('-1 day')->format(Clock::SQL_FORMAT)]);
    }

    public function countByUsernameSince(string $username, DateTimeImmutable $since): int
    {
        return $this->count('username', $username, $since);
    }

    public function countByIpSince(string $ip, DateTimeImmutable $since): int
    {
        return $this->count('ip', $ip, $since);
    }

    public function clearForUsername(string $username): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
    }

    private function count(string $column, string $value, DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE $column = ? AND attempted_at >= ?");
        $stmt->execute([$value, $since->format(Clock::SQL_FORMAT)]);

        return (int) $stmt->fetchColumn();
    }
}
