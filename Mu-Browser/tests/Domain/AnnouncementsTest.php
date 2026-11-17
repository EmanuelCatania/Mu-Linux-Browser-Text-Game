<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Data\GoldenRepository;
use Mu\Domain\World\Announcements;
use Mu\Domain\World\GoldenInvasion;
use Mu\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Barra de anuncios: estado y orden de los eventos con horario. */
final class AnnouncementsTest extends TestCase
{
    public function testGoldenInvasionGoesFromLaterToSoonToActive(): void
    {
        $golden = new GoldenInvasion(new GoldenRepository(TestDatabase::create()), [8, 16]);
        $bar = new Announcements();
        $bar->add(static fn (DateTimeImmutable $now): array => [
            Announcements::timed('ticker.golden', $golden->isActive($now), $golden->secondsToChange($now), '/map'),
        ]);
        $at = static fn (string $time): array => $bar->current(new DateTimeImmutable("2026-01-05 $time", new DateTimeZone('UTC')))[0];

        self::assertSame([Announcements::LATER, '2 h 15 min'], [$at('05:45:00')['state'], $at('05:45:00')['time']]);
        self::assertSame([Announcements::LATER, '6 min'], [$at('07:54:30')['state'], $at('07:54:30')['time']]);
        self::assertSame([Announcements::SOON, '5 min'], [$at('07:55:00')['state'], $at('07:55:00')['time']]);
        self::assertSame([Announcements::SOON, '40 s'], [$at('07:59:20')['state'], $at('07:59:20')['time']]);
        self::assertSame([Announcements::ACTIVE, '1 h', '/map'], [$at('08:00:00')['state'], $at('08:00:00')['time'], $at('08:00:00')['link']]);
        self::assertSame([Announcements::ACTIVE, '30 s'], [$at('08:59:30')['state'], $at('08:59:30')['time']]);
        self::assertSame(Announcements::LATER, $at('09:00:00')['state']);
    }

    public function testWhatIsHappeningComesFirst(): void
    {
        $bar = new Announcements();
        $bar->add(static fn (): array => [Announcements::timed('a', false, 4000), Announcements::timed('b', false, 200)]);
        $bar->add(static fn (): array => [Announcements::timed('c', true, 900), Announcements::timed('d', false, 100)]);

        self::assertSame(['c', 'd', 'b', 'a'], array_column($bar->current(new DateTimeImmutable('now')), 'event'));
    }
}
