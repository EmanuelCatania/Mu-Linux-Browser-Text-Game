<?php

declare(strict_types=1);

namespace Mu\Tests\Battle;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleException;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\MapCatalog;
use Mu\Tests\Support\FakeRng;
use PHPUnit\Framework\TestCase;

/**
 * Reglas de combate, con azar controlado. Referencias de Lorencia:
 *   spider        nivel 2, vida 3, daño 7, defensa 1, tasa de ataque 8, tasa de defensa 1
 * Dark Wizard nivel 1: vida 61, maná 62, daño mágico 3~7, defensa 4, tasa de defensa 6, tasa de ataque 18
 *   Energy Ball (30): 6~11 de daño, 1 de maná.
 */
final class BattleEngineTest extends TestCase
{
    private BattleEngine $engine;
    private MapCatalog $maps;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/data/game.php';
        $this->maps = new MapCatalog($root . '/data/maps');
        $this->engine = new BattleEngine(
            new SkillCatalog($root . '/data/skills.php'),
            new DropGenerator($root . '/data/drop', $config['drop']),
            $config,
        );
        $this->now = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));
    }

    public function testFairyElfSummonFightsAndTakesHalfOfTheBlows(): void
    {
        $stats = self::stats(CharacterClass::FairyElf, ['57'], 100);
        $battle = $this->battle($stats);
        $battle->mp = 100;
        $battle->enemies[0]['hpCur'] = $battle->enemies[0]['hp'] = 500;

        // 1.ª tirada: golpe de la invocación (10); 2.ª: el enemigo va contra ella (1); 3.ª: cuánto le pega
        $this->engine->act($battle, $stats, '57', 0, new FakeRng([10, 1], 'min'), $this->now);
        self::assertSame('57', $battle->summon);
        self::assertSame(['summon', 'summon_dmg', 'summon_hit'], self::types($battle));
        self::assertSame($stats->hpMax, $battle->hp, 'el golpe fue para la invocación');
        self::assertLessThan(60, $battle->summonHp);
        self::assertLessThan(500, $battle->enemies[0]['hpCur']);

        // con la tirada en 0 el enemigo le pega a la Elf
        $before = $battle->summonHp;
        $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([], 'min'), $this->now);
        self::assertSame($before, $battle->summonHp);

        // cuando se queda sin vida desaparece
        $battle->summonHp = 1;
        $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([1, 1, 1, 1], 'max'), $this->now);
        self::assertSame(['', 0], [$battle->summon, $battle->summonHp]);
        self::assertContains('summon_dead', self::types($battle));
    }

    /** @param list<string> $skills */
    private static function stats(CharacterClass $class, array $skills = [], int $energy = 0): CharacterStats
    {
        $s = $class->startingStats();
        $character = new Character(1, 1, 'Test', $class, 1, $s['strength'], $s['agility'], $s['stamina'], $energy ?: $s['energy'], 0, 0, 0, $s['hp'], $s['mp'], $skills);

        return new StatsCalculator()->calculate($character);
    }

    /** Batalla contra $count arañas de Lorencia. */
    private function battle(CharacterStats $stats, int $count = 1): Battle
    {
        $battle = new Battle();
        $battle->map = 'lorencia';
        $battle->spot = 'spot1';
        $battle->hp = $stats->hpMax;
        $battle->mp = $stats->mpMax;

        $map = $this->maps->get('lorencia');
        self::assertNotNull($map);
        // primera tirada = cantidad de enemigos; el resto elige siempre la araña (único encuentro del spot 1)
        $this->engine->startRound($battle, $map, $map->spots['spot1'], null, new FakeRng([$count], 'min'));
        self::assertCount($count, $battle->enemies);

        return $battle;
    }

    /** @return list<string> */
    private static function types(Battle $battle): array
    {
        return array_column($battle->log, 'type');
    }

    public function testStartRoundBuildsEnemiesFromTheSpot(): void
    {
        $battle = $this->battle(self::stats(CharacterClass::DarkWizard, ['30']), 3);

        self::assertSame(['spider', 'spider', 'spider'], array_column($battle->enemies, 'name'));
        self::assertSame([3, 3, false, ''], [$battle->enemies[0]['hp'], $battle->enemies[0]['hpCur'], $battle->enemies[0]['dead'], $battle->enemies[0]['kind']]);
        self::assertFalse($battle->roundOver());
    }

    public function testBossAppearsAtMostOncePerVisit(): void
    {
        $map = null;
        $spot = null;
        foreach ($this->maps->all() as $candidateMap) {
            foreach ($candidateMap->spots as $candidate) {
                if ($spot === null && $candidate->boss !== null) {
                    [$map, $spot] = [$candidateMap, $candidate];
                }
            }
        }
        self::assertNotNull($map);
        self::assertNotNull($spot, 'se esperaba algún spot con jefe');

        $battle = new Battle();
        // 5 enemigos y todas las tiradas al mínimo: el jefe sale en el primer lugar y no se repite
        $this->engine->startRound($battle, $map, $spot, null, new FakeRng([5], 'min'));

        self::assertSame('boss', $battle->enemies[0]['kind']);
        self::assertSame($spot->boss, $battle->enemies[0]['name']);
        self::assertSame(['', '', '', ''], array_slice(array_column($battle->enemies, 'kind'), 1));
        self::assertTrue($battle->bossSeen);

        $this->engine->startRound($battle, $map, $spot, null, new FakeRng([5], 'min'));
        self::assertNotContains('boss', array_column($battle->enemies, 'kind'), 'en la ronda siguiente ya no aparece');
    }

    public function testGoldenMonsterSpawnsWhenAvailable(): void
    {
        $map = $this->maps->get('lorencia');
        self::assertNotNull($map);
        $battle = new Battle();

        $spawned = $this->engine->startRound($battle, $map, $map->spots['spot1'], 'golden_budge_dragon', new FakeRng([2], 'min'));

        self::assertTrue($spawned);
        self::assertSame(['golden', ''], array_column($battle->enemies, 'kind'));
        self::assertSame('golden_budge_dragon', $battle->enemies[0]['name']);

        $again = $this->engine->startRound($battle, $map, $map->spots['spot1'], 'golden_budge_dragon', new FakeRng([2], 'min'));
        self::assertFalse($again);
    }

    public function testSkillHitKillsAndRewards(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);

        // todas las tiradas al máximo: daño 11 - defensa 1 = 10, no falla, exp 6, zen 10, sin drop
        $this->engine->act($battle, $stats, '30', 0, new FakeRng([], 'max'), $this->now);

        self::assertSame(['dmg', 'kill'], self::types($battle));
        self::assertSame(10, $battle->log[0]['n']);
        self::assertSame(61, $battle->mp, 'Energy Ball cuesta 1 de maná');
        self::assertSame(61, $battle->hp, 'la araña murió antes de pegar');
        self::assertTrue($battle->enemies[0]['dead']);
        self::assertSame([6, 10, null], [$battle->enemies[0]['exp'], $battle->enemies[0]['zen'], $battle->enemies[0]['item']]);
        self::assertSame([6, 6, 10, 1, 1, 0], [$battle->experience, $battle->expEarned, $battle->zen, $battle->kills, $battle->level, $battle->levelUps]);
        self::assertTrue($battle->roundOver());
        self::assertSame(2, $battle->turn);
    }

    public function testMissAndEnemyCounterAttack(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);

        // daño (6), tirada de fallo 1 → falla (5% mínimo); la araña pega 7 - defensa 4 = 3 y no falla (100)
        $this->engine->act($battle, $stats, '30', 0, new FakeRng([6, 1, 7, 100]), $this->now);

        self::assertSame(['miss', 'enemy_dmg'], self::types($battle));
        self::assertSame(3, $battle->log[1]['n']);
        self::assertSame(58, $battle->hp);
        self::assertSame(3, $battle->enemies[0]['hpCur']);
        self::assertFalse($battle->roundOver());
    }

    public function testEnemyDamageHasAFloorAndCanMiss(): void
    {
        $stats = self::stats(CharacterClass::DarkKnight);
        $battle = $this->battle($stats);

        // ataque normal: daño 4 - 1 = 3 mata a la araña... se fuerza el fallo (1) para que siga viva.
        // La araña tira 5 de daño - 6 de defensa → mínimo 1 (nivel/10 redondeado arriba); tirada 100 → pega.
        $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([4, 1, 5, 100]), $this->now);
        self::assertSame(1, $battle->log[1]['n']);

        // ahora la araña falla: tasa de defensa 6 contra tasa de ataque 8 → 37% de fallo
        $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([4, 1, 5, 37]), $this->now);
        self::assertSame(['miss', 'enemy_miss'], self::types($battle));
    }

    public function testLevelUpIsCappedToOneLevelPerKill(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);
        $battle->experience = 9; // faltaba 1 para el nivel 2 (10)

        $this->engine->act($battle, $stats, '30', 0, new FakeRng([], 'max'), $this->now);

        self::assertSame([2, 1, 10], [$battle->level, $battle->levelUps, $battle->experience], 'la experiencia queda justo en el umbral');
        self::assertContains('level_up', self::types($battle));
        self::assertSame(44, Battle::experienceFor(2));
    }

    public function testTwoKillsInOneTurnGiveAtMostTwoLevels(): void
    {
        $stats = self::stats(CharacterClass::DarkKnight, ['22']);
        $battle = $this->battle($stats, 2);
        $battle->experience = 9;

        // Twisting Slash pega a los dos
        $this->engine->act($battle, $stats, '22', 0, new FakeRng([], 'max'), $this->now);

        self::assertSame([true, true], array_column($battle->enemies, 'dead'));
        self::assertSame(2, $battle->level, 'la segunda baja suma experiencia normal sobre el nivel nuevo');
        self::assertSame(10 + 6, $battle->experience);
    }

    public function testAreaSkillHitsUpToThreeTargets(): void
    {
        $stats = self::stats(CharacterClass::DarkKnight, ['22']);
        $battle = $this->battle($stats, 5);

        $this->engine->act($battle, $stats, '22', 2, new FakeRng([], 'max'), $this->now);

        self::assertSame([true, true, true, false, false], array_column($battle->enemies, 'dead'), 'el elegido y los dos primeros que siguen vivos');
        self::assertSame(3, $battle->kills);
    }

    public function testNotEnoughManaStillCostsTheTurn(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);
        $battle->mp = 0;

        $this->engine->act($battle, $stats, '30', 0, new FakeRng([7, 100]), $this->now);

        self::assertSame(['no_mp', 'enemy_dmg'], self::types($battle));
        self::assertSame(3, $battle->enemies[0]['hpCur']);
    }

    public function testDefeatHalvesTheExperienceOfTheCurrentLevel(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);
        $battle->level = 2;
        $battle->experience = 30; // nivel 2 empieza en 10: lleva ganados 20
        $battle->hp = 2;

        $this->engine->act($battle, $stats, '30', 0, new FakeRng([6, 1, 7, 100]), $this->now);

        self::assertTrue($battle->defeated);
        self::assertSame(0, $battle->hp);
        self::assertSame(20, $battle->experience);
        self::assertSame('defeat', $battle->log[array_key_last($battle->log)]['type']);

        $this->expectExceptionObject(new BattleException('battle.error_defeated'));
        $this->engine->act($battle, $stats, '30', 0, new FakeRng(), $this->now);
    }

    public function testBuffOnSelfLastsItsDurationAndRaisesMaxHp(): void
    {
        $stats = self::stats(CharacterClass::DarkKnight, ['23']);
        $battle = $this->battle($stats);
        $battle->mp = 100;

        // Greater Fortitude sobre uno mismo; la araña falla (tirada 1)
        $this->engine->act($battle, $stats, '23', null, new FakeRng([7, 1]), $this->now);

        self::assertSame('effect', $battle->log[0]['type']);
        self::assertSame(['23', 20], [$battle->effect, $battle->effectTurns]);
        self::assertSame(78, $battle->mp);
        // 12% + ENE/20 (energía 10 → 12%) de 112
        self::assertSame(112 + 13, $this->engine->hpMax($battle, $stats));

        for ($i = 0; $i < 20; $i++) {
            $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([4, 1, 7, 1]), $this->now);
        }
        self::assertSame('', $battle->effect);
        self::assertSame(112, $this->engine->hpMax($battle, $stats));
    }

    public function testDefenseSkillHalvesIncomingDamage(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30', '8']);
        $battle = $this->battle($stats);

        $this->engine->act($battle, $stats, '8', null, new FakeRng([7, 100]), $this->now);

        // 7 - 4 = 3 → la mitad redondeada hacia arriba
        self::assertSame(2, $battle->log[1]['n']);
    }

    public function testSleepSkipsTheEnemyTurnUntilItIsHit(): void
    {
        $stats = self::stats(CharacterClass::Summoner, ['71']);
        $battle = $this->battle($stats);

        // Sleep no tira daño; tirada de fallo 100 → entra
        $this->engine->act($battle, $stats, '71', 0, new FakeRng([100]), $this->now);
        self::assertSame(['effect'], self::types($battle), 'dormida, no ataca');
        self::assertSame('71', $battle->enemies[0]['effect']);

        // un golpe que falla igual la despierta, y vuelve a atacar
        $this->engine->act($battle, $stats, BattleEngine::ATTACK, 0, new FakeRng([1, 1, 7, 100]), $this->now);
        self::assertSame(['miss', 'enemy_dmg'], self::types($battle));
    }

    public function testPoisonSkillKeepsHurtingTheEnemy(): void
    {
        $map = $this->maps->get('lorencia');
        self::assertNotNull($map);
        $stats = self::stats(CharacterClass::DarkWizard, ['36']);
        $battle = new Battle();
        $battle->hp = $stats->hpMax;
        $battle->mp = $stats->mpMax;
        // spot 5 de Lorencia tiene monstruos con bastante vida; se elige el último encuentro
        $this->engine->startRound($battle, $map, $map->spots['spot5'], null, new FakeRng([1, 99]));
        $before = $battle->enemies[0]['hpCur'];
        self::assertGreaterThan(30, $before);

        $this->engine->act($battle, $stats, '36', 0, new FakeRng([15, 100, 1, 1]), $this->now);

        self::assertSame('36', $battle->enemies[0]['effect']);
        self::assertSame(10, $battle->enemies[0]['effectTurns']);
        self::assertContains('poison', self::types($battle));
        $afterHit = $before - $battle->log[0]['n'];
        self::assertSame($afterHit - intdiv($afterHit, 30), $battle->enemies[0]['hpCur']);
    }

    public function testInvalidActionsDoNotSpendTheTurn(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats, 2);
        $this->engine->act($battle, $stats, '30', 0, new FakeRng([], 'max'), $this->now);
        $turn = $battle->turn;

        $cases = [
            'habilidad que no conoce' => ['31', 1, 'battle.error_skill'],
            'habilidad inexistente' => ['999', 1, 'battle.error_skill'],
            'habilidad sin combate (invocación)' => ['57', 1, 'battle.error_skill'],
            'enemigo muerto' => ['30', 0, 'battle.error_target'],
            'enemigo inexistente' => ['30', 7, 'battle.error_target'],
            'índice negativo' => ['30', -1, 'battle.error_target'],
            'atacarse a uno mismo' => [BattleEngine::ATTACK, null, 'battle.error_target'],
        ];
        foreach ($cases as $label => [$action, $target, $expected]) {
            try {
                $this->engine->act($battle, $stats, $action, $target, new FakeRng(), $this->now);
                self::fail("Se esperaba un error: $label");
            } catch (BattleException $e) {
                self::assertSame($expected, $e->getMessage(), $label);
            }
        }
        self::assertSame($turn, $battle->turn);
    }

    public function testCannotActOnceTheRoundIsOver(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats);
        $this->engine->act($battle, $stats, '30', 0, new FakeRng([], 'max'), $this->now);

        $this->expectExceptionObject(new BattleException('battle.error_round_over'));
        $this->engine->act($battle, $stats, '30', 0, new FakeRng(), $this->now);
    }

    public function testExperienceIsSharedWhenTheSpotIsCrowded(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);

        $penalised = $this->battle($stats);
        $penalised->expPercent = 50;
        $this->engine->act($penalised, $stats, '30', 0, new FakeRng([], 'max'), $this->now);
        self::assertSame([3, -3], [$penalised->enemies[0]['exp'], $penalised->enemies[0]['expDelta']]);
        self::assertSame(6, $penalised->expEarned, 'el ritmo se mide con la experiencia base');

        $boosted = $this->battle($stats);
        $boosted->expPercent = 150;
        $this->engine->act($boosted, $stats, '30', 0, new FakeRng([], 'max'), $this->now);
        self::assertSame(9, $boosted->experience);
    }

    public function testStateSurvivesJsonRoundTrip(): void
    {
        $stats = self::stats(CharacterClass::DarkWizard, ['30']);
        $battle = $this->battle($stats, 2);
        $this->engine->act($battle, $stats, '30', 0, new FakeRng([], 'max'), $this->now);

        $copy = Battle::fromArray(json_decode(json_encode($battle->toArray(), JSON_THROW_ON_ERROR), true));

        self::assertEquals($battle, $copy);
    }
}
