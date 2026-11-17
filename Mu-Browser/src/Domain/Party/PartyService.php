<?php

declare(strict_types=1);

namespace Mu\Domain\Party;

use DateTimeImmutable;
use Mu\Data\PresenceRepository;
use Mu\Data\NotificationRepository;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\InventoryException;
use Mu\Support\Clock;
use PDO;

/**
 * Party: hasta `max` personajes que, mientras pelean en el mismo mapa, se pasan parte de la experiencia que ganan.
 * El que gana no pierde nada: cada compañero presente en el mapa recibe además un porcentaje.
 *
 * No hay tabla de parties: el id del party es el id del personaje que lo armó (su líder). Si el líder se va, se disuelve.
 * La experiencia recibida queda pendiente en characters.party_exp y entra en la próxima ronda de quien la recibe.
 */
final class PartyService
{
    /** @param array{share_percent: int, max: int} $config */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly ?NotificationRepository $notifications = null,
    ) {
    }

    /**
     * Personajes que están peleando ahora en el mapa.
     *
     * @return list<array{id: int, name: string, class: string, level: int, spot: string, party_id: ?int}>
     */
    public function inMap(string $map, DateTimeImmutable $now): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.name, c.class, c.level, p.location, c.party_id
               FROM presence p JOIN characters c ON c.id = p.character_id
              WHERE p.location LIKE ? AND p.updated_at >= ? ORDER BY c.name'
        );
        $stmt->execute([$map . ':%', $now->modify('-' . PresenceRepository::TIMEOUT_SECONDS . ' seconds')->format(Clock::SQL_FORMAT)]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'class' => CharacterClass::tryFrom((int) $r['class'])?->label() ?? '',
            'level' => (int) $r['level'],
            'spot' => substr($r['location'], strlen($map) + 1),
            'party_id' => $r['party_id'] === null ? null : (int) $r['party_id'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return list<array{id: int, name: string, class: string, level: int, leader: bool}> */
    public function members(int $partyId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, class, level FROM characters WHERE party_id = ? ORDER BY id = ? DESC, name');
        $stmt->execute([$partyId, $partyId]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'class' => CharacterClass::tryFrom((int) $r['class'])?->label() ?? '',
            'level' => (int) $r['level'],
            'leader' => (int) $r['id'] === $partyId,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{party: ?int, invite: ?int, inviter: ?string} */
    public function state(int $characterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.party_id, c.party_invite_id, l.name AS inviter
               FROM characters c LEFT JOIN characters l ON l.id = c.party_invite_id WHERE c.id = ?'
        );
        $stmt->execute([$characterId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['party_id' => null, 'party_invite_id' => null, 'inviter' => null];

        return [
            'party' => $row['party_id'] === null ? null : (int) $row['party_id'],
            'invite' => $row['party_invite_id'] === null ? null : (int) $row['party_invite_id'],
            'inviter' => $row['inviter'],
        ];
    }

    /** @throws InventoryException */
    public function invite(Character $from, string $targetName): void
    {
        $stmt = $this->pdo->prepare('SELECT id, party_id, account_id, name FROM characters WHERE LOWER(name) = LOWER(?)');
        $stmt->execute([trim($targetName)]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($target === false || (int) $target['id'] === $from->id) {
            throw new InventoryException('party.error_target');
        }
        if ($target['party_id'] !== null) {
            throw new InventoryException('party.error_has_party');
        }

        $partyId = $this->state($from->id)['party'] ?? $from->id;
        if (count($this->members($partyId)) >= (int) $this->config['max']) {
            throw new InventoryException('party.error_full');
        }
        // quien invita sin tener party arma uno y queda de líder
        $this->pdo->prepare('UPDATE characters SET party_id = ? WHERE id = ? AND party_id IS NULL')->execute([$partyId, $from->id]);
        $this->pdo->prepare('UPDATE characters SET party_invite_id = ? WHERE id = ? AND party_id IS NULL')->execute([$partyId, (int) $target['id']]);
        $this->notifications?->notify((int) $target['account_id'], 'notify.party_invited', ['name' => $from->name, 'target' => $target['name']], '/map');
    }

    /** @throws InventoryException */
    public function accept(Character $character): void
    {
        $partyId = $this->state($character->id)['invite'] ?? throw new InventoryException('party.error_no_invite');
        $size = count($this->members($partyId));
        if ($size < 1 || $size >= (int) $this->config['max']) {
            $this->decline($character);
            throw new InventoryException($size < 1 ? 'party.error_no_invite' : 'party.error_full');
        }
        $this->pdo->prepare('UPDATE characters SET party_id = ?, party_invite_id = NULL WHERE id = ? AND party_id IS NULL')
            ->execute([$partyId, $character->id]);
    }

    public function decline(Character $character): void
    {
        $this->pdo->prepare('UPDATE characters SET party_invite_id = NULL WHERE id = ?')->execute([$character->id]);
    }

    /** Sale del party; si era el líder (o quedaba uno solo), se disuelve. */
    public function leave(Character $character): void
    {
        $partyId = $this->state($character->id)['party'];
        if ($partyId === null) {
            return;
        }
        $this->pdo->prepare('UPDATE characters SET party_id = NULL WHERE id = ?')->execute([$character->id]);
        if ($partyId === $character->id || count($this->members($partyId)) < 2) {
            $this->pdo->prepare('UPDATE characters SET party_id = NULL WHERE party_id = ?')->execute([$partyId]);
            $this->pdo->prepare('UPDATE characters SET party_invite_id = NULL WHERE party_invite_id = ?')->execute([$partyId]);
        }
    }

    /**
     * Reparte entre los compañeros que están peleando en el mismo mapa.
     *
     * @return int cuántos compañeros recibieron su parte
     */
    public function share(int $characterId, string $map, int $experience, DateTimeImmutable $now): int
    {
        $partyId = $this->state($characterId)['party'];
        $part = intdiv($experience * (int) $this->config['share_percent'], 100);
        if ($partyId === null || $part < 1) {
            return 0;
        }
        $mates = array_filter($this->inMap($map, $now), static fn (array $p): bool => $p['party_id'] === $partyId && $p['id'] !== $characterId);
        $give = $this->pdo->prepare('UPDATE characters SET party_exp = party_exp + ? WHERE id = ?');
        foreach ($mates as $mate) {
            $give->execute([$part, $mate['id']]);
        }

        return count($mates);
    }

    /** Experiencia que le dejaron sus compañeros desde la última vez; al cobrarla queda en cero. */
    public function collect(int $characterId): int
    {
        $stmt = $this->pdo->prepare('SELECT party_exp FROM characters WHERE id = ?');
        $stmt->execute([$characterId]);
        $pending = (int) $stmt->fetchColumn();
        if ($pending < 1) {
            return 0;
        }
        // Resta lo leído en vez de poner cero: si justo llegó más, no se pierde.
        $this->pdo->prepare('UPDATE characters SET party_exp = party_exp - ? WHERE id = ? AND party_exp >= ?')->execute([$pending, $characterId, $pending]);

        return $pending;
    }
}
