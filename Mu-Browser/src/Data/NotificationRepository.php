<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Support\Clock;
use PDO;

/**
 * Avisos para una cuenta sobre cosas que pasaron mientras no miraba: duelos, ventas, invitaciones, respuestas.
 * El texto se guarda como clave de traducción con sus parámetros, así se muestra en el idioma de quien lo lee.
 */
final class NotificationRepository
{
    /** Cuántos se guardan por cuenta: al pasarse se borran los más viejos ya leídos. */
    public const int KEEP = 100;

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /** @param array<string, int|string> $params */
    public function notify(int $accountId, string $key, array $params = [], ?string $link = null): void
    {
        $this->pdo->prepare('INSERT INTO notifications (account_id, message, params, link, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$accountId, $key, json_encode((object) $params, JSON_THROW_ON_ERROR), $link, $this->clock->now()->format(Clock::SQL_FORMAT)]);
    }

    public function unread(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notifications WHERE account_id = ? AND read_at IS NULL');
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{id: int, key: string, params: array<string, int|string>, link: ?string, read: bool, at: DateTimeImmutable}> de la más nueva a la más vieja */
    public function latest(int $accountId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, message, params, link, read_at, created_at FROM notifications WHERE account_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        $stmt->execute([$accountId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'key' => $r['message'],
            'params' => is_array($p = json_decode((string) $r['params'], true)) ? $p : [],
            'link' => $r['link'],
            'read' => $r['read_at'] !== null,
            'at' => new DateTimeImmutable($r['created_at'], new DateTimeZone('UTC')),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markAllRead(int $accountId): void
    {
        $this->pdo->prepare('UPDATE notifications SET read_at = ? WHERE account_id = ? AND read_at IS NULL')
            ->execute([$this->clock->now()->format(Clock::SQL_FORMAT), $accountId]);

        // las leídas más viejas que las últimas KEEP se van
        $stmt = $this->pdo->prepare('SELECT id FROM notifications WHERE account_id = ? ORDER BY id DESC LIMIT 1 OFFSET ' . self::KEEP);
        $stmt->execute([$accountId]);
        $oldest = $stmt->fetchColumn();
        if ($oldest !== false) {
            $this->pdo->prepare('DELETE FROM notifications WHERE account_id = ? AND id <= ? AND read_at IS NOT NULL')->execute([$accountId, $oldest]);
        }
    }

    public function clear(int $accountId): void
    {
        $this->pdo->prepare('DELETE FROM notifications WHERE account_id = ? AND read_at IS NOT NULL')->execute([$accountId]);
    }
}
