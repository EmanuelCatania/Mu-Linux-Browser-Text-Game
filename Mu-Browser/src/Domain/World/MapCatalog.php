<?php

declare(strict_types=1);

namespace Mu\Domain\World;

use RuntimeException;

/** Mapas del juego, leídos de data/maps/<id>.xml (monstruos y spots de cada uno). */
final class MapCatalog
{
    /**
     * Mapas en el orden del juego, con su posición (x, y) sobre el mapa del continente de 572x572.
     * Las coordenadas son las de map.css del original.
     */
    public const array MAPS = [
        'lorencia' => [280, 188],
        'noria' => [372, 248],
        'elbeland' => [318, 136],
        'dungeon' => [336, 202],
        'devias' => [230, 150],
        'lost_tower' => [254, 116],
        'atlans' => [424, 188],
        'tarkan' => [492, 176],
        // Mapas agregados después (no estaban en el original): ubicados sobre sus nombres en el mapa mundial.
        'icarus' => [196, 104],
        'aida' => [392, 330],
        'kanturu' => [448, 128],
        'kanturu_relics' => [512, 146],
        'karutan' => [262, 318],
        'crywolf' => [306, 284],
        'barracks' => [226, 266],
        'swamp_of_peace' => [356, 436],
        'vulcanus' => [456, 416],
        'raklion' => [150, 202],
        'ferea' => [62, 292],
        'nixie' => [200, 404],
    ];

    /** Mapas de evento: no están en el continente, se entra con un ítem (Lost Map para Kalima). */
    public const array EVENT_MAPS = [
        'kalima_1', 'kalima_2', 'kalima_3', 'kalima_4', 'kalima_5', 'kalima_6', 'kalima_7',
        // eventos con horario (ScheduledEvents); los mapas los genera tools/events.py
        'devil_square_1', 'devil_square_2', 'devil_square_3', 'devil_square_4', 'devil_square_5', 'devil_square_6', 'devil_square_7',
        'blood_castle_1', 'blood_castle_2', 'blood_castle_3', 'blood_castle_4', 'blood_castle_5', 'blood_castle_6', 'blood_castle_7',
    ];

    /** Lado en píxeles de las imágenes de mapa; las coordenadas de los XML están en esa escala. */
    public const int IMAGE_SIZE = 572;

    /** @var array<string, GameMap> */
    private array $loaded = [];

    public function __construct(private readonly string $dir)
    {
    }

    public static function exists(string $id): bool
    {
        return isset(self::MAPS[$id]);
    }

    public static function isEvent(string $id): bool
    {
        return in_array($id, self::EVENT_MAPS, true);
    }

    /** Un mapa del continente o de evento. */
    public function get(string $id): ?GameMap
    {
        if (!self::exists($id) && !self::isEvent($id)) {
            return null;
        }

        return $this->loaded[$id] ??= $this->load($id);
    }

    /** @return array<string, GameMap> */
    public function all(): array
    {
        $maps = [];
        foreach (array_keys(self::MAPS) as $id) {
            $maps[$id] = $this->get($id) ?? throw new RuntimeException("Mapa inexistente: $id");
        }

        return $maps;
    }

    private function load(string $id): GameMap
    {
        $xml = simplexml_load_file($this->dir . '/' . $id . '.xml');
        if ($xml === false) {
            throw new RuntimeException("No se pudo leer el mapa $id.");
        }

        $monsters = [];
        foreach ($xml->monster as $node) {
            $name = (string) $node['name'];
            $attribute = trim((string) $node->attribute);
            $monsters[$name] = new Monster(
                name: $name,
                level: (int) $node->level,
                hp: (int) $node->hp,
                damage: (int) $node->damage,
                defense: (int) $node->defense,
                damageRate: (int) $node->damage_rate,
                defenseRate: (int) $node->defense_rate,
                // Los datos originales escriben "lighting" por lightning.
                attribute: match ($attribute) {
                    '', 'none' => null,
                    'lighting' => 'lightning',
                    default => $attribute,
                },
            );
        }

        $spots = [];
        foreach ($xml->spot as $node) {
            $encounters = [];
            $boss = null;
            $stars = 0;
            foreach ($node->encounter as $encounter) {
                $name = trim((string) $encounter);
                if (!isset($monsters[$name])) {
                    throw new RuntimeException("El mapa $id nombra al monstruo $name pero no lo define.");
                }
                if (isset($encounter['boss'])) {
                    $boss = $name;
                    $stars = (int) $encounter['boss'];
                } else {
                    $encounters[] = $name;
                }
            }
            $spotId = (string) $node['id'];
            $spots[$spotId] = new Spot($spotId, (int) $node['x'], (int) $node['y'], $encounters, $boss, $stars);
        }

        return new GameMap($id, $monsters, $spots);
    }
}
