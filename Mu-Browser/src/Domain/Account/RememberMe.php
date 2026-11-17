<?php

declare(strict_types=1);

namespace Mu\Domain\Account;

use Mu\Data\AccountRepository;
use Mu\Data\RememberTokenRepository;
use Mu\Support\Clock;

/**
 * "Recordarme": la cookie lleva selector:validador aleatorios y la base sólo guarda un HMAC del validador.
 * Cada uso reemplaza el token por uno nuevo, así una cookie copiada deja de servir apenas se usa la original.
 */
final class RememberMe
{
    public const string COOKIE = 'mu_remember';
    public const int LIFETIME_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private readonly RememberTokenRepository $tokens,
        private readonly AccountRepository $accounts,
        private readonly Clock $clock,
        private readonly string $secret,
    ) {
    }

    /** Crea un token para la cuenta y devuelve el valor a guardar en la cookie. */
    public function issue(Account $account): string
    {
        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $now = $this->clock->now();

        $this->tokens->insert(
            $selector,
            $this->sign($validator),
            $account->id,
            $now->modify('+' . self::LIFETIME_SECONDS . ' seconds'),
            $now,
        );

        return $selector . ':' . $validator;
    }

    /**
     * Valida la cookie y la rota.
     *
     * @return array{Account, string}|null cuenta y nuevo valor de cookie, o null si no es válida
     */
    public function consume(string $cookie): ?array
    {
        [$selector, $validator] = self::split($cookie);
        if ($selector === null) {
            return null;
        }

        $row = $this->tokens->find($selector);
        if ($row === null) {
            return null;
        }

        $this->tokens->delete($selector);

        if (
            !hash_equals($row['token_hash'], $this->sign($validator))
            || $row['expires_at'] <= $this->clock->now()->format(Clock::SQL_FORMAT)
        ) {
            return null;
        }

        $account = $this->accounts->findById((int) $row['account_id']);

        return $account === null ? null : [$account, $this->issue($account)];
    }

    public function forget(string $cookie): void
    {
        [$selector] = self::split($cookie);
        if ($selector !== null) {
            $this->tokens->delete($selector);
        }
    }

    public function forgetAll(Account $account): void
    {
        $this->tokens->deleteForAccount($account->id);
    }

    private function sign(string $validator): string
    {
        return hash_hmac('sha256', $validator, $this->secret);
    }

    /** @return array{?string, string} */
    private static function split(string $cookie): array
    {
        if (preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $cookie, $m) !== 1) {
            return [null, ''];
        }

        return [$m[1], $m[2]];
    }
}
