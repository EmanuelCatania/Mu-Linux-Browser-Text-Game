<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Domain\Account\Account;
use Mu\Support\Clock;
use PDO;

final class AccountRepository
{
    private const string COLUMNS = 'id, username, email, password_hash, locale, zen, active_character_id, store_open, is_admin, banned_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Account
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM accounts WHERE id = ?', [$id]);
    }

    /** La comparación no distingue mayúsculas: "Tester" y "tester" son la misma cuenta. */
    public function findByUsername(string $username): ?Account
    {
        return $this->one('SELECT ' . self::COLUMNS . ' FROM accounts WHERE LOWER(username) = LOWER(?)', [$username]);
    }

    public function emailExists(string $email, ?int $exceptAccountId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM accounts WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1');
        $stmt->execute([$email, $exceptAccountId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(
        string $username,
        string $email,
        string $passwordHash,
        string $locale,
        string $ip,
        DateTimeImmutable $now,
    ): Account {
        $this->pdo->prepare(
            'INSERT INTO accounts (username, email, password_hash, locale, register_ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$username, $email, $passwordHash, $locale, $ip, $now->format(Clock::SQL_FORMAT)]);

        return new Account((int) $this->pdo->lastInsertId(), $username, $email, $passwordHash, $locale);
    }

    public function updatePasswordHash(int $id, string $hash): void
    {
        $this->pdo->prepare('UPDATE accounts SET password_hash = ? WHERE id = ?')->execute([$hash, $id]);
    }

    public function updateEmail(int $id, string $email): void
    {
        $this->pdo->prepare('UPDATE accounts SET email = ? WHERE id = ?')->execute([$email, $id]);
    }

    public function updateLocale(int $id, string $locale): void
    {
        $this->pdo->prepare('UPDATE accounts SET locale = ? WHERE id = ?')->execute([$locale, $id]);
    }

    public function setActiveCharacter(int $id, ?int $characterId): void
    {
        $this->pdo->prepare('UPDATE accounts SET active_character_id = ? WHERE id = ?')->execute([$characterId, $id]);
    }

    public function setStoreOpen(int $id, bool $open): void
    {
        $this->pdo->prepare('UPDATE accounts SET store_open = ? WHERE id = ?')->execute([$open ? 1 : 0, $id]);
    }

    /** Descuenta zen sólo si alcanza. Devuelve false si no había suficiente. */
    public function spendZen(int $id, int $amount): bool
    {
        $stmt = $this->pdo->prepare('UPDATE accounts SET zen = zen - ? WHERE id = ? AND zen >= ?');
        $stmt->execute([$amount, $id, $amount]);

        return $stmt->rowCount() === 1;
    }

    public function addZen(int $id, int $amount): void
    {
        $this->pdo->prepare('UPDATE accounts SET zen = zen + ? WHERE id = ?')->execute([$amount, $id]);
    }

    public function touchLastLogin(int $id, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE accounts SET last_login_at = ? WHERE id = ?')
            ->execute([$now->format(Clock::SQL_FORMAT), $id]);
    }

    /**
     * Para el panel: cuentas cuyo usuario o email contiene el texto, con su personaje más alto.
     *
     * @return list<array{id: int, username: string, email: string, zen: int, is_admin: bool, banned: bool, created_at: string, last_login_at: ?string, characters: int, top: ?string}>
     */
    public function search(string $query, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT a.id, a.username, a.email, a.zen, a.is_admin, a.banned_at, a.created_at, a.last_login_at,
                    (SELECT COUNT(*) FROM characters c WHERE c.account_id = a.id) AS characters,
                    (SELECT c.name FROM characters c WHERE c.account_id = a.id ORDER BY c.level DESC, c.experience DESC LIMIT 1) AS top
               FROM accounts a
              WHERE a.username LIKE ? OR a.email LIKE ?
              ORDER BY a.id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset)
        );
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $stmt->execute([$like, $like]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'email' => $r['email'],
            'zen' => (int) $r['zen'],
            'is_admin' => (bool) $r['is_admin'],
            'banned' => $r['banned_at'] !== null,
            'created_at' => $r['created_at'],
            'last_login_at' => $r['last_login_at'],
            'characters' => (int) $r['characters'],
            'top' => $r['top'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countSearch(string $query): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM accounts WHERE username LIKE ? OR email LIKE ?');
        $like = '%' . addcslashes($query, '%_\\') . '%';
        $stmt->execute([$like, $like]);

        return (int) $stmt->fetchColumn();
    }

    public function setAdmin(int $id, bool $admin): void
    {
        $this->pdo->prepare('UPDATE accounts SET is_admin = ? WHERE id = ?')->execute([$admin ? 1 : 0, $id]);
    }

    public function setBanned(int $id, ?DateTimeImmutable $since): void
    {
        $this->pdo->prepare('UPDATE accounts SET banned_at = ? WHERE id = ?')->execute([$since?->format(Clock::SQL_FORMAT), $id]);
        if ($since !== null) {
            // la cuenta sale de la tienda personal y pierde los "recordarme"
            $this->pdo->prepare('UPDATE accounts SET store_open = 0 WHERE id = ?')->execute([$id]);
            $this->pdo->prepare('DELETE FROM remember_tokens WHERE account_id = ?')->execute([$id]);
        }
    }

    public function setZen(int $id, int $zen): void
    {
        $this->pdo->prepare('UPDATE accounts SET zen = ? WHERE id = ?')->execute([max(0, $zen), $id]);
    }

    /** @param list<int|string> $params */
    private function one(string $sql, array $params): ?Account
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new Account(
            (int) $row['id'],
            $row['username'],
            $row['email'],
            $row['password_hash'],
            $row['locale'],
            (int) $row['zen'],
            $row['active_character_id'] === null ? null : (int) $row['active_character_id'],
            (bool) $row['store_open'],
            (bool) ($row['is_admin'] ?? false),
            ($row['banned_at'] ?? null) !== null,
        );
    }
}
