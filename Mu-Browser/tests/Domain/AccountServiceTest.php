<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\ValidationException;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

final class AccountServiceTest extends TestCase
{
    private const string PASSWORD = 'correct horse';

    private AccountRepository $repo;
    private FixedClock $clock;
    private AccountService $service;

    protected function setUp(): void
    {
        $pdo = TestDatabase::create();
        $this->repo = new AccountRepository($pdo);
        $this->clock = new FixedClock();
        $this->service = new AccountService($this->repo, new LoginAttemptRepository($pdo), $this->clock);
    }

    private function register(string $username = 'Tester', string $email = 'tester@example.test'): Account
    {
        return $this->service->register($username, $email, self::PASSWORD, self::PASSWORD, '10.0.0.1', 'es');
    }

    /** @return list<string> */
    private function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors;
        }

        self::fail('Se esperaba una ValidationException');
    }

    public function testRegisterStoresAHashedPassword(): void
    {
        $account = $this->register();

        self::assertSame('Tester', $account->username);
        self::assertNotSame(self::PASSWORD, $account->passwordHash);
        self::assertTrue(password_verify(self::PASSWORD, $account->passwordHash));
        self::assertNotNull($this->repo->findById($account->id));
    }

    public function testRegisterReportsEveryInvalidField(): void
    {
        $errors = $this->errorsOf(
            fn () => $this->service->register('a!', 'not-an-email', 'short', 'other', '10.0.0.1', 'es'),
        );

        self::assertSame(
            ['error.username_format', 'error.email_format', 'error.password_length', 'error.password_mismatch'],
            $errors,
        );
    }

    public function testRegisterRejectsDuplicatesIgnoringCase(): void
    {
        $this->register();

        self::assertSame(
            ['error.username_taken', 'error.email_taken'],
            $this->errorsOf(fn () => $this->register('TESTER', 'Tester@Example.test')),
        );
    }

    public function testRegisterFallsBackToSpanishForUnknownLocale(): void
    {
        $account = $this->service->register('Tester', 't@example.test', self::PASSWORD, self::PASSWORD, '10.0.0.1', 'xx');

        self::assertSame('es', $account->locale);
    }

    public function testAuthenticateAcceptsTheRightPasswordInAnyUsernameCase(): void
    {
        $registered = $this->register();

        self::assertSame($registered->id, $this->service->authenticate('tester', self::PASSWORD, '10.0.0.1')->id);
    }

    public function testAuthenticateGivesTheSameErrorForWrongPasswordAndUnknownUser(): void
    {
        $this->register();

        $wrongPassword = $this->errorsOf(fn () => $this->service->authenticate('Tester', 'nope', '10.0.0.1'));
        $unknownUser = $this->errorsOf(fn () => $this->service->authenticate('Nobody', 'nope', '10.0.0.1'));

        self::assertSame(['error.invalid_credentials'], $wrongPassword);
        self::assertSame($wrongPassword, $unknownUser);
    }

    public function testAuthenticateLocksAfterTooManyFailuresAndUnlocksLater(): void
    {
        $this->register();

        for ($i = 0; $i < AccountService::MAX_FAILS_PER_USERNAME; $i++) {
            $this->errorsOf(fn () => $this->service->authenticate('Tester', 'nope', '10.0.0.1'));
        }

        // Bloqueado aunque ahora la contraseña sea la correcta, y desde otra IP también.
        self::assertSame(
            ['error.too_many_attempts'],
            $this->errorsOf(fn () => $this->service->authenticate('Tester', self::PASSWORD, '10.0.0.2')),
        );

        $this->clock->advance('+' . (AccountService::FAIL_WINDOW_SECONDS + 1) . ' seconds');

        self::assertSame('Tester', $this->service->authenticate('Tester', self::PASSWORD, '10.0.0.2')->username);
    }

    public function testASuccessfulLoginClearsPreviousFailures(): void
    {
        $this->register();

        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < AccountService::MAX_FAILS_PER_USERNAME - 1; $i++) {
                $this->errorsOf(fn () => $this->service->authenticate('Tester', 'nope', '10.0.0.1'));
            }
            $this->service->authenticate('Tester', self::PASSWORD, '10.0.0.1');
        }

        $this->addToAssertionCount(1);
    }

    public function testAuthenticateLocksAnIpThatTriesManyUsernames(): void
    {
        for ($i = 0; $i < AccountService::MAX_FAILS_PER_IP; $i++) {
            $this->errorsOf(fn () => $this->service->authenticate('user' . $i, 'nope', '10.0.0.9'));
        }

        self::assertSame(
            ['error.too_many_attempts'],
            $this->errorsOf(fn () => $this->service->authenticate('another', 'nope', '10.0.0.9')),
        );
    }

    public function testChangePasswordRequiresTheCurrentOne(): void
    {
        $account = $this->register();

        self::assertSame(
            ['error.current_password'],
            $this->errorsOf(fn () => $this->service->changePassword($account, 'wrong', 'new password', 'new password')),
        );

        $this->service->changePassword($account, self::PASSWORD, 'new password', 'new password');

        self::assertSame($account->id, $this->service->authenticate('Tester', 'new password', '10.0.0.1')->id);
        self::assertSame(
            ['error.invalid_credentials'],
            $this->errorsOf(fn () => $this->service->authenticate('Tester', self::PASSWORD, '10.0.0.1')),
        );
    }

    public function testChangeEmailValidatesAndAllowsKeepingYourOwn(): void
    {
        $account = $this->register();
        $this->register('Other', 'other@example.test');

        self::assertSame(
            ['error.email_taken'],
            $this->errorsOf(fn () => $this->service->changeEmail($account, self::PASSWORD, 'OTHER@example.test')),
        );

        $this->service->changeEmail($account, self::PASSWORD, 'tester@example.test');
        $this->service->changeEmail($account, self::PASSWORD, 'new@example.test');

        self::assertSame('new@example.test', $this->repo->findById($account->id)?->email);
    }

    public function testSetLocaleIgnoresUnsupportedValues(): void
    {
        $account = $this->register();

        $this->service->setLocale($account, 'pt');
        self::assertSame('pt', $this->repo->findById($account->id)?->locale);

        $this->service->setLocale($account, 'xx');
        self::assertSame('pt', $this->repo->findById($account->id)?->locale);
    }
}
