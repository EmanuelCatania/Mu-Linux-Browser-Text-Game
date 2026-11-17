<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Party\PartyService;
use Mu\Domain\World\Announcements;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\ScheduledEvents;
use Mu\Domain\World\WorldBosses;
use Mu\Domain\World\MapCatalog;
use Mu\Domain\World\Monster;
use Mu\Http\View;
use Mu\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Mapa del continente y de cada zona, con sus spots de batalla. */
final class MapController
{
    /** Hacia dónde se abre la etiqueta de cada mapa, para que no se tapen entre sí (están muy juntos). */
    private const array TAG_SIDE = [
        'lost_tower' => 'up', 'devias' => 'left', 'lorencia' => 'left', 'dungeon' => 'down', 'atlans' => 'down',
        'icarus' => 'left', 'kanturu' => 'up', 'karutan' => 'left', 'barracks' => 'left', 'raklion' => 'left',
        'swamp_of_peace' => 'down', 'aida' => 'down', 'nixie' => 'down',
    ];

    /** @param array<string, mixed> $config data/game.php */
    public function __construct(
        private readonly View $view,
        private readonly MapCatalog $maps,
        private readonly GoldenInvasion $golden,
        private readonly BattleService $battles,
        private readonly Clock $clock,
        private readonly array $config,
        private readonly ?WorldBosses $bosses = null,
        private readonly ?ScheduledEvents $events = null,
        private readonly ?PartyService $party = null,
    ) {
    }

    public function world(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = $this->clock->now();
        $character = $request->getAttribute('character');

        $points = [];
        foreach ($this->maps->all() as $id => $map) {
            [$x, $y] = MapCatalog::MAPS[$id];
            [$min, $max] = $map->levelRange();
            $points[] = [
                'id' => $id,
                'label' => $map->label(),
                'side' => self::TAG_SIDE[$id] ?? 'right',
                'left' => self::percent($x),
                'top' => self::percent($y),
                'min' => $min,
                'max' => $max,
            ];
        }

        $golden = [];
        foreach ($this->golden->status($now) as $row) {
            $golden[] = [
                'monster' => Monster::label($row['monster']),
                'map' => $row['map'] === null ? null : Monster::label($row['map']),
                'by' => $row['by'],
            ];
        }

        return $this->view->render($response, $request, 'map/world.twig', [
            'points' => $points,
            'golden' => $golden,
            'events' => array_map(fn (string $id): array => [
                'id' => $id,
                'open' => $this->events?->isOpen($id, $now) === true,
                'time' => Announcements::duration($this->events?->secondsToChange($id, $now) ?? 0),
            ], $this->events?->ids() ?? []),
            'event_closed' => $character instanceof Character && $character->eventMap !== null
                && ($scheduled = $this->events?->eventOf($character->eventMap)) !== null
                && $this->events?->isOpen($scheduled, $now) !== true,
            'bosses' => array_map(static fn (array $b): array => [
                'monster' => Monster::label($b['monster']),
                'map' => Monster::label($b['map']),
                'alive' => $b['alive'],
                'time' => Announcements::duration($b['seconds']),
                'by' => $b['by'],
            ], $this->bosses?->status($now) ?? []),
            'golden_active' => $this->golden->isActive($now),
            'golden_timer' => gmdate('H:i:s', $this->golden->secondsToChange($now)),
            'event_map' => $character instanceof Character && $character->eventMap !== null
                ? Monster::label($character->eventMap)
                : null,
        ]);
    }

    /** @param array{map: string} $args */
    public function zone(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // sólo los mapas del continente tienen página; a los de evento se entra directo a la batalla
        $map = (MapCatalog::exists($args['map']) ? $this->maps->get($args['map']) : null)
            ?? throw new HttpNotFoundException($request);

        $spots = [];
        foreach ($map->spots as $spot) {
            [$min, $max] = $map->levelRange($spot);
            $spots[] = [
                'id' => $spot->id,
                'number' => $spot->number(),
                'left' => self::percent($spot->x),
                'top' => self::percent($spot->y),
                'min' => $min,
                'max' => $max,
            ];
        }

        $selected = null;
        $spotId = $request->getQueryParams()['spot'] ?? null;
        $spot = is_string($spotId) ? $map->spot($spotId) : null;
        if ($spot !== null) {
            $monsters = [];
            foreach ([...$spot->encounters, ...($spot->boss === null ? [] : [$spot->boss])] as $name) {
                $monster = $map->monster($name);
                $monsters[] = [
                    'name' => $name,
                    'label' => Monster::label($name),
                    'level' => $monster?->level,
                    'stars' => $name === $spot->boss ? $spot->bossStars : 0,
                ];
            }
            $selected = [
                'id' => $spot->id,
                'number' => $spot->number(),
                'monsters' => $monsters,
                'players' => count($this->battles->playersAt($map->id, $spot->id)),
                'capacity' => (int) $this->config['spot_capacity'],
            ];
        }

        return $this->view->render($response, $request, 'map/zone.twig', [
            'map' => ['id' => $map->id, 'label' => $map->label()],
            'spots' => $spots,
            'selected' => $selected,
            'players' => $this->party?->inMap($map->id, $this->clock->now()) ?? [],
            'party' => $this->partyView($request->getAttribute('character')),
            'share' => (int) ($this->config['party']['share_percent'] ?? 0),
        ]);
    }

    /** @return array{id: ?int, members: list<array<string, mixed>>, inviter: ?string} */
    private function partyView(mixed $character): array
    {
        $state = $character instanceof Character ? $this->party?->state($character->id) : null;

        return [
            'id' => $state['party'] ?? null,
            'members' => isset($state['party']) ? ($this->party?->members($state['party']) ?? []) : [],
            'inviter' => isset($state['invite']) ? $state['inviter'] : null,
        ];
    }

    /** Posición en % sobre la imagen del mapa, para que escale con el ancho de pantalla. */
    private static function percent(int $pixels): string
    {
        return number_format($pixels / MapCatalog::IMAGE_SIZE * 100, 2, '.', '');
    }
}
