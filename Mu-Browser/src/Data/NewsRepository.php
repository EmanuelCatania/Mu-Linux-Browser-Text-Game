<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Support\Clock;
use PDO;

/** Publicaciones de la portada. */
final class NewsRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id: int, title: string, body: string, image: ?string, published: bool, author: ?string, created_at: DateTimeImmutable}> de la más nueva a la más vieja */
    public function page(int $limit, int $offset, bool $onlyPublished = true): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id, n.title, n.body, n.image, n.published, a.username AS author, n.created_at
               FROM news n LEFT JOIN accounts a ON a.id = n.account_id'
            . ($onlyPublished ? ' WHERE n.published = 1' : '')
            . ' ORDER BY n.created_at DESC, n.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $stmt->execute();

        return array_map(self::hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function count(bool $onlyPublished = true): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM news' . ($onlyPublished ? ' WHERE published = 1' : ''))->fetchColumn();
    }

    /** @return ?array{id: int, title: string, body: string, image: ?string, published: bool, author: ?string, created_at: DateTimeImmutable} */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT n.id, n.title, n.body, n.image, n.published, a.username AS author, n.created_at
               FROM news n LEFT JOIN accounts a ON a.id = n.account_id WHERE n.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::hydrate($row);
    }

    public function insert(int $accountId, string $title, string $body, ?string $image, bool $published, DateTimeImmutable $now): int
    {
        $at = $now->format(Clock::SQL_FORMAT);
        $this->pdo->prepare('INSERT INTO news (title, body, image, published, account_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$title, $body, $image, $published ? 1 : 0, $accountId, $at, $at]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $title, string $body, ?string $image, bool $published, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE news SET title = ?, body = ?, image = ?, published = ?, updated_at = ? WHERE id = ?')
            ->execute([$title, $body, $image, $published ? 1 : 0, $now->format(Clock::SQL_FORMAT), $id]);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM news WHERE id = ?')->execute([$id]);
    }

    /** @param array<string, mixed> $r */
    private static function hydrate(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'body' => $r['body'],
            'image' => $r['image'],
            'published' => (bool) $r['published'],
            'author' => $r['author'],
            'created_at' => new DateTimeImmutable($r['created_at'], new DateTimeZone('UTC')),
        ];
    }
}
