<?php

declare(strict_types=1);

namespace Mu\Domain\Chat;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\InventoryException;
use Mu\Support\Clock;
use PDO;

/**
 * Chat (port de function.chat.php). Hay un canal global y uno por spot: quien está peleando escribe
 * en el canal de su spot y lee ése más el global. Los mensajes duran una hora.
 */
final class ChatService
{
    public const string GLOBAL = 'global';
    public const int MAX_LENGTH = 120;
    public const int VISIBLE = 12;
    private const int LIFETIME_SECONDS = 3600;
    /** Segundos mínimos entre dos mensajes del mismo personaje. */
    private const int COOLDOWN_SECONDS = 2;

    public function __construct(private readonly PDO $pdo, private readonly Clock $clock)
    {
    }

    /**
     * @param string $dest canal: ChatService::GLOBAL o la ubicación del spot (mapa:spot)
     *
     * @throws InventoryException
     */
    public function post(Character $character, string $dest, string $text): void
    {
        // Sólo texto plano de una línea; el escape al mostrar lo hace quien lo renderiza.
        $text = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($text)) ?? '');
        if ($text === '') {
            throw new InventoryException('chat.error_empty');
        }
        $text = mb_substr($text, 0, self::MAX_LENGTH);
        $now = $this->clock->now();

        $last = $this->pdo->prepare('SELECT MAX(created_at) FROM chat_messages WHERE character_id = ?');
        $last->execute([$character->id]);
        $lastAt = $last->fetchColumn();
        if (is_string($lastAt) && $lastAt > $now->modify('-' . self::COOLDOWN_SECONDS . ' seconds')->format(Clock::SQL_FORMAT)) {
            throw new InventoryException('chat.error_too_fast');
        }

        $this->pdo->prepare('INSERT INTO chat_messages (character_id, poster, dest, body, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$character->id, $character->name, $dest, $text, $now->format(Clock::SQL_FORMAT)]);
    }

    /**
     * Últimos mensajes del canal global y, si se indica, de un spot.
     *
     * @return list<array{time: string, poster: string, class: string, text: string, global: bool}> del más viejo al más nuevo
     */
    public function recent(?string $spot = null): array
    {
        $now = $this->clock->now();
        $this->pdo->prepare('DELETE FROM chat_messages WHERE created_at < ?')
            ->execute([$now->modify('-' . self::LIFETIME_SECONDS . ' seconds')->format(Clock::SQL_FORMAT)]);

        $stmt = $this->pdo->prepare(
            'SELECT m.poster, m.dest, m.body, m.created_at, c.class
               FROM chat_messages m LEFT JOIN characters c ON c.id = m.character_id
              WHERE m.dest = ? OR m.dest = ? ORDER BY m.id DESC LIMIT ' . self::VISIBLE
        );
        $stmt->execute([self::GLOBAL, $spot ?? self::GLOBAL]);

        return array_reverse(array_map(static fn (array $r): array => [
            // en UTC con zona explícita: el navegador la muestra en la hora local de cada uno
            'time' => new DateTimeImmutable($r['created_at'], new DateTimeZone('UTC'))->format(DATE_ATOM),
            'poster' => $r['poster'],
            // la clase actual del personaje; vacío si lo borraron después de escribir
            'class' => $r['class'] === null ? '' : (CharacterClass::tryFrom((int) $r['class'])?->label() ?? ''),
            'text' => $r['body'],
            'global' => $r['dest'] === self::GLOBAL,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }
}
