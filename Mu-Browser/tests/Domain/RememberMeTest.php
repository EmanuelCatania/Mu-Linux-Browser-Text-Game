<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\RememberTokenRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\RememberMe;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class RememberMeTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private RememberMe $rememberMe;
    private Account $account;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock();
        $accounts = new AccountRepository($this->pdo);
        $this->rememberMe = new RememberMe(
            new RememberTokenRepository($this->pdo),
            $accounts,
            $this->clock,
            'test-secret',
        );
        $this->account = $accounts->insert('Tester', 't@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now());
    }

    public function testConsumeReturnsTheAccountAndRotatesTheToken(): void
    {
        $cookie = $this->rememberMe->issue($this->account);

        $result = $this->rememberMe->consume($cookie);

        self::assertNotNull($result);
        [$account, $newCookie] = $result;
        self::assertSame($this->account->id, $account->id);
        self::assertNotSame($cookie, $newCookie);
        self::assertNull($this->rememberMe->consume($cookie), 'la cookie vieja no se puede reusar');
        self::assertNotNull($this->rememberMe->consume($newCookie));
    }

    public function testTheDatabaseDoesNotStoreTheValidator(): void
    {
        $cookie = $this->rememberMe->issue($this->account);
        [, $validator] = explode(':', $cookie);

        $stored = $this->pdo->query('SELECT token_hash FROM remember_tokens')->fetchColumn();

        self::assertNotSame($validator, $stored);
        self::assertStringNotContainsString($validator, (string) $stored);
    }

    public function testATamperedValidatorIsRejectedAndBurnsTheToken(): void
    {
        $cookie = $this->rememberMe->issue($this->account);
        [$selector] = explode(':', $cookie);

        self::assertNull($this->rememberMe->consume($selector . ':' . str_repeat('0', 64)));
        self::assertNull($this->rememberMe->consume($cookie));
    }

    public function testMalformedCookiesAreRejected(): void
    {
        self::assertNull($this->rememberMe->consume(''));
        self::assertNull($this->rememberMe->consume('abc'));
        self::assertNull($this->rememberMe->consume("' OR 1=1 --:x"));
    }

    public function testExpiredTokensAreRejected(): void
    {
        $cookie = $this->rememberMe->issue($this->account);
        $this->clock->advance('+' . (RememberMe::LIFETIME_SECONDS + 1) . ' seconds');

        self::assertNull($this->rememberMe->consume($cookie));
    }

    public function testForgetAllInvalidatesEveryDevice(): void
    {
        $phone = $this->rememberMe->issue($this->account);
        $laptop = $this->rememberMe->issue($this->account);

        $this->rememberMe->forgetAll($this->account);

        self::assertNull($this->rememberMe->consume($phone));
        self::assertNull($this->rememberMe->consume($laptop));
    }

    public function testForgetRemovesOnlyThatDevice(): void
    {
        $phone = $this->rememberMe->issue($this->account);
        $laptop = $this->rememberMe->issue($this->account);

        $this->rememberMe->forget($phone);

        self::assertNull($this->rememberMe->consume($phone));
        self::assertNotNull($this->rememberMe->consume($laptop));
    }
}
