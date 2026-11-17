<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Character\MasterTree;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\Item;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Tercera clase, nivel master y árbol de habilidades. */
final class MasterTest extends TestCase
{
    private string $root;
    private PDO $pdo;
    private AccountRepository $accounts;
    private CharacterRepository $characters;
    private Account $account;
    private int $id;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
        $this->config = require $this->root . '/data/game.php';
        $this->pdo = TestDatabase::create();
        $clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->characters = new CharacterRepository($this->pdo);
        $this->account = $this->accounts->insert('uArthur', 'arthur@example.test', 'hash', 'es', '10.0.0.1', $clock->now());
        $this->id = $this->characters->insert($this->account->id, 'Arthur', CharacterClass::DarkKnight, $clock->now())->id;
    }

    public function testClassLinesAndItemRights(): void
    {
        self::assertSame([CharacterClass::BladeMaster, CharacterClass::BladeKnight, CharacterClass::DarkKnight], CharacterClass::BladeMaster->lineage());
        self::assertSame(CharacterClass::DarkLord, CharacterClass::LordEmperor->base());
        self::assertSame([3, 2, 1, 1], array_map(static fn (CharacterClass $c): int => $c->tier(), [CharacterClass::DuelMaster, CharacterClass::MuseElf, CharacterClass::MagicGladiator, CharacterClass::DarkKnight]));
        self::assertSame([7, 5], [CharacterClass::LordEmperor->pointsPerLevel(), CharacterClass::GrandMaster->pointsPerLevel()]);
        self::assertContains('command', CharacterClass::LordEmperor->stats());
        self::assertSame([21, 5], [CharacterClass::BladeMaster->image(), CharacterClass::DuelMaster->image()]);

        $dragon = Item::parse('8.53.2.0030.00.0.4215.0000.21.0.0.00.00.0.220.220.000000.dragon_wings');
        $storm = Item::parse('8.53.3.0060.00.0.4400.0000.31.0.0.00.00.0.240.240.000000.wing_of_storm');
        self::assertTrue($dragon->canBeUsedBy(CharacterClass::BladeMaster), 'lo de la segunda clase le sigue sirviendo a la tercera');
        self::assertFalse($dragon->canBeUsedBy(CharacterClass::DarkKnight));
        self::assertTrue($storm->canBeUsedBy(CharacterClass::BladeMaster));
        self::assertFalse($storm->canBeUsedBy(CharacterClass::BladeKnight), 'las alas de 3.er nivel piden la tercera clase');
        self::assertFalse($storm->canBeUsedBy(CharacterClass::GrandMaster));
    }

    public function testMarlonGivesExtraPointsAndTheDarkKnightCombo(): void
    {
        $service = $this->evolution();
        $talk = fn (): string => $service->talkMarlon($this->account(), $this->character());

        self::assertSame('evo.error_need_second', $this->errorOf($talk), 'antes, Sebina');
        $this->pdo->exec('UPDATE characters SET class = 21, evo_step = 5, level = 219');
        self::assertSame('evo.error_level', $this->errorOf($talk));
        $this->pdo->exec('UPDATE characters SET level = 230, points = 0');
        $this->accounts->addZen($this->account->id, 5_000_000);

        self::assertSame('evo.marlon_started', $talk());
        self::assertSame([['item' => EvolutionService::RING, 'min' => 72, 'max' => 108, 'monster' => '']], $this->hunt()['drops']);
        self::assertSame('evo.error_hunting', $this->errorOf($talk));
        $this->characters->setEvolution($this->id, EvolutionService::HAS_RING);
        self::assertFalse(EvolutionService::hasPlusStat($this->character()));

        self::assertSame('evo.ring_delivered_dk', $talk());
        self::assertSame(10, $this->character()->points, 'un punto por cada nivel que ya pasó del 220');
        self::assertTrue(EvolutionService::hasPlusStat($this->character()));
        self::assertSame(EvolutionService::STONE, $this->hunt()['drops'][0]['item'], 'el Dark Knight sigue con la Dark Stone');
        self::assertSame(0, $this->stats()->comboDamage);

        $this->characters->setEvolution($this->id, EvolutionService::HAS_STONE);
        self::assertSame('evo.combo_learned', $talk());
        $knight = $this->character();
        self::assertSame(intdiv($knight->strength + $knight->agility + $knight->energy, 2), $this->stats()->comboDamage);
        self::assertSame('evo.error_done', $this->errorOf($talk));

        // la Muse Elf termina con el anillo y aprende Infinity Arrow
        $this->pdo->exec('UPDATE characters SET class = 23, evo_step = 7');
        self::assertNotContains('F', $this->stats()->skills);
        self::assertSame('evo.ring_delivered_elf', $talk());
        self::assertSame(EvolutionService::MARLON_DONE, $this->character()->evoStep);
        self::assertContains('F', $this->stats()->skills);

        // Magic Gladiator y Dark Lord no pasan por Marlon
        $this->pdo->exec('UPDATE characters SET class = 5, evo_step = 0');
        self::assertSame('evo.error_done', $this->errorOf($talk));
    }

    public function testThirdClassQuestWithApostleDevin(): void
    {
        $service = $this->evolution();
        $talk = fn (): string => $service->talkThird($this->account(), $this->character());

        self::assertSame('evo.error_need_second', $this->errorOf($talk), 'un Dark Knight primero pasa por Sebina');
        $this->pdo->exec('UPDATE characters SET class = 21, evo_step = 5, level = 400');
        self::assertSame('evo.error_need_marlon', $this->errorOf($talk));
        try {
            $service->talk($this->account(), $this->character());
            self::fail('Sebina ya no tiene nada para un Blade Knight');
        } catch (InventoryException $e) {
            self::assertSame('evo.error_done', $e->getMessage());
        }

        $this->pdo->exec('UPDATE characters SET evo_step = 10, level = 379');
        self::assertSame('evo.error_level', $this->errorOf($talk), 'empieza en el 380');
        $this->pdo->exec('UPDATE characters SET level = 390, points = 0');
        self::assertSame('shop.error_no_money', $this->errorOf($talk));
        $this->accounts->addZen($this->account->id, 22_000_000);

        // 1. los tres trofeos, cada uno de su monstruo
        self::assertSame('evo.devin_started', $talk());
        self::assertSame(['death_beam_knight', 'hell_maine', 'phoenix_of_darkness'], array_column($this->hunt()['drops'], 'monster'));
        $this->record(['Flame of Death Beam Knight'], []);
        self::assertSame(['hell_maine', 'phoenix_of_darkness'], array_column($this->hunt()['drops'], 'monster'));
        self::assertSame('evo.error_hunting', $this->errorOf($talk));
        $this->record(['Horn of Hell Maine', 'Feather of Dark Phoenix'], []);
        self::assertSame('evo.error_level', $this->errorOf($talk), 'la segunda parte pide el nivel 400');
        $this->pdo->exec('UPDATE characters SET level = 400');
        self::assertSame('evo.trophies_delivered', $talk());
        self::assertSame(20, $this->character()->points);

        // 2. veinte de cada uno, sólo en Barracks
        self::assertSame(['drops' => [], 'map' => 'barracks', 'kills' => EvolutionService::BARRACKS_KILLS], $this->hunt());
        $this->record([], ['balram' => 20, 'death_spirit' => 20, 'soram' => 19]);
        self::assertSame(['soram' => 1], $this->hunt()['kills']);
        self::assertSame('evo.error_hunting', $this->errorOf($talk));
        $this->record([], ['soram' => 1]);
        self::assertSame('evo.barracks_done', $talk());

        // 3. el Dark Elf
        self::assertSame(['dark_elf' => 1], $this->hunt()['kills']);
        $this->record([], ['dark_elf' => 1]);
        self::assertSame('evo.third_evolved', $talk());

        $master = $this->character();
        self::assertSame([CharacterClass::BladeMaster, EvolutionService::MASTER, 70], [$master->class, $master->evoStep, $master->points]);
        self::assertSame('master', $service->status($master)['devin']['phase']);
        self::assertSame(5000, $this->account()->zen, 'cobró 5 + 7 + 10 millones: le queda el zen inicial');
        self::assertSame('evo.error_done', $this->errorOf($talk));

        // Magic Gladiator y Dark Lord van directo a Devin
        $this->pdo->exec('UPDATE characters SET class = 6, evo_step = 0, evo_progress = \'{}\'');
        $this->accounts->addZen($this->account->id, 5_000_000);
        self::assertSame('evo.devin_started', $talk());
        self::assertSame('Lord Emperor', $service->status($this->character())['devin']['class']);
    }

    public function testBarracksKillsOnlyCountOnTheirMapAndTheComboNeedsThreeDifferentSkills(): void
    {
        $config = $this->config;
        $engine = new BattleEngine(new SkillCatalog($this->root . '/data/skills.php'), new DropGenerator($this->root . '/data/drop', $config['drop']), $config);
        $maps = new \Mu\Domain\World\MapCatalog($this->root . '/data/maps');
        $now = new \DateTimeImmutable('2026-01-05 12:00:00');
        $fight = function (string $mapId, array $skills = [], int $combo = 0) use ($engine, $maps, $now): Battle {
            $map = $maps->get($mapId) ?? self::fail('sin mapa');
            $battle = new Battle();
            $battle->map = $mapId;
            $battle->evoMap = 'barracks';
            $battle->evoKills = ['balram' => 20];
            $engine->startRound($battle, $map, array_values($map->spots)[0], null, new FakeRng([1], 'min'));
            $battle->enemies[0]['name'] = 'balram';
            $battle->enemies[0]['hpCur'] = 1;
            $knight = new Character(1, 1, 'K', CharacterClass::BladeKnight, 300, 900, 300, 300, 100, 0, 0, 0, 2000, 2000, $skills);
            $stats = new \Mu\Domain\Stats\CharacterStats(...array_merge(get_object_vars(new StatsCalculator()->calculate($knight)), ['comboDamage' => $combo]));
            $battle->hp = $stats->hpMax;
            $battle->mp = $stats->mpMax;
            $engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([], 'max'), $now);

            return $battle;
        };

        self::assertSame(['balram' => 1], $fight('barracks')->evoKilled);
        self::assertSame(['balram' => 19], $fight('barracks')->evoKills);
        self::assertSame([], $fight('crywolf')->evoKilled, 'el Balram de Crywolf no cuenta');

        // Combo: tres habilidades distintas seguidas que pegan
        $map = $maps->get('lorencia') ?? self::fail('sin mapa');
        $knight = new Character(1, 1, 'K', CharacterClass::BladeKnight, 300, 900, 300, 300, 100, 0, 0, 0, 2000, 2000, ['21', '22', '24']);
        $stats = new \Mu\Domain\Stats\CharacterStats(...array_merge(get_object_vars(new StatsCalculator()->calculate($knight)), ['comboDamage' => 500]));
        $battle = new Battle();
        $battle->hp = $stats->hpMax;
        $battle->mp = $stats->mpMax;
        $engine->startRound($battle, $map, $map->spots['spot1'], null, new FakeRng([1], 'min'));
        $battle->enemies[0]['hpCur'] = $battle->enemies[0]['hp'] = 1_000_000;
        foreach (['21', '21', '22'] as $skill) {
            $engine->act($battle, $stats, $skill, 0, new FakeRng([], 'max'), $now);
            self::assertNotContains('combo', array_column($battle->log, 'type'));
        }
        $engine->act($battle, $stats, '24', 0, new FakeRng([], 'max'), $now);
        self::assertContains(['type' => 'combo', 'target' => 0, 'n' => 500], $battle->log);
        self::assertSame([], $battle->combo);
    }

    public function testAtLevel400ExperienceRaisesTheMasterLevelOnlyForTheThirdClass(): void
    {
        $engine = new BattleEngine(new SkillCatalog($this->root . '/data/skills.php'), new DropGenerator($this->root . '/data/drop', $this->config['drop']), $this->config);
        $battle = new Battle();
        $battle->level = Battle::MAX_LEVEL;
        $need = MasterTree::experienceFor(0);

        $engine->grantExperience($battle, $need);
        self::assertSame([0, 0], [$battle->masterLevel, $battle->masterExp], 'sin tercera clase no hay nivel master');

        $battle->master = true;
        $engine->grantExperience($battle, $need - 1);
        self::assertSame([0, $need - 1, 0], [$battle->masterLevel, $battle->masterExp, $battle->masterUps]);
        $engine->grantExperience($battle, 1);
        self::assertSame([1, 0, 1], [$battle->masterLevel, $battle->masterExp, $battle->masterUps]);
        self::assertContains(['type' => 'master_up', 'n' => 1], $battle->log);
        self::assertGreaterThan($need, MasterTree::experienceFor(1), 'cada nivel master cuesta más');

        $battle->masterLevel = MasterTree::MAX_LEVEL;
        $engine->grantExperience($battle, 10_000_000);
        self::assertSame(MasterTree::MAX_LEVEL, $battle->masterLevel);
    }

    public function testMasterPointsOpenTheTreeTierByTierAndRaiseTheStats(): void
    {
        self::assertTrue(MasterTree::canLearn('hp', []));
        self::assertFalse(MasterTree::canLearn('defense', ['hp' => 2]), 'el segundo escalón pide 3 puntos en la rama');
        self::assertTrue(MasterTree::canLearn('defense', ['hp' => 3]));
        self::assertFalse(MasterTree::canLearn('damage_rate', ['hp' => 5]), 'los puntos de otra rama no cuentan');
        self::assertFalse(MasterTree::canLearn('hp', ['hp' => MasterTree::MAX_RANK]));
        self::assertFalse(MasterTree::canLearn('inventado', []));

        $this->pdo->exec('UPDATE characters SET class = 31, level = 400, master_level = 3, master_points = 2');
        self::assertTrue($this->characters->spendMasterPoint($this->id, 'hp'));
        self::assertTrue($this->characters->spendMasterPoint($this->id, 'hp'));
        self::assertFalse($this->characters->spendMasterPoint($this->id, 'hp'), 'sin puntos no sube');

        $character = $this->character();
        self::assertSame([['hp' => 2], 0], [$character->master, $character->masterPoints]);

        $plain = new StatsCalculator()->calculate(new Character(...array_merge(get_object_vars($character), ['master' => []])));
        $boosted = new StatsCalculator()->calculate($character);
        self::assertSame($plain->hpMax + intdiv($plain->hpMax * 6, 100), $boosted->hpMax, '2 rangos = +6% de vida');
        self::assertSame($plain->damageMax, $boosted->damageMax);

        $all = MasterTree::apply($plain, array_fill_keys(array_keys(MasterTree::PER_RANK), MasterTree::MAX_RANK));
        self::assertSame($plain->damageMax + intdiv($plain->damageMax * 15, 100), $all->damageMax);
        self::assertSame([$plain->luck + 5, $plain->ignoreDefense + 5, $plain->damageDecrease + 5], [$all->luck, $all->ignoreDefense, $all->damageDecrease]);

        $this->characters->saveMaster($this->id, 4, 1234, 1);
        $saved = $this->character();
        self::assertSame([4, 1234, 1], [$saved->masterLevel, $saved->masterExp, $saved->masterPoints]);
    }

    private function evolution(): EvolutionService
    {
        return new EvolutionService($this->characters, $this->accounts, new BattleRepository($this->pdo), new Transaction($this->pdo), $this->config['evolution']);
    }

    /** @return array{drops: list<array<string, mixed>>, map: string, kills: array<string, int>} */
    private function hunt(): array
    {
        return EvolutionService::hunt($this->character(), $this->config['evolution']);
    }

    /** Lo que BattleService anota al cerrar una ronda. */
    private function record(array $found, array $killed): void
    {
        $c = $this->character();
        [$step, $progress] = EvolutionService::record($c->evoStep, $c->evoProgress, $found, $killed);
        $this->characters->setEvoProgress($this->id, $step, $progress);
    }

    private function stats(): \Mu\Domain\Stats\CharacterStats
    {
        return new StatsCalculator()->calculate($this->character());
    }

    private function errorOf(\Closure $talk): string
    {
        try {
            $talk();
        } catch (InventoryException $e) {
            return $e->getMessage();
        }
        self::fail('Se esperaba un error');
    }

    private function account(): Account
    {
        return $this->accounts->findById($this->account->id) ?? self::fail('cuenta inexistente');
    }

    private function character(): Character
    {
        return $this->characters->findById($this->id) ?? self::fail('personaje inexistente');
    }
}
