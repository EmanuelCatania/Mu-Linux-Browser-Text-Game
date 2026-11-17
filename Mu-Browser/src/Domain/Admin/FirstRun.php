<?php

declare(strict_types=1);

namespace Mu\Domain\Admin;

use DateTimeImmutable;
use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\ValidationException;
use Mu\Support\Clock;
use PDO;

/**
 * Primer inicio: mientras no haya ninguna cuenta administradora, el sitio pide crearla.
 *
 * Para que no la reclame cualquiera que llegue primero, hace falta un código de instalación que el contenedor
 * escribe en su log al arrancar (docker compose logs mu-web). El código deja de valer al crear el administrador.
 */
final class FirstRun
{
    private const string SETTING = 'setup.code';
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O ni 1/I
    private const int CODE_LENGTH = 10;
    private const int MAX_FAILS = 10;
    private const int FAIL_WINDOW_SECONDS = 900;
    private const string ATTEMPT_KEY = '#setup';

    public function __construct(
        private readonly PDO $pdo,
        private readonly AccountRepository $accounts,
        private readonly AccountService $service,
        private readonly LoginAttemptRepository $attempts,
        private readonly Clock $clock,
    ) {
    }

    public function needed(): bool
    {
        return $this->pdo->query('SELECT 1 FROM accounts WHERE is_admin = 1 LIMIT 1')->fetchColumn() === false;
    }

    /** Código vigente, creándolo si hace falta. null si ya hay administrador. */
    public function code(): ?string
    {
        if (!$this->needed()) {
            $this->pdo->prepare('DELETE FROM settings WHERE name = ?')->execute([self::SETTING]);

            return null;
        }
        $stmt = $this->pdo->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([self::SETTING]);
        $stored = $stmt->fetchColumn();
        if (is_string($stored) && is_string($code = json_decode($stored, true))) {
            return $code;
        }

        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $this->pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)')
            ->execute([self::SETTING, json_encode($code), $this->clock->now()->format(Clock::SQL_FORMAT)]);

        return $code;
    }

    /**
     * Crea la cuenta administradora.
     *
     * @throws ValidationException
     */
    public function complete(string $code, string $username, string $email, string $password, string $passwordAgain, string $ip, string $locale): Account
    {
        if (!$this->needed()) {
            throw new ValidationException(['setup.error_done']);
        }
        $now = $this->clock->now();
        if ($this->attempts->countByUsernameSince(self::ATTEMPT_KEY, $this->since($now)) >= self::MAX_FAILS) {
            throw new ValidationException(['error.too_many_attempts']);
        }
        $expected = $this->code() ?? '';
        $given = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');
        if ($expected === '' || !hash_equals($expected, $given)) {
            $this->attempts->record(self::ATTEMPT_KEY, $ip, $now);
            throw new ValidationException(['setup.error_code']);
        }

        $account = $this->service->register($username, $email, $password, $passwordAgain, $ip, $locale);
        $this->accounts->setAdmin($account->id, true);
        $this->attempts->clearForUsername(self::ATTEMPT_KEY);
        $this->code(); // ya hay administrador: borra el código

        return $this->accounts->findById($account->id) ?? $account;
    }

    private function since(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify('-' . self::FAIL_WINDOW_SECONDS . ' seconds');
    }
}
