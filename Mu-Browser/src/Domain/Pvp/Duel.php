<?php

declare(strict_types=1);

namespace Mu\Domain\Pvp;

use DateTimeImmutable;

/** Un desafío de PvP, en cualquiera de sus estados. */
final readonly class Duel
{
    /** Espera la respuesta del desafiado. */
    public const string PENDING = 'pending';
    /** El desafiado aceptó y puso su apuesta: espera que el retador la confirme. */
    public const string ANSWERED = 'answered';
    public const string FINISHED = 'finished';
    public const string DECLINED = 'declined';
    public const string CANCELLED = 'cancelled';
    public const string EXPIRED = 'expired';

    /** @param ?array<string, mixed> $result parte del combate y premios, sólo en duelos terminados */
    public function __construct(
        public int $id,
        public int $challengerId,
        public int $targetId,
        public int $challengerAccountId,
        public int $targetAccountId,
        public string $challengerName,
        public string $targetName,
        public int $zen,
        public string $status,
        public ?int $winnerId,
        public ?array $result,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING || $this->status === self::ANSWERED;
    }

    public function isChallenger(int $accountId): bool
    {
        return $this->challengerAccountId === $accountId;
    }

    /** ¿Esta cuenta ya comprometió su apuesta? El retador al desafiar; el desafiado, recién al aceptar. */
    public function stakeLocked(int $accountId): bool
    {
        return $this->isOpen() && ($this->isChallenger($accountId) || $this->status === self::ANSWERED);
    }
}
