<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Domain\Item\Item;
use Mu\Domain\Npc\ChaosMachine;
use Mu\Domain\World\MapCatalog;
use Mu\Domain\World\ScheduledEvents;
use Mu\Tests\Support\FakeRng;
use PHPUnit\Framework\TestCase;

/** Devil Square y Blood Castle: horario, nivel según el personaje, mapas y entrada. */
final class ScheduledEventsTest extends TestCase
{
    public function testEventsOpenForAFewMinutesAtTheirHours(): void
    {
        $events = new ScheduledEvents(['devil_square' => ['hours' => [1, 13], 'minutes' => 15], 'blood_castle' => ['hours' => [3], 'minutes' => 10]]);
        $at = static fn (string $time): DateTimeImmutable => new DateTimeImmutable("2026-01-05 $time", new DateTimeZone('UTC'));

        self::assertTrue($events->isOpen('devil_square', $at('13:00:00')));
        self::assertSame(60, $events->secondsToChange('devil_square', $at('13:14:00')));
        self::assertFalse($events->isOpen('devil_square', $at('13:15:00')));
        self::assertSame(11 * 3600 + 45 * 60, $events->secondsToChange('devil_square', $at('13:15:00')), 'hasta la 1 del día siguiente');
        self::assertSame(300, $events->secondsToChange('devil_square', $at('12:55:00')));
        self::assertSame(24 * 3600 - 600, $events->secondsToChange('blood_castle', $at('03:10:00')), 'con una sola hora, vuelve mañana');
        self::assertFalse($events->isOpen('chaos_castle', $at('13:00:00')));

        self::assertSame('devil_square', $events->eventOf('devil_square_4'));
        self::assertNull($events->eventOf('kalima_4'), 'Kalima no tiene horario');
    }

    public function testEventLevelComesFromTheCharacterLevel(): void
    {
        self::assertSame([1, 1, 2, 3, 6, 7, 7], array_map(ScheduledEvents::tierFor(...), [1, 79, 80, 179, 329, 330, 400]));
        self::assertSame('blood_castle_4', ScheduledEvents::mapFor('blood_castle', 200));
    }

    public function testEveryEventMapHasItsFifteenRoundsAndMonsterImages(): void
    {
        $root = dirname(__DIR__, 2);
        $maps = new MapCatalog($root . '/data/maps');
        $previous = ['devil_square' => 0, 'blood_castle' => 0];
        foreach (['devil_square', 'blood_castle'] as $event) {
            for ($tier = 1; $tier <= 7; $tier++) {
                $id = "{$event}_$tier";
                self::assertTrue(MapCatalog::isEvent($id));
                $map = $maps->get($id) ?? self::fail("sin mapa $id");
                for ($spot = 1; $spot <= 14; $spot++) {
                    $s = $map->spot("spot$spot") ?? self::fail("$id sin spot$spot");
                    foreach ($s->encounters as $name) {
                        self::assertNotNull($map->monster($name), "$id: $name sin definir");
                        self::assertFileExists($root . "/public/assets/img/monsters/$name.gif");
                    }
                }
                $boss = $map->monster($map->spot('spot1')->encounters[0])->level;
                self::assertGreaterThan($previous[$event], $boss, 'cada nivel de evento es más difícil que el anterior');
                $previous[$event] = $boss;
            }
        }
    }

    public function testDevilsInvitationIsMadeInTheChaosMachine(): void
    {
        $items = array_map(Item::parse(...), [
            'I.11.6.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.devils_eye',
            'I.11.7.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.devils_key',
            'E.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.jewel_of_chaos',
        ]);

        $made = ChaosMachine::mix($items, new FakeRng([0]));
        self::assertTrue($made->success);
        self::assertSame(['devil_square_invitation', 'K', '3'], [$made->items[0]->name, $made->items[0]->type, $made->items[0]->subType]);
        self::assertFalse(ChaosMachine::mix($items, new FakeRng([1]))->success);
    }
}
