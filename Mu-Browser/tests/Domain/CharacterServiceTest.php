<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\CharacterService;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

final class CharacterServiceTest extends TestCase
{
    private PDO $pdo;
    private AccountRepository $accounts;
    private CharacterService $service;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->accounts = new AccountRepository($this->pdo);
        $clock = new FixedClock();
        $this->service = new CharacterService(new CharacterRepository($this->pdo), $this->accounts, $clock);
        $this->accountId = $this->accounts->insert('Tester', 't@example.test', 'hash', 'es', '10.0.0.1', $clock->now())->id;
    }

    /** La cuenta tal como está ahora en la base (el DTO es inmutable). */
    private function account(?int $id = null): Account
    {
        return $this->accounts->findById($id ?? $this->accountId) ?? self::fail('cuenta inexistente');
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

    public function testNewAccountsStartWithZenAndNoCharacter(): void
    {
        self::assertSame(5000, $this->account()->zen);
        self::assertNull($this->service->active($this->account()));
        self::assertSame([], $this->service->listFor($this->account()));
    }

    public function testCreateUsesTheOriginalStartingStats(): void
    {
        $knight = $this->service->create($this->account(), 'Arthur', CharacterClass::DarkKnight->value);
        $wizard = $this->service->create($this->account(), 'Merlin', CharacterClass::DarkWizard->value);

        self::assertSame(
            [1, 28, 20, 25, 10, 110, 20, 0, []],
            [$knight->level, $knight->strength, $knight->agility, $knight->stamina, $knight->energy,
                $knight->hpCur, $knight->mpCur, $knight->points, $knight->skills],
        );
        self::assertSame([18, 18, 15, 30, 60, 60], [$wizard->strength, $wizard->agility, $wizard->stamina,
            $wizard->energy, $wizard->hpCur, $wizard->mpCur]);
        self::assertSame(['30'], $wizard->skills, 'el Dark Wizard nace con Energy Ball');
    }

    public function testFirstCharacterBecomesActiveAndLaterOnesDoNot(): void
    {
        $first = $this->service->create($this->account(), 'Arthur', 1);
        $this->service->create($this->account(), 'Merlin', 2);

        self::assertSame($first->id, $this->service->active($this->account())?->id);
    }

    public function testCreateValidatesNameClassAndLimit(): void
    {
        self::assertSame(
            ['error.character_name_format', 'error.character_class'],
            $this->errorsOf(fn () => $this->service->create($this->account(), 'a b', 99)),
        );
        self::assertSame(
            ['error.character_class'],
            $this->errorsOf(fn () => $this->service->create($this->account(), 'Gladi', CharacterClass::MagicGladiator->value)),
            'las clases que no son iniciales no se pueden crear',
        );

        $this->service->create($this->account(), 'Arthur', 1);
        self::assertSame(
            ['error.character_name_taken'],
            $this->errorsOf(fn () => $this->service->create($this->account(), 'ARTHUR', 1)),
        );

        $this->service->create($this->account(), 'Two', 2);
        $this->service->create($this->account(), 'Three', 3);
        self::assertSame(
            ['error.character_limit'],
            $this->errorsOf(fn () => $this->service->create($this->account(), 'Four', 4)),
        );
    }

    public function testSelectChangesTheActiveCharacter(): void
    {
        $this->service->create($this->account(), 'Arthur', 1);
        $second = $this->service->create($this->account(), 'Merlin', 2);

        $this->service->select($this->account(), $second->id);

        self::assertSame('Merlin', $this->service->active($this->account())?->name);
    }

    public function testCannotTouchAnotherAccountsCharacter(): void
    {
        $mine = $this->service->create($this->account(), 'Arthur', 1);
        $otherId = $this->accounts->insert('Other', 'o@example.test', 'hash', 'es', '10.0.0.2', new FixedClock()->now())->id;

        self::assertSame(
            ['error.character_not_found'],
            $this->errorsOf(fn () => $this->service->select($this->account($otherId), $mine->id)),
        );
        self::assertSame(
            ['error.character_not_found'],
            $this->errorsOf(fn () => $this->service->delete($this->account($otherId), $mine->id)),
        );
        self::assertCount(1, $this->service->listFor($this->account()));
    }

    public function testDeletingTheActiveCharacterActivatesTheOldestRemaining(): void
    {
        $first = $this->service->create($this->account(), 'Arthur', 1);
        $second = $this->service->create($this->account(), 'Merlin', 2);
        $this->service->create($this->account(), 'Robin', 3);

        $this->service->delete($this->account(), $first->id);
        self::assertSame($second->id, $this->service->active($this->account())?->id);
        self::assertCount(2, $this->service->listFor($this->account()));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM character_skills')->fetchColumn() - 1, 'quedan sólo las habilidades de Merlin');

        $this->service->delete($this->account(), $second->id);
        $this->service->delete($this->account(), $this->service->active($this->account())->id);
        self::assertNull($this->service->active($this->account()));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM character_skills')->fetchColumn());
    }

    public function testDeletingAnInactiveCharacterKeepsTheActiveOne(): void
    {
        $first = $this->service->create($this->account(), 'Arthur', 1);
        $second = $this->service->create($this->account(), 'Merlin', 2);

        $this->service->delete($this->account(), $second->id);

        self::assertSame($first->id, $this->service->active($this->account())?->id);
    }

    public function testAddStatPointSpendsOnePointOnTheActiveCharacter(): void
    {
        $character = $this->service->create($this->account(), 'Arthur', 1);
        $this->pdo->exec('UPDATE characters SET points = 2 WHERE id = ' . $character->id);

        $this->service->addStatPoint($this->account(), 'strength');
        $this->service->addStatPoint($this->account(), 'energy');

        $after = $this->service->active($this->account());
        self::assertSame([29, 11, 0], [$after?->strength, $after?->energy, $after?->points]);

        self::assertSame(
            ['error.no_points'],
            $this->errorsOf(fn () => $this->service->addStatPoint($this->account(), 'strength')),
        );
    }

    public function testAddStatPointRejectsUnknownStats(): void
    {
        $character = $this->service->create($this->account(), 'Arthur', 1);
        $this->pdo->exec('UPDATE characters SET points = 5 WHERE id = ' . $character->id);

        foreach (['level', 'points', 'experience', 'strength = 999, level', ''] as $stat) {
            self::assertSame(
                ['error.stat_invalid'],
                $this->errorsOf(fn () => $this->service->addStatPoint($this->account(), $stat)),
            );
        }
        self::assertSame(5, $this->service->active($this->account())?->points);
    }

    public function testAddStatPointNeedsAnActiveCharacter(): void
    {
        self::assertSame(
            ['error.character_not_found'],
            $this->errorsOf(fn () => $this->service->addStatPoint($this->account(), 'strength')),
        );
    }
}
