<?php

declare(strict_types=1);

namespace Mu\Domain\Forum;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Data\NotificationRepository;
use Mu\Domain\Account\Account;
use Mu\Support\Clock;
use PDO;

/**
 * Foro básico: categorías fijas, temas y respuestas en texto plano.
 * Cualquiera lee; para escribir hace falta una cuenta. Los administradores fijan, cierran y borran.
 */
final class ForumService
{
    public const array CATEGORIES = ['news', 'bugs', 'suggestions', 'general'];
    /** Sólo los administradores abren temas acá. */
    public const array ADMIN_ONLY = ['news'];
    public const int TITLE_MAX = 80;
    public const int BODY_MAX = 5000;
    public const int PAGE_SIZE = 20;
    /** Segundos mínimos entre dos mensajes de la misma cuenta. */
    public const int COOLDOWN_SECONDS = 20;

    public function __construct(private readonly PDO $pdo, private readonly ?NotificationRepository $notifications = null)
    {
    }

    /** @return list<array{id: string, threads: int, posts: int, last: ?array{thread_id: int, title: string, author: string, at: DateTimeImmutable}}> */
    public function categories(): array
    {
        $counts = $this->pdo->query('SELECT category, COUNT(*) AS threads, SUM(replies) + COUNT(*) AS posts FROM forum_threads GROUP BY category')
            ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        $last = $this->pdo->prepare(
            'SELECT t.id, t.title, t.last_post_at, a.username FROM forum_threads t
               JOIN forum_posts p ON p.id = (SELECT MAX(id) FROM forum_posts WHERE thread_id = t.id)
               JOIN accounts a ON a.id = p.account_id
              WHERE t.category = ? ORDER BY t.last_post_at DESC, t.id DESC LIMIT 1'
        );
        $out = [];
        foreach (self::CATEGORIES as $category) {
            $last->execute([$category]);
            $row = $last->fetch(PDO::FETCH_ASSOC);
            $out[] = [
                'id' => $category,
                'threads' => (int) ($counts[$category]['threads'] ?? 0),
                'posts' => (int) ($counts[$category]['posts'] ?? 0),
                'last' => $row === false ? null : [
                    'thread_id' => (int) $row['id'],
                    'title' => $row['title'],
                    'author' => $row['username'],
                    'at' => self::date($row['last_post_at']),
                ],
            ];
        }

        return $out;
    }

    public function countThreads(string $category): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_threads WHERE category = ?');
        $stmt->execute([$category]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array<string, mixed>> primero los fijados, después por último mensaje */
    public function threads(string $category, int $page): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.title, t.replies, t.locked, t.pinned, t.created_at, t.last_post_at, a.username AS author
               FROM forum_threads t JOIN accounts a ON a.id = t.account_id
              WHERE t.category = ? ORDER BY t.pinned DESC, t.last_post_at DESC, t.id DESC
              LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (max(1, $page) - 1) * self::PAGE_SIZE
        );
        $stmt->execute([$category]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'replies' => (int) $r['replies'],
            'locked' => (bool) $r['locked'],
            'pinned' => (bool) $r['pinned'],
            'author' => $r['author'],
            'last_post_at' => self::date($r['last_post_at']),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return ?array{id: int, category: string, title: string, replies: int, locked: bool, pinned: bool, account_id: int} */
    public function thread(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, category, title, replies, locked, pinned, account_id FROM forum_threads WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);

        return $r === false ? null : [
            'id' => (int) $r['id'],
            'category' => $r['category'],
            'title' => $r['title'],
            'replies' => (int) $r['replies'],
            'locked' => (bool) $r['locked'],
            'pinned' => (bool) $r['pinned'],
            'account_id' => (int) $r['account_id'],
        ];
    }

    /** @return list<array{id: int, body: string, author: string, author_admin: bool, character: ?string, class: ?int, at: DateTimeImmutable, first: bool}> */
    public function posts(int $threadId, int $page): array
    {
        $first = $this->pdo->prepare('SELECT MIN(id) FROM forum_posts WHERE thread_id = ?');
        $first->execute([$threadId]);
        $firstId = (int) $first->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.body, p.created_at, a.username, a.is_admin, c.name AS character_name, c.class
               FROM forum_posts p JOIN accounts a ON a.id = p.account_id
               LEFT JOIN characters c ON c.id = a.active_character_id
              WHERE p.thread_id = ? ORDER BY p.id
              LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (max(1, $page) - 1) * self::PAGE_SIZE
        );
        $stmt->execute([$threadId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'body' => $r['body'],
            'author' => $r['username'],
            'author_admin' => (bool) $r['is_admin'],
            'character' => $r['character_name'],
            'class' => $r['class'] === null ? null : (int) $r['class'],
            'at' => self::date($r['created_at']),
            'first' => (int) $r['id'] === $firstId,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Para la moderación: los últimos mensajes de todo el foro, o de una cuenta.
     *
     * @return list<array{id: int, body: string, author: string, account_id: int, banned: bool, thread_id: int, title: string, category: string, locked: bool, at: DateTimeImmutable, first: bool}>
     */
    public function recentPosts(int $page, string $author = ''): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.body, p.created_at, a.username, a.id AS account_id, a.banned_at, t.id AS thread_id, t.title, t.category, t.locked,
                    (SELECT MIN(id) FROM forum_posts f WHERE f.thread_id = t.id) AS first_id
               FROM forum_posts p JOIN accounts a ON a.id = p.account_id JOIN forum_threads t ON t.id = p.thread_id'
            . ($author === '' ? '' : ' WHERE LOWER(a.username) = LOWER(?)')
            . ' ORDER BY p.id DESC LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . (max(1, $page) - 1) * self::PAGE_SIZE
        );
        $stmt->execute($author === '' ? [] : [$author]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'body' => $r['body'],
            'author' => $r['username'],
            'account_id' => (int) $r['account_id'],
            'banned' => $r['banned_at'] !== null,
            'thread_id' => (int) $r['thread_id'],
            'title' => $r['title'],
            'category' => $r['category'],
            'locked' => (bool) $r['locked'],
            'at' => self::date($r['created_at']),
            'first' => (int) $r['id'] === (int) $r['first_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countPosts(string $author = ''): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM forum_posts p JOIN accounts a ON a.id = p.account_id'
            . ($author === '' ? '' : ' WHERE LOWER(a.username) = LOWER(?)'));
        $stmt->execute($author === '' ? [] : [$author]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{id: int, title: string, category: string, locked: bool, pinned: bool, replies: int}> temas fijados o cerrados */
    public function flaggedThreads(): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'title' => $r['title'],
            'category' => $r['category'],
            'locked' => (bool) $r['locked'],
            'pinned' => (bool) $r['pinned'],
            'replies' => (int) $r['replies'],
        ], $this->pdo->query('SELECT id, title, category, locked, pinned, replies FROM forum_threads WHERE locked = 1 OR pinned = 1 ORDER BY pinned DESC, last_post_at DESC')->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @throws ForumException */
    public function createThread(Account $account, string $category, string $title, string $body, DateTimeImmutable $now): int
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new ForumException('forum.error_category');
        }
        if (in_array($category, self::ADMIN_ONLY, true) && !$account->isAdmin) {
            throw new ForumException('forum.error_admin_only');
        }
        $title = self::clean($title, self::TITLE_MAX, false);
        if (mb_strlen($title) < 3) {
            throw new ForumException('forum.error_title');
        }
        $body = $this->checkBody($account, $body, $now);

        $at = $now->format(Clock::SQL_FORMAT);
        $this->pdo->prepare('INSERT INTO forum_threads (category, title, account_id, created_at, last_post_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$category, $title, $account->id, $at, $at]);
        $id = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO forum_posts (thread_id, account_id, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$id, $account->id, $body, $at]);

        return $id;
    }

    /**
     * @return int página donde quedó la respuesta
     *
     * @throws ForumException
     */
    public function reply(Account $account, int $threadId, string $body, DateTimeImmutable $now): int
    {
        $thread = $this->thread($threadId) ?? throw new ForumException('forum.error_thread');
        if ($thread['locked'] && !$account->isAdmin) {
            throw new ForumException('forum.error_locked');
        }
        $body = $this->checkBody($account, $body, $now);

        $at = $now->format(Clock::SQL_FORMAT);
        $this->pdo->prepare('INSERT INTO forum_posts (thread_id, account_id, body, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$threadId, $account->id, $body, $at]);
        $this->pdo->prepare('UPDATE forum_threads SET replies = replies + 1, last_post_at = ? WHERE id = ?')->execute([$at, $threadId]);
        $page = intdiv($thread['replies'] + 1, self::PAGE_SIZE) + 1;
        if ($thread['account_id'] !== $account->id) {
            $this->notifications?->notify($thread['account_id'], 'notify.forum_reply', ['name' => $account->username, 'title' => $thread['title']], '/forum/thread/' . $threadId . '?page=' . $page . '#last');
        }

        return intdiv($thread['replies'] + 1, self::PAGE_SIZE) + 1;
    }

    /** Borra un mensaje; si es el primero, se va el tema entero. @return ?string categoría si se borró el tema */
    public function deletePost(int $postId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT thread_id FROM forum_posts WHERE id = ?');
        $stmt->execute([$postId]);
        $threadId = $stmt->fetchColumn();
        if ($threadId === false) {
            return null;
        }
        $thread = $this->thread((int) $threadId);
        $first = $this->pdo->prepare('SELECT MIN(id) FROM forum_posts WHERE thread_id = ?');
        $first->execute([$threadId]);
        if ((int) $first->fetchColumn() === $postId) {
            $this->deleteThread((int) $threadId);

            return $thread['category'] ?? null;
        }

        $this->pdo->prepare('DELETE FROM forum_posts WHERE id = ?')->execute([$postId]);
        $this->pdo->prepare(
            'UPDATE forum_threads SET replies = replies - 1,
                    last_post_at = (SELECT MAX(created_at) FROM forum_posts WHERE thread_id = ?) WHERE id = ?'
        )->execute([$threadId, $threadId]);

        return null;
    }

    public function deleteThread(int $threadId): void
    {
        $this->pdo->prepare('DELETE FROM forum_posts WHERE thread_id = ?')->execute([$threadId]);
        $this->pdo->prepare('DELETE FROM forum_threads WHERE id = ?')->execute([$threadId]);
    }

    public function setFlag(int $threadId, string $flag, bool $on): void
    {
        if (!in_array($flag, ['locked', 'pinned'], true)) {
            return;
        }
        $this->pdo->prepare("UPDATE forum_threads SET $flag = ? WHERE id = ?")->execute([$on ? 1 : 0, $threadId]);
    }

    public function postThread(int $postId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT thread_id FROM forum_posts WHERE id = ?');
        $stmt->execute([$postId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @throws ForumException */
    private function checkBody(Account $account, string $body, DateTimeImmutable $now): string
    {
        $body = self::clean($body, self::BODY_MAX, true);
        if (mb_strlen($body) < 2) {
            throw new ForumException('forum.error_body');
        }
        $last = $this->pdo->prepare('SELECT MAX(created_at) FROM forum_posts WHERE account_id = ?');
        $last->execute([$account->id]);
        $lastAt = $last->fetchColumn();
        if (is_string($lastAt) && $lastAt > $now->modify('-' . self::COOLDOWN_SECONDS . ' seconds')->format(Clock::SQL_FORMAT)) {
            throw new ForumException('forum.error_too_fast');
        }

        return $body;
    }

    /** Texto plano: sin caracteres de control (salvo saltos de línea en el cuerpo) y con largo máximo. */
    private static function clean(string $text, int $max, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace($multiline ? '/[\x00-\x09\x0B-\x1F\x7F]+/u' : '/[\x00-\x1F\x7F]+/u', ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        if (!$multiline) {
            $text = preg_replace('/ {2,}/', ' ', $text) ?? '';
        }

        return mb_substr(trim($text), 0, $max);
    }

    private static function date(string $sql): DateTimeImmutable
    {
        return new DateTimeImmutable($sql, new DateTimeZone('UTC'));
    }
}
