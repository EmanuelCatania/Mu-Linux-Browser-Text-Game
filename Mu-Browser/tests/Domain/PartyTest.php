<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\PresenceRepository;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Party\PartyService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Party: invitaciones, límite de miembros y experiencia compartida entre los que pelean en el mismo mapa. */
final class PartyTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private CharacterRepository $characters;
    private PresenceRepository $presence;
    private PartyService $party;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->presence = new PresenceRepository($this->pdo);
        $this->party = new PartyService($this->pdo, ['share_percent' => 30, 'max' => 3]);
        foreach (['Arthur', 'Merlin', 'Robin', 'Tristan'] as $name) {
            $account = $accounts->insert('u' . $name, strtolower($name) . '@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now());
            $this->characters->insert($account->id, $name, CharacterClass::DarkKnight, $this->clock->now());
        }
    }

    public function testInviteAcceptLeaveAndLimit(): void
    {
        $this->party->invite($this->who('Arthur'), 'merlin');
        self::assertSame($this->who('Arthur')->id, $this->party->state($this->who('Arthur')->id)['party'], 'el que invita queda de líder');
        self::assertSame('Arthur', $this->party->state($this->who('Merlin')->id)['inviter']);

        $this->party->accept($this->who('Merlin'));
        self::assertSame(['Arthur', 'Merlin'], array_column($this->party->members($this->who('Arthur')->id), 'name'));
        self::assertSame('party.error_has_party', $this->errorOf(fn () => $this->party->invite($this->who('Robin'), 'Merlin')));
        self::assertSame('party.error_target', $this->errorOf(fn () => $this->party->invite($this->who('Arthur'), 'Arthur')));
        self::assertSame('party.error_no_invite', $this->errorOf(fn () => $this->party->accept($this->who('Tristan'))));

        // máximo 3: con Robin se llena y Tristan ya no entra
        $this->party->invite($this->who('Merlin'), 'Robin');
        $this->party->invite($this->who('Arthur'), 'Tristan');
        $this->party->accept($this->who('Robin'));
        self::assertSame('party.error_full', $this->errorOf(fn () => $this->party->accept($this->who('Tristan'))));

        $this->party->leave($this->who('Robin'));
        self::assertCount(2, $this->party->members($this->who('Arthur')->id));
        $this->party->leave($this->who('Arthur'));
        self::assertNull($this->party->state($this->who('Merlin')->id)['party'], 'sin líder el party se disuelve');
    }

    public function testExperienceIsSharedOnlyWithMatesFightingOnTheSameMap(): void
    {
        foreach (['Merlin', 'Robin'] as $name) {
            $this->party->invite($this->who('Arthur'), $name);
            $this->party->accept($this->who($name));
        }
        $this->presence->touch($this->who('Arthur')->id, 'lorencia:spot1', 10, $this->clock->now());
        $this->presence->touch($this->who('Merlin')->id, 'lorencia:spot4', 10, $this->clock->now());
        $this->presence->touch($this->who('Robin')->id, 'noria:spot11', 10, $this->clock->now());
        $this->presence->touch($this->who('Tristan')->id, 'lorencia:spot1', 10, $this->clock->now());

        self::assertSame(['Arthur', 'Merlin', 'Tristan'], array_column($this->party->inMap('lorencia', $this->clock->now()), 'name'));
        self::assertSame(1, $this->party->share($this->who('Arthur')->id, 'lorencia', 1000, $this->clock->now()));

        self::assertSame(300, $this->party->collect($this->who('Merlin')->id), 'otro spot del mismo mapa: recibe');
        self::assertSame(0, $this->party->collect($this->who('Merlin')->id), 'se cobra una sola vez');
        self::assertSame(0, $this->party->collect($this->who('Robin')->id), 'está en otro mapa');
        self::assertSame(0, $this->party->collect($this->who('Tristan')->id), 'no es del party');
        self::assertSame(0, $this->party->collect($this->who('Arthur')->id), 'el que gana no se paga a sí mismo');

        // quien dejó de jugar hace rato ya no cuenta como presente
        $this->clock->advance('+10 minutes');
        $this->presence->touch($this->who('Arthur')->id, 'lorencia:spot1', 10, $this->clock->now());
        self::assertSame(0, $this->party->share($this->who('Arthur')->id, 'lorencia', 1000, $this->clock->now()));
    }

    public function testReceivedExperienceLevelsUpLikeAKill(): void
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/data/game.php';
        $engine = new BattleEngine(new SkillCatalog($root . '/data/skills.php'), new DropGenerator($root . '/data/drop', $config['drop']), $config);
        $battle = new Battle();
        $battle->level = 1;

        self::assertFalse($engine->grantExperience($battle, Battle::experienceFor(1) - 1));
        self::assertTrue($engine->grantExperience($battle, 5));
        self::assertSame([2, 1, Battle::experienceFor(1)], [$battle->level, $battle->levelUps, $battle->experience]);
    }

    private function who(string $name): Character
    {
        return $this->characters->findByName($name) ?? self::fail("sin $name");
    }

    private function errorOf(callable $action): string
    {
        try {
            $action();
        } catch (InventoryException $e) {
            return $e->getMessage();
        }
        self::fail('Se esperaba un error');
    }
}
