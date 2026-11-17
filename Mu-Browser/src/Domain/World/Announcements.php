<?php

declare(strict_types=1);

namespace Mu\Domain\World;

use Closure;
use DateTimeImmutable;

/**
 * Anuncios de la barra que se ve en todas las pantallas: qué evento está en curso y cuánto falta para los demás.
 * Cada evento con horario se registra como una fuente; la barra los muestra ordenados por urgencia.
 */
final class Announcements
{
    /** En curso. */
    public const string ACTIVE = 'active';
    /** Empieza dentro de SOON_SECONDS. */
    public const string SOON = 'soon';
    /** Falta más. */
    public const string LATER = 'later';

    public const int SOON_SECONDS = 300;

    /** @var list<Closure(DateTimeImmutable): list<array{event: string, state: string, seconds: int, link: ?string}>> */
    private array $sources = [];

    /** @param Closure(DateTimeImmutable): list<array{event: string, state: string, seconds: int, link: ?string}> $source */
    public function add(Closure $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * Anuncio de un evento con horario.
     *
     * @param string $event   clave de traducción del nombre del evento
     * @param int    $seconds si está activo, lo que le queda; si no, lo que falta para que empiece
     *
     * @return array{event: string, state: string, seconds: int, link: ?string}
     */
    public static function timed(string $event, bool $active, int $seconds, ?string $link = null): array
    {
        return [
            'event' => $event,
            'state' => $active ? self::ACTIVE : ($seconds <= self::SOON_SECONDS ? self::SOON : self::LATER),
            'seconds' => max(0, $seconds),
            'link' => $link,
        ];
    }

    /**
     * Anuncio de un jefe de mapa: amarillo mientras está vivo, y si no la cuenta regresiva hasta que vuelva.
     *
     * @return array{event: string, state: string, seconds: int, link: ?string, name: string, place: string, key?: string}
     */
    public static function boss(string $name, string $place, bool $alive, int $seconds, ?string $link = null): array
    {
        return self::timed('', $alive, $seconds, $link) + ['name' => $name, 'place' => $place] + ($alive ? ['key' => 'ticker.boss_alive'] : []);
    }

    /**
     * @return list<array{event: string, state: string, seconds: int, link: ?string, time: string}>
     *         primero lo que está en curso, después lo que está por empezar y al final lo que falta más
     */
    public function current(DateTimeImmutable $now): array
    {
        $items = [];
        foreach ($this->sources as $source) {
            foreach ($source($now) as $item) {
                $items[] = $item + ['time' => self::duration($item['seconds'])];
            }
        }
        $order = [self::ACTIVE => 0, self::SOON => 1, self::LATER => 2];
        usort($items, static fn (array $a, array $b): int => [$order[$a['state']], $a['seconds']] <=> [$order[$b['state']], $b['seconds']]);

        return $items;
    }

    /** "45 s", "5 min", "2 h 15 min": igual en los tres idiomas. */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' s';
        }
        $minutes = intdiv($seconds + 59, 60);
        if ($minutes < 60) {
            return $minutes . ' min';
        }

        return intdiv($minutes, 60) . ' h' . ($minutes % 60 > 0 ? ' ' . ($minutes % 60) . ' min' : '');
    }
}
