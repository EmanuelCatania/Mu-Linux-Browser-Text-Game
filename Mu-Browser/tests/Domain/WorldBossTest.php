<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\World\Announcements;
use Mu\Domain\World\MapCatalog;
use Mu\Domain\World\WorldBosses;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Jefes de mapa: uno por mapa para todo el servidor, con reaparición por tiempo. */
final class WorldBossTest extends TestCase
{
    public function testBossIsSharedAndComesBackAfterTheRespawnTime(): void
    {
        $bosses = new WorldBosses(TestDatabase::create(), 4 * 3600);
        $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable("2026-01-05 $time", new DateTimeZone('UTC'));

        self::assertSame('selupan', $bosses->aliveIn('raklion', $at('10:00:00')));
        self::assertNull($bosses->aliveIn('lorencia', $at('10:00:00')));

        self::assertTrue($bosses->defeat('selupan', 'Arthur', $at('10:00:00')));
        self::assertFalse($bosses->defeat('selupan', 'Merlin', $at('10:00:05')), 'sólo cuenta el primero');
        self::assertNull($bosses->aliveIn('raklion', $at('13:59:59')));

        $status = array_column($bosses->status($at('13:00:00')), null, 'monster')['selupan'];
        self::assertSame([false, 3600, 'Arthur'], [$status['alive'], $status['seconds'], $status['by']]);
        self::assertSame(Announcements::LATER, Announcements::boss('Selupan', 'Raklion', false, 3600)['state']);
        self::assertSame('ticker.boss_alive', Announcements::boss('Selupan', 'Raklion', true, 0)['key']);

        self::assertSame('selupan', $bosses->aliveIn('raklion', $at('14:00:00')));
        self::assertTrue($bosses->defeat('selupan', 'Merlin', $at('14:00:00')));
    }

    public function testEveryBossIsDefinedInItsMapWithImageAndAppearsInBattle(): void
    {
        $root = dirname(__DIR__, 2);
        $config = require $root . '/data/game.php';
        $maps = new MapCatalog($root . '/data/maps');
        $drops = new DropGenerator($root . '/data/drop', $config['drop']);
        $engine = new BattleEngine(new SkillCatalog($root . '/data/skills.php'), $drops, ['world_boss' => ['chance' => 100]] + $config);
        $now = new DateTimeImmutable('2026-01-05 12:00:00', new DateTimeZone('UTC'));

        $all = new WorldBosses(TestDatabase::create(), 3600)->status($now);
        self::assertCount(5, $all);
        foreach ($all as $boss) {
            $map = $maps->get($boss['map']) ?? self::fail("sin mapa {$boss['map']}");
            $monster = $map->monster($boss['monster']);
            self::assertNotNull($monster, "{$boss['monster']} no está definido en {$boss['map']}");
            self::assertFileExists($root . "/public/assets/img/monsters/{$boss['monster']}.gif");
            self::assertSame('box_of_kundun', $drops->drop($boss['monster'], $monster->level, new FakeRng(), $now)?->name);

            $battle = new Battle();
            $spot = array_values($map->spots)[0];
            $engine->startRound($battle, $map, $spot, null, new FakeRng([3], 'min'), $boss['monster']);
            self::assertSame(['world', '', ''], array_column($battle->enemies, 'kind'), 'aparece una sola vez por visita');
            self::assertSame($boss['monster'], $battle->enemies[0]['name']);

            // sin jefe vivo no se tira ningún dado de más
            $plain = new Battle();
            $engine->startRound($plain, $map, $spot, null, new FakeRng([1], 'min'));
            self::assertNotContains('world', array_column($plain->enemies, 'kind'));
        }
    }

    public function testFereaAndNixieContinueTheCurveOfTheOfficialMaps(): void
    {
        $maps = new MapCatalog(dirname(__DIR__, 2) . '/data/maps');
        $top = static function (string $id) use ($maps): int {
            $map = $maps->get($id) ?? self::fail("sin mapa $id");
            $hp = [];
            foreach ($map->spots as $spot) {
                foreach ($spot->encounters as $name) {
                    $hp[] = $map->monster($name)?->hp ?? 0;
                }
            }

            return max($hp);
        };

        self::assertLessThan(2 * $top('raklion'), $top('ferea'));
        self::assertGreaterThan($top('raklion'), $top('ferea'));
        self::assertGreaterThan($top('ferea'), $top('nixie'));
    }
}
