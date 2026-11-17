<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Admin\FirstRun;
use Mu\Domain\Admin\GameSettings;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Primer inicio: el sitio pide crear el administrador, con el código que el contenedor deja en el log. */
final class FirstRunTest extends TestCase
{
    public function testTheAdminIsCreatedOnceWithTheInstallationCode(): void
    {
        $pdo = TestDatabase::create();
        $clock = new FixedClock('2026-01-05 12:00:00');
        $accounts = new AccountRepository($pdo);
        $attempts = new LoginAttemptRepository($pdo);
        $firstRun = new FirstRun($pdo, $accounts, new AccountService($accounts, $attempts, $clock), $attempts, $clock);
        $create = fn (string $code, string $name = 'Jefe') => $firstRun->complete($code, $name, strtolower($name) . '@example.test', 'secreto123', 'secreto123', '10.0.0.1', 'es');
        $error = static function (callable $action): array {
            try {
                $action();
            } catch (ValidationException $e) {
                return $e->errors;
            }
            self::fail('Se esperaba un error');
        };

        self::assertTrue($firstRun->needed());
        $code = $firstRun->code() ?? self::fail('sin código');
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{10}$/', $code);
        self::assertSame($code, $firstRun->code(), 'el mismo código mientras no se use');
        self::assertSame([], new GameSettings($pdo)->overrides(), 'el código no es un ajuste del juego');

        self::assertSame(['setup.error_code'], $error(fn () => $create('AAAAAAAAAA')));
        self::assertSame(['error.username_taken'], $error(function () use ($accounts, $clock, $create, $code): void {
            $accounts->insert('Jefe', 'otro@example.test', 'hash', 'es', '10.0.0.1', $clock->now());
            $create($code);
        }), 'valida los datos como el registro');

        // se acepta en minúsculas y con el guion con que lo muestra el log
        $admin = $create(strtolower(substr($code, 0, 5) . '-' . substr($code, 5)), 'Admin');
        self::assertTrue($admin->isAdmin);
        self::assertFalse($firstRun->needed());
        self::assertNull($firstRun->code());
        self::assertSame(['setup.error_done'], $error(fn () => $create($code, 'Otro')));
    }

    public function testGuessingTheCodeIsLimited(): void
    {
        $pdo = TestDatabase::create();
        $clock = new FixedClock('2026-01-05 12:00:00');
        $accounts = new AccountRepository($pdo);
        $attempts = new LoginAttemptRepository($pdo);
        $firstRun = new FirstRun($pdo, $accounts, new AccountService($accounts, $attempts, $clock), $attempts, $clock);
        $code = $firstRun->code() ?? self::fail('sin código');
        $try = static function (string $c) use ($firstRun): string {
            try {
                $firstRun->complete($c, 'Admin', 'admin@example.test', 'secreto123', 'secreto123', '10.0.0.1', 'es');
            } catch (ValidationException $e) {
                return $e->errors[0];
            }

            return 'ok';
        };

        for ($i = 0; $i < 10; $i++) {
            self::assertSame('setup.error_code', $try('XXXXXXXXXX'));
        }
        self::assertSame('error.too_many_attempts', $try($code), 'ni con el código correcto, hasta que pase el tiempo');
        $clock->advance('+16 minutes');
        self::assertSame('ok', $try($code));
    }
}
