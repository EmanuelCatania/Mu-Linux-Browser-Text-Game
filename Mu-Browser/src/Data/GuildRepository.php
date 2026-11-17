<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Support\Clock;
use PDO;

final class GuildRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, name: string, founder_id: int}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, founder_id FROM guilds WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => (int) $row['id'], 'name' => $row['name'], 'founder_id' => (int) $row['founder_id']];
    }

    public function nameExists(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM guilds WHERE LOWER(name) = LOWER(?)');
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }

    public function create(string $name, int $founderId, DateTimeImmutable $now): int
    {
        $this->pdo->prepare('INSERT INTO guilds (name, founder_id, created_at) VALUES (?, ?, ?)')
            ->execute([$name, $founderId, $now->format(Clock::SQL_FORMAT)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->setMembership($founderId, $id, null);

        return $id;
    }

    public function delete(int $id): void
    {
        // Las claves foráneas ya dejan en NULL a miembros e invitados; se hace explícito por las dudas.
        $this->pdo->prepare('UPDATE characters SET guild_id = NULL WHERE guild_id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE characters SET guild_invite_id = NULL WHERE guild_invite_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM guilds WHERE id = ?')->execute([$id]);
    }

    /** Fija el clan y la invitación pendiente de un personaje (null = ninguno). */
    public function setMembership(int $characterId, ?int $guildId, ?int $inviteId): void
    {
        $this->pdo->prepare('UPDATE characters SET guild_id = ?, guild_invite_id = ? WHERE id = ?')
            ->execute([$guildId, $inviteId, $characterId]);
    }

    /**
     * Miembros e invitados de un clan, de mayor a menor nivel.
     *
     * @return list<array{id: int, name: string, class: int, level: int, invited: bool}>
     */
    public function roster(int $guildId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, class, level, guild_id FROM characters
              WHERE guild_id = ? OR guild_invite_id = ? ORDER BY level DESC, name'
        );
        $stmt->execute([$guildId, $guildId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'class' => (int) $r['class'],
            'level' => (int) $r['level'],
            'invited' => $r['guild_id'] === null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
