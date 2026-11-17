<?php

declare(strict_types=1);

namespace Mu\Data;

use DateTimeImmutable;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Support\Clock;
use PDO;

final class CharacterRepository
{
    private const string COLUMNS = 'id, account_id, name, class, level, strength, agility, stamina, energy,
        points, fruits, experience, hp_cur, mp_cur, quest_id, quest_score, event_map, guild_id, guild_invite_id, resets, evo_step, command, master_level, master_exp, master_points, evo_progress';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?Character
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM characters WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<Character> en orden de creación */
    public function findByName(string $name): ?Character
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM characters WHERE LOWER(name) = LOWER(?)');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /** Marca que el personaje está conectado, como mucho una vez por minuto para no escribir en cada pedido. */
    public function touchSeen(int $id, DateTimeImmutable $now): void
    {
        $this->pdo->prepare('UPDATE characters SET last_seen_at = ? WHERE id = ? AND (last_seen_at IS NULL OR last_seen_at < ?)')
            ->execute([$now->format(Clock::SQL_FORMAT), $id, $now->modify('-60 seconds')->format(Clock::SQL_FORMAT)]);
    }

    /**
     * Personajes vistos desde $since, con su clan y dónde están peleando.
     *
     * @return list<array{name: string, class: int, level: int, guild: ?string, location: ?string}>
     */
    public function online(DateTimeImmutable $since): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.name, c.class, c.level, g.name AS guild, p.location
               FROM characters c
               LEFT JOIN guilds g ON g.id = c.guild_id
               LEFT JOIN presence p ON p.character_id = c.id
              WHERE c.last_seen_at >= ? ORDER BY c.level DESC, c.name LIMIT 200'
        );
        $stmt->execute([$since->format(Clock::SQL_FORMAT)]);

        return array_map(static fn (array $r): array => [
            'name' => $r['name'], 'class' => (int) $r['class'], 'level' => (int) $r['level'],
            'guild' => $r['guild'], 'location' => $r['location'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countOnline(DateTimeImmutable $since): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM characters WHERE last_seen_at >= ?');
        $stmt->execute([$since->format(Clock::SQL_FORMAT)]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ranking por experiencia, por monstruos cazados o por duelos ganados.
     *
     * @param string $order 'experience', 'kills' o 'pvp'
     *
     * @param list<int> $classes filtro por clase (vacío = todas)
     *
     * @return array{total: int, rows: list<array{name: string, class: int, level: int, experience: int, kills: int, guild: ?string, pvp_wins: int, pvp_losses: int}>}
     */
    public function ranking(array $classes, string $order, int $limit, int $offset): array
    {
        $where = $classes === [] ? '' : 'WHERE c.class IN (' . implode(',', array_map(intval(...), $classes)) . ')';
        $order = match ($order) {
            'kills' => 'c.monsters_killed DESC, c.experience DESC',
            'pvp' => 'c.pvp_wins DESC, c.pvp_losses, c.experience DESC',
            default => 'c.resets DESC, c.master_level DESC, c.experience DESC, c.monsters_killed DESC',
        };

        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM characters c $where")->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT c.name, c.class, c.level, c.resets, c.experience, c.monsters_killed, c.pvp_wins, c.pvp_losses, g.name AS guild
               FROM characters c LEFT JOIN guilds g ON g.id = c.guild_id
               $where ORDER BY $order, c.id LIMIT ? OFFSET ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => $total, 'rows' => array_map(static fn (array $r): array => [
            'name' => $r['name'], 'class' => (int) $r['class'], 'level' => (int) $r['level'],
            'experience' => (int) $r['experience'], 'kills' => (int) $r['monsters_killed'], 'guild' => $r['guild'],
            'pvp_wins' => (int) $r['pvp_wins'], 'pvp_losses' => (int) $r['pvp_losses'], 'resets' => (int) $r['resets'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC))];
    }

    public function findByAccount(int $accountId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM characters WHERE account_id = ? ORDER BY id');
        $stmt->execute([$accountId]);

        return array_map($this->hydrate(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countByAccount(int $accountId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM characters WHERE account_id = ?');
        $stmt->execute([$accountId]);

        return (int) $stmt->fetchColumn();
    }

    public function nameExists(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM characters WHERE LOWER(name) = LOWER(?) LIMIT 1');
        $stmt->execute([$name]);

        return $stmt->fetchColumn() !== false;
    }

    public function insert(int $accountId, string $name, CharacterClass $class, DateTimeImmutable $now): Character
    {
        $start = $class->startingStats();

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO characters
                    (account_id, name, class, level, strength, agility, stamina, energy, command, hp_cur, mp_cur, created_at)
                 VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $accountId,
                $name,
                $class->value,
                $start['strength'],
                $start['agility'],
                $start['stamina'],
                $start['energy'],
                $start['command'],
                $start['hp'],
                $start['mp'],
                $now->format(Clock::SQL_FORMAT),
            ]);
            $id = (int) $this->pdo->lastInsertId();

            $addSkill = $this->pdo->prepare('INSERT INTO character_skills (character_id, skill) VALUES (?, ?)');
            foreach ($start['skills'] as $skill) {
                $addSkill->execute([$id, $skill]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->findById($id) ?? throw new \LogicException('El personaje recién creado no existe.');
    }

    public function delete(int $id): void
    {
        // si lideraba un party, se disuelve
        $this->pdo->prepare('UPDATE characters SET party_id = NULL WHERE party_id = ?')->execute([$id]);
        $this->pdo->prepare('UPDATE characters SET party_invite_id = NULL WHERE party_invite_id = ?')->execute([$id]);
        $this->pdo->prepare('DELETE FROM characters WHERE id = ?')->execute([$id]);
    }

    /** Resta un punto libre y lo suma al atributo. Devuelve false si no había puntos. */
    public function spendPoint(int $id, string $stat): bool
    {
        // El nombre de columna no puede ir como parámetro: se valida contra la lista fija.
        if (!in_array($stat, Character::ALL_STATS, true)) {
            throw new \InvalidArgumentException("Atributo desconocido: $stat");
        }

        $stmt = $this->pdo->prepare(
            "UPDATE characters SET points = points - 1, $stat = $stat + 1 WHERE id = ? AND points > 0"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** Empieza una misión (o la abandona, con null). El progreso vuelve a cero. */
    public function setQuest(int $id, ?int $questId): void
    {
        $this->pdo->prepare('UPDATE characters SET quest_id = ?, quest_score = 0 WHERE id = ?')->execute([$questId, $id]);
    }

    public function addQuestScore(int $id, int $kills): void
    {
        $this->pdo->prepare('UPDATE characters SET quest_score = quest_score + ? WHERE id = ?')->execute([$kills, $id]);
    }

    public function completeQuest(int $id, int $questId): void
    {
        $this->pdo->prepare('INSERT INTO character_quests_done (character_id, quest_id) VALUES (?, ?)')->execute([$id, $questId]);
        $this->setQuest($id, null);
    }

    /** @return list<int> misiones ya cobradas */
    public function questsDone(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT quest_id FROM character_quests_done WHERE character_id = ? ORDER BY quest_id');
        $stmt->execute([$id]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function setEventMap(int $id, ?string $map): void
    {
        $this->pdo->prepare('UPDATE characters SET event_map = ? WHERE id = ?')->execute([$map, $id]);
    }

    public function setVitals(int $id, int $hp, int $mp): void
    {
        $this->pdo->prepare('UPDATE characters SET hp_cur = ?, mp_cur = ? WHERE id = ?')->execute([$hp, $mp, $id]);
    }

    public function addSkill(int $id, string $skill): void
    {
        $this->pdo->prepare('INSERT INTO character_skills (character_id, skill) VALUES (?, ?)')->execute([$id, $skill]);
    }

    /** Una fruta que funcionó: suma puntos al atributo y cuenta una fruta más comida. */
    public function applyFruit(int $id, string $stat, int $points): void
    {
        if (!in_array($stat, Character::STATS, true)) {
            throw new \InvalidArgumentException("Atributo desconocido: $stat");
        }
        $this->pdo->prepare("UPDATE characters SET fruits = fruits + 1, $stat = $stat + ? WHERE id = ?")->execute([$points, $id]);
    }

    /** Guarda lo acumulado en una batalla. Nivel, experiencia, vida y maná son valores finales; puntos y bajas se suman. */
    public function saveProgress(int $id, int $level, int $addPoints, int $experience, int $hp, int $mp, int $addKills): void
    {
        $this->pdo->prepare(
            'UPDATE characters
                SET level = ?, points = points + ?, experience = ?, hp_cur = ?, mp_cur = ?,
                    monsters_killed = monsters_killed + ?
              WHERE id = ?'
        )->execute([$level, $addPoints, $experience, $hp, $mp, $addKills, $id]);
    }

    /** @return array<string, int> */
    private function masterRanks(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT node, `rank` FROM character_master WHERE character_id = ?');
        $stmt->execute([$id]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    /** Guarda el avance master de una batalla: nivel y experiencia son valores finales, los puntos se suman. */
    public function saveMaster(int $id, int $level, int $experience, int $addPoints): void
    {
        $this->pdo->prepare('UPDATE characters SET master_level = ?, master_exp = ?, master_points = master_points + ? WHERE id = ?')
            ->execute([$level, $experience, $addPoints, $id]);
    }

    /** Gasta un punto master en un rango de la habilidad. Devuelve false si no quedaban puntos. */
    public function spendMasterPoint(int $id, string $node): bool
    {
        $spend = $this->pdo->prepare('UPDATE characters SET master_points = master_points - 1 WHERE id = ? AND master_points > 0');
        $spend->execute([$id]);
        if ($spend->rowCount() !== 1) {
            return false;
        }
        $raise = $this->pdo->prepare('UPDATE character_master SET `rank` = `rank` + 1 WHERE character_id = ? AND node = ?');
        $raise->execute([$id, $node]);
        if ($raise->rowCount() === 0) {
            $this->pdo->prepare('INSERT INTO character_master (character_id, node, `rank`) VALUES (?, ?, 1)')->execute([$id, $node]);
        }

        return true;
    }

    /** @param array{items?: list<string>, kills?: array<string, int>} $progress */
    public function setEvoProgress(int $id, int $step, array $progress): void
    {
        $this->pdo->prepare('UPDATE characters SET evo_step = ?, evo_progress = ? WHERE id = ?')
            ->execute([$step, json_encode((object) $progress, JSON_THROW_ON_ERROR), $id]);
    }

    public function setEvolution(int $id, int $step): void
    {
        $this->pdo->prepare('UPDATE characters SET evo_step = ? WHERE id = ?')->execute([$step, $id]);
    }

    public function evolve(int $id, CharacterClass $class, int $step): void
    {
        $this->pdo->prepare('UPDATE characters SET class = ?, evo_step = ? WHERE id = ?')->execute([$class->value, $step, $id]);
    }

    public function addPoints(int $id, int $points): void
    {
        $this->pdo->prepare('UPDATE characters SET points = points + ? WHERE id = ?')->execute([$points, $id]);
    }

    /**
     * Reset: vuelve al nivel 1 conservando atributos, puntos, habilidades y clase, y puede repetir las misiones.
     *
     * @return bool false si el personaje no había llegado a $requiredLevel
     */
    public function reset(int $id, int $requiredLevel): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE characters SET level = 1, experience = 0, resets = resets + 1, quest_id = NULL, quest_score = 0
              WHERE id = ? AND level >= ?'
        );
        $stmt->execute([$id, $requiredLevel]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $this->pdo->prepare('DELETE FROM character_quests_done WHERE character_id = ?')->execute([$id]);

        return true;
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Character
    {
        $stmt = $this->pdo->prepare('SELECT skill FROM character_skills WHERE character_id = ? ORDER BY skill');
        $stmt->execute([$row['id']]);

        return new Character(
            id: (int) $row['id'],
            accountId: (int) $row['account_id'],
            name: $row['name'],
            class: CharacterClass::from((int) $row['class']),
            level: (int) $row['level'],
            strength: (int) $row['strength'],
            agility: (int) $row['agility'],
            stamina: (int) $row['stamina'],
            energy: (int) $row['energy'],
            points: (int) $row['points'],
            fruits: (int) $row['fruits'],
            experience: (int) $row['experience'],
            hpCur: (int) $row['hp_cur'],
            mpCur: (int) $row['mp_cur'],
            skills: array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN)),
            questId: $row['quest_id'] === null ? null : (int) $row['quest_id'],
            questScore: (int) $row['quest_score'],
            eventMap: $row['event_map'],
            guildId: $row['guild_id'] === null ? null : (int) $row['guild_id'],
            guildInviteId: $row['guild_invite_id'] === null ? null : (int) $row['guild_invite_id'],
            resets: (int) ($row['resets'] ?? 0),
            evoStep: (int) ($row['evo_step'] ?? 0),
            command: (int) ($row['command'] ?? 0),
            masterLevel: (int) ($row['master_level'] ?? 0),
            masterExp: (int) ($row['master_exp'] ?? 0),
            masterPoints: (int) ($row['master_points'] ?? 0),
            evoProgress: is_array($progress = json_decode((string) ($row['evo_progress'] ?? '{}'), true)) ? $progress : [],
            master: (int) ($row['master_level'] ?? 0) > 0 ? $this->masterRanks((int) $row['id']) : [],
        );
    }
}
