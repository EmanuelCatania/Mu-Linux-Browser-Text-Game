<?php

declare(strict_types=1);

namespace Mu\Domain\Account;

use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Support\Clock;
use Mu\Support\Translator;

/** Registro, inicio de sesión y cambios de datos de la cuenta. */
final class AccountService
{
    /** Igual que el juego original: alfanumérico de 3 a 12 caracteres. */
    public const string USERNAME_PATTERN = '/^[A-Za-z0-9]{3,12}$/';
    public const int PASSWORD_MIN = 8;
    public const int PASSWORD_MAX = 128;

    /** Intentos fallidos tolerados dentro de la ventana antes de bloquear. */
    public const int MAX_FAILS_PER_USERNAME = 5;
    public const int MAX_FAILS_PER_IP = 20;
    public const int FAIL_WINDOW_SECONDS = 900;

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly LoginAttemptRepository $attempts,
        private readonly Clock $clock,
    ) {
    }

    /** @throws ValidationException */
    public function register(
        string $username,
        string $email,
        string $password,
        string $passwordAgain,
        string $ip,
        string $locale,
    ): Account {
        $username = trim($username);
        $email = trim($email);

        $errors = [];
        if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
            $errors[] = 'error.username_format';
        } elseif ($this->accounts->findByUsername($username) !== null) {
            $errors[] = 'error.username_taken';
        }

        if (!self::isValidEmail($email)) {
            $errors[] = 'error.email_format';
        } elseif ($this->accounts->emailExists($email)) {
            $errors[] = 'error.email_taken';
        }

        array_push($errors, ...self::passwordErrors($password, $passwordAgain));

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->accounts->insert(
            $username,
            $email,
            self::hash($password),
            Translator::isSupported($locale) ? $locale : Translator::DEFAULT_LOCALE,
            $ip,
            $this->clock->now(),
        );
    }

    /** @throws ValidationException con error.too_many_attempts o error.invalid_credentials */
    public function authenticate(string $username, string $password, string $ip): Account
    {
        $username = trim($username);
        $now = $this->clock->now();
        $since = $now->modify('-' . self::FAIL_WINDOW_SECONDS . ' seconds');
        $key = mb_strtolower(mb_substr($username, 0, 64));

        if (
            $this->attempts->countByUsernameSince($key, $since) >= self::MAX_FAILS_PER_USERNAME
            || $this->attempts->countByIpSince($ip, $since) >= self::MAX_FAILS_PER_IP
        ) {
            throw new ValidationException(['error.too_many_attempts']);
        }

        $account = $this->accounts->findByUsername($username);
        // Si la cuenta no existe se verifica igual contra un hash de relleno, para no delatarlo por el tiempo de respuesta.
        $valid = password_verify($password, $account->passwordHash ?? self::dummyHash());

        if ($account === null || !$valid) {
            $this->attempts->record($key, $ip, $now);
            throw new ValidationException(['error.invalid_credentials']);
        }

        if ($account->banned) {
            throw new ValidationException(['error.banned']);
        }
        $this->attempts->clearForUsername($key);
        if (password_needs_rehash($account->passwordHash, self::algorithm())) {
            $this->accounts->updatePasswordHash($account->id, self::hash($password));
        }
        $this->accounts->touchLastLogin($account->id, $now);

        return $account;
    }

    /** @throws ValidationException */
    public function changeEmail(Account $account, string $currentPassword, string $email): void
    {
        $email = trim($email);
        $errors = [];
        if (!password_verify($currentPassword, $account->passwordHash)) {
            $errors[] = 'error.current_password';
        }
        if (!self::isValidEmail($email)) {
            $errors[] = 'error.email_format';
        } elseif ($this->accounts->emailExists($email, $account->id)) {
            $errors[] = 'error.email_taken';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->accounts->updateEmail($account->id, $email);
    }

    /** @throws ValidationException */
    public function changePassword(Account $account, string $currentPassword, string $new, string $newAgain): void
    {
        $errors = [];
        if (!password_verify($currentPassword, $account->passwordHash)) {
            $errors[] = 'error.current_password';
        }
        array_push($errors, ...self::passwordErrors($new, $newAgain));
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->accounts->updatePasswordHash($account->id, self::hash($new));
    }

    public function setLocale(Account $account, string $locale): void
    {
        if (Translator::isSupported($locale)) {
            $this->accounts->updateLocale($account->id, $locale);
        }
    }

    /** @return list<string> */
    private static function passwordErrors(string $password, string $again): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::PASSWORD_MIN || $length > self::PASSWORD_MAX) {
            $errors[] = 'error.password_length';
        }
        if ($password !== $again) {
            $errors[] = 'error.password_mismatch';
        }

        return $errors;
    }

    private static function isValidEmail(string $email): bool
    {
        return strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private static function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private static function hash(string $password): string
    {
        return password_hash($password, self::algorithm());
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= self::hash(bin2hex(random_bytes(16)));
    }
}
