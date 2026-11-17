<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\GoldenRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\CharacterService;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\MapCatalog;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Nivel máximo y reset, evolución de clase con ítems de misión, y dorados de los mapas extra. */
final class ProgressTest extends TestCase
{
    private const int RESET_ZEN = 10_000_000;

    private string $root;
    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private Account $account;
    private Character $character;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->config = require $this->root . '/data/game.php';
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->account = $this->accounts->insert('uArthur', 'arthur@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now());
        $this->character = $this->characters->insert($this->account->id, 'Arthur', CharacterClass::DarkKnight, $this->clock->now());
        $this->accounts->setActiveCharacter($this->account->id, $this->character->id);
    }

    // --- nivel máximo y reset ---

    public function testAtTheMaximumLevelExperienceFillsUpButTheLevelDoesNotRise(): void
    {
        $stats = new StatsCalculator()->calculate($this->character());
        $battle = $this->battleAgainstSpider($stats);
        $battle->level = Battle::MAX_LEVEL;
        $next = Battle::experienceFor(Battle::MAX_LEVEL);
        $battle->experience = $next - 1;

        $this->engine()->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([], 'max'), $this->now());

        self::assertTrue($battle->enemies[0]['dead']);
        self::assertSame([Battle::MAX_LEVEL, 0, $next], [$battle->level, $battle->levelUps, $battle->experience]);
        self::assertNotContains('level_up', array_column($battle->log, 'type'));
    }

    public function testMonsterLifeCountsForExperienceOnlyWhenConfigured(): void
    {
        $stats = new StatsCalculator()->calculate($this->character());
        $earned = function (int $divisor) use ($stats): int {
            $config = ['exp_hp_divisor' => $divisor] + $this->config;
            $engine = new BattleEngine(new SkillCatalog($this->root . '/data/skills.php'), new DropGenerator($this->root . '/data/drop', $config['drop']), $config);
            $battle = $this->battleAgainstSpider($stats);
            $battle->enemies[0]['hp'] = 1000; // como si fuera un monstruo de mucha vida
            $engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([], 'max'), $this->now());
            self::assertTrue($battle->enemies[0]['dead']);

            return $battle->expEarned;
        };

        $original = $earned(0);
        // la tirada máxima da el doble de la base, que ahora suma vida/5 = 200
        self::assertSame($original + 400, $earned(5));
    }

    public function testResetNeedsTheMaximumLevelAndTheZenAndKeepsEverythingElse(): void
    {
        $service = new CharacterService($this->characters, $this->accounts, $this->clock, self::RESET_ZEN);
        $error = function () use ($service): string {
            try {
                $service->reset($this->account());
            } catch (ValidationException $e) {
                return $e->errors[0];
            }
            self::fail('Se esperaba un error');
        };

        self::assertSame('reset.error_level', $error());

        $this->pdo->exec('UPDATE characters SET level = 400, experience = 123456, strength = 900, points = 7, quest_id = 3, quest_score = 5, evo_step = 5, class = 21');
        $this->pdo->exec('INSERT INTO character_quests_done (character_id, quest_id) VALUES (' . $this->character->id . ', 1)');
        self::assertSame('shop.error_no_money', $error());
        self::assertSame(400, $this->character()->level, 'sin zen no pasa nada');

        $this->accounts->addZen($this->account->id, self::RESET_ZEN);
        $service->reset($this->account());

        $after = $this->character();
        self::assertSame([1, 0, 1], [$after->level, $after->experience, $after->resets]);
        self::assertSame([900, 7, CharacterClass::BladeKnight, 5], [$after->strength, $after->points, $after->class, $after->evoStep], 'conserva atributos, puntos y clase');
        self::assertNull($after->questId);
        self::assertSame([], $this->characters->questsDone($after->id), 'puede repetir las misiones');
        self::assertSame(5000, $this->account()->zen);
        self::assertSame('reset.error_level', $error(), 'no se hace dos veces seguidas');

        self::assertSame(1, $this->characters->ranking([], 'experience', 10, 0)['rows'][0]['resets']);
    }

    public function testRankingPutsMoreResetsFirst(): void
    {
        $other = $this->accounts->insert('uMerlin', 'merlin@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now());
        $this->characters->insert($other->id, 'Merlin', CharacterClass::DarkWizard, $this->clock->now());
        $this->pdo->exec("UPDATE characters SET experience = 999999, level = 90 WHERE name = 'Merlin'");
        $this->pdo->exec("UPDATE characters SET resets = 2 WHERE name = 'Arthur'");

        self::assertSame(['Arthur', 'Merlin'], array_column($this->characters->ranking([], 'experience', 10, 0)['rows'], 'name'));
    }

    // --- evolución ---

    public function testQuestItemOnlyDropsWhileHuntingAndFromMonstersInItsLevelRange(): void
    {
        $stats = new StatsCalculator()->calculate($this->character());
        $always = $this->engine(['drop_one_in' => 1]);
        $kill = function (array $drops) use ($stats, $always): Battle {
            $battle = $this->battleAgainstSpider($stats);
            $battle->evoDrops = $drops;
            $always->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([], 'max'), $this->now());
            self::assertTrue($battle->enemies[0]['dead']);

            return $battle;
        };
        $range = static fn (int $min, int $max): array => [['item' => EvolutionService::SCROLL, 'min' => $min, 'max' => $max, 'monster' => '']];

        self::assertSame([], $kill([])->evoFound, 'sin misión no cae nada');
        self::assertSame([], $kill($range(45, 60))->evoFound, 'la araña (nivel 2) es demasiado débil');
        self::assertSame([], $kill([['item' => 'Horn of Hell Maine', 'min' => 0, 'max' => 0, 'monster' => 'hell_maine']])->evoFound, 'el trofeo lo deja sólo su monstruo');

        $found = $kill($range(1, 5));
        self::assertSame([EvolutionService::SCROLL], $found->evoFound);
        self::assertSame([], $found->evoDrops, 'una vez que cayó ya no se busca');
        self::assertContains(['type' => 'quest_item', 'item' => EvolutionService::SCROLL], $found->log);
        self::assertSame([EvolutionService::SCROLL], $kill([['item' => EvolutionService::SCROLL, 'min' => 0, 'max' => 0, 'monster' => 'spider']])->evoFound);
    }

    public function testEvolutionFollowsTheQuestWithSebina(): void
    {
        $service = new EvolutionService($this->characters, $this->accounts, new BattleRepository($this->pdo), new Transaction($this->pdo), $this->config['evolution']);
        $talk = fn (): string => $service->talk($this->account(), $this->character());
        $error = function () use ($talk): string {
            try {
                $talk();
            } catch (InventoryException $e) {
                return $e->getMessage();
            }
            self::fail('Se esperaba un error');
        };

        self::assertSame('evo.error_level', $error());
        $this->pdo->exec('UPDATE characters SET level = 150');
        self::assertSame('shop.error_no_money', $error());

        $this->accounts->addZen($this->account->id, 3_000_000 - 5000);
        self::assertSame('evo.started', $talk());
        self::assertSame(2_000_000, $this->account()->zen);
        self::assertSame([['item' => EvolutionService::SCROLL, 'min' => 45, 'max' => 60, 'monster' => '']], EvolutionService::hunt($this->character(), $this->config['evolution'])['drops']);
        self::assertSame('evo.error_hunting', $error());

        // el pergamino cae en batalla: BattleService deja el paso siguiente
        $this->characters->setEvolution($this->character->id, EvolutionService::HAS_SCROLL);
        self::assertSame([], EvolutionService::hunt($this->character(), $this->config['evolution'])['drops']);
        self::assertSame('evo.scroll_delivered', $talk());
        self::assertSame(0, $this->account()->zen);
        self::assertSame(10, $this->character()->points);
        self::assertSame(['Broken Sword', 62, 76], array_values(array_slice(EvolutionService::hunt($this->character(), $this->config['evolution'])['drops'][0], 0, 3)));
        self::assertSame('evo.error_hunting', $error());

        $this->characters->setEvolution($this->character->id, EvolutionService::HAS_TREASURE);
        self::assertSame('evo.evolved', $talk());
        $evolved = $this->character();
        self::assertSame([CharacterClass::BladeKnight, 20, EvolutionService::DONE], [$evolved->class, $evolved->points, $evolved->evoStep]);
        self::assertSame(EvolutionService::DONE, $service->status($evolved)['step']);
        self::assertSame('evo.error_done', $error(), 'lo que sigue es con Marlon');
    }

    public function testEachStartingClassHasItsOwnTreasureAndEvolution(): void
    {
        $expected = [
            [CharacterClass::DarkKnight, CharacterClass::BladeKnight, 'Broken Sword'],
            [CharacterClass::DarkWizard, CharacterClass::SoulMaster, 'Soul Shard of Wizard'],
            [CharacterClass::FairyElf, CharacterClass::MuseElf, 'Tear of Elf'],
            [CharacterClass::Summoner, CharacterClass::BloodySummoner, 'Eye of the Abyssal'],
        ];
        foreach ($expected as [$class, $target, $treasure]) {
            $s = $class->startingStats();
            $c = new Character(1, 1, 'Test', $class, 150, $s['strength'], $s['agility'], $s['stamina'], $s['energy'], 0, 0, 0, $s['hp'], $s['mp']);
            self::assertSame([$target, $treasure], [EvolutionService::target($c), EvolutionService::treasure($c)]);
        }
        $evolved = new Character(1, 1, 'Test', CharacterClass::SoulMaster, 200, 18, 18, 15, 30, 0, 0, 0, 60, 60);
        self::assertNull(EvolutionService::target($evolved));
    }

    // --- dorados ---

    public function testEveryGoldenSpawnExistsInItsMapWithImageAndBox(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');
        $drops = new DropGenerator($this->root . '/data/drop', $this->config['drop']);
        $spawns = new GoldenRepository($this->pdo)->all();

        self::assertCount(15, $spawns);
        foreach ($spawns as $spawn) {
            $monster = $maps->get($spawn['map'])?->monster($spawn['monster']);
            self::assertNotNull($monster, "{$spawn['monster']} no está definido en {$spawn['map']}");
            self::assertFileExists($this->root . "/public/assets/img/monsters/{$spawn['monster']}.gif");
            $box = $drops->drop($spawn['monster'], $monster->level, new FakeRng(), $this->now());
            self::assertContains($box?->name, ['box_of_luck', 'box_of_kundun'], "{$spawn['monster']} tiene que dejar una caja");
        }
    }

    // --- ayudas ---

    /** @param array<string, int> $evolution cambios sobre la configuración de evolución */
    private function engine(array $evolution = []): BattleEngine
    {
        $config = $this->config;
        $config['evolution'] = $evolution + $config['evolution'];

        return new BattleEngine(
            new SkillCatalog($this->root . '/data/skills.php'),
            new DropGenerator($this->root . '/data/drop', $config['drop']),
            $config,
        );
    }

    private function battleAgainstSpider(\Mu\Domain\Stats\CharacterStats $stats): Battle
    {
        $battle = new Battle();
        $battle->map = 'lorencia';
        $battle->spot = 'spot1';
        $battle->hp = $stats->hpMax;
        $battle->mp = $stats->mpMax;
        $map = new MapCatalog($this->root . '/data/maps')->get('lorencia') ?? self::fail('sin mapa');
        $this->engine()->startRound($battle, $map, $map->spots['spot1'], null, new FakeRng([1], 'min'));

        return $battle;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));
    }

    private function account(): Account
    {
        return $this->accounts->findById($this->account->id) ?? self::fail('cuenta inexistente');
    }

    private function character(): Character
    {
        return $this->characters->findById($this->character->id) ?? self::fail('personaje inexistente');
    }
}
