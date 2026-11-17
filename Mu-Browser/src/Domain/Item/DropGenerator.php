<?php

declare(strict_types=1);

namespace Mu\Domain\Item;

use DateTimeImmutable;
use Mu\Support\Rng;
use RuntimeException;

/** Qué ítem deja un monstruo al morir (port de item_generator.php). Las tablas están en data/drop/. */
final class DropGenerator
{
    private const string CHERRY_BOX = 'H.11.3.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.cherry_blossom_box';

    /** Los monstruos dorados siempre dejan su caja. */
    private const array GOLDEN_BOXES = [
        'golden_budge_dragon' => 'H.11.1.0000.00.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_luck',
        'golden_goblin' => 'H.11.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_rabbit' => 'H.11.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_knight' => 'H.11.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_titan' => 'H.11.2.0000.01.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_devil' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_lizard_king' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        // dorados de los mapas agregados después del original
        'golden_tantalos' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_crust' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_stone_golem' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_satyros' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_twin_tail' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_napin' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'great_golden_dragon' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'golden_iron_knight' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        // jefes de mapa
        'nightmare' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'selupan' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'medusa' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'lord_of_ferea' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
        'nix' => 'H.11.2.0000.02.0.0000.0000.00.0.0.00.00.0.000.000.000000.box_of_kundun',
    ];

    /** Tabla de ítems comunes según nivel del monstruo: [nivel máximo, archivo]. */
    private const array REGULAR = [
        [10, 'regular1'], [20, 'regular1.5'], [30, 'regular2'], [40, 'regular2.5'], [50, 'regular3'],
        [60, 'regular4'], [70, 'regular5'], [80, 'regular6'], [90, 'regular7'], [PHP_INT_MAX, 'regular8'],
    ];
    private const array SKILLS = [[30, 'skills1'], [50, 'skills2'], [70, 'skills3'], [90, 'skills4'], [PHP_INT_MAX, 'skills5']];
    private const array PARTS = [
        [35, 'parts1'], [50, 'parts2'], [60, 'parts3'], [70, 'parts4'], [80, 'parts5'], [90, 'parts6'], [PHP_INT_MAX, 'parts7'],
    ];

    private const array WEARABLE = ['1', '2', '3', '4', '5', '6', '7', '9'];

    /** @var array<string, list<string>> */
    private array $tables = [];

    /** @param array<string, mixed> $config sección `drop` de data/game.php */
    public function __construct(private readonly string $dir, private readonly array $config)
    {
    }

    /** @return ?Item null la mayoría de las veces: casi todos los monstruos no dejan nada */
    public function drop(string $monster, int $level, Rng $rng, DateTimeImmutable $now): ?Item
    {
        $oneIn = fn (string $key): bool => $rng->int(0, (int) $this->config[$key] - 1) < 1;

        if ($now->format('D') === 'Sun' && $oneIn('sunday_box_one_in')) {
            return Item::parse(self::CHERRY_BOX);
        }
        if (isset(self::GOLDEN_BOXES[$monster])) {
            return Item::parse(self::GOLDEN_BOXES[$monster]);
        }

        $regular = false;
        if ($oneIn('regular_one_in')) {
            $table = self::tier(self::REGULAR, $level);
            $regular = true;
        } elseif ($oneIn('skills_one_in') && $level < 121) {
            $table = self::tier(self::SKILLS, $level);
        } elseif ($oneIn('parts_one_in') && $level > 20 && $level < 121) {
            $table = self::tier(self::PARTS, $level);
        } elseif ($oneIn('rings_one_in') && $level > 20) {
            $table = $level < 71 ? 'rings1' : 'rings2';
        } elseif ($oneIn('jewels_one_in')) {
            $table = $level < 71 ? 'jewels1' : 'jewels2';
        } else {
            return null;
        }

        $codes = $this->table($table);
        $item = Item::parse($codes[$rng->int(0, count($codes) - 1)]);

        if ($regular && $this->config['regular_quality'] === 'normal') {
            $item = $this->rerollQuality($item, $rng);
        }

        return $this->randomize($item, $rng);
    }

    /** Quita la marca excellent que traen las tablas y la vuelve a sortear con la probabilidad configurada. */
    private function rerollQuality(Item $item, Rng $rng): Item
    {
        $excellent = 0;
        if (in_array($item->type, self::WEARABLE, true) && $rng->int(1, 100) <= (int) $this->config['excellent_chance']) {
            $excellent = $rng->int(1, 6);
        }

        return $item->with(['excellent' => $excellent, 'harmonyType' => 0, 'harmonyValue' => 0]);
    }

    /** Nivel, opción, habilidad y luck al azar, como en el original. */
    private function randomize(Item $item, Rng $rng): Item
    {
        $type = $item->type;
        $wearable = in_array($type, self::WEARABLE, true);
        $jewelry = $type === 'A' || $type === 'B';
        $changes = [];

        if ($wearable || $jewelry) {
            // nivel +1 (33%) o +2 (10% del resto)
            if ($rng->int(0, 2) < 1) {
                $changes['level'] = $item->level + 1;
            } elseif ($rng->int(0, 9) < 1) {
                $changes['level'] = $item->level + 2;
            }

            $option = 0;
            if ($wearable) {
                if ($rng->int(0, 4) < 1) {
                    $option = 1;
                } elseif ($rng->int(0, 19) < 1) {
                    $option = 2;
                }
            } else {
                if ($rng->int(0, 2) < 1) {
                    $option = 1;
                } elseif ($rng->int(0, 4) < 1) {
                    $option = 2;
                } elseif ($rng->int(0, 9) < 1) {
                    $option = 3;
                } elseif ($rng->int(0, 49) < 1) {
                    $option = 4;
                }
            }
            $changes['option'] = $option;
        }

        // habilidad del arma o escudo (25%)
        if (in_array($type, ['1', '2', '9'], true) && $rng->int(0, 3) < 1) {
            $weapon = $type === '1' || $type === '2';
            $changes['skill'] = match (true) {
                $weapon && $item->subType === '1' => (string) $rng->int(3, 6),          // espadas
                $weapon && $item->subType === '2' => '7',                              // hachas: Falling Slash
                $type === '2' && in_array($item->subType, ['8', '9'], true) => '9',    // arcos y ballestas: Triple Shot
                $type === '9' => '8',                                                  // escudos: Defense
                $type === '1' && $item->subType === '5' && $item->effect === 23 => 'A', // libros de Summoner
                $type === '1' && $item->subType === '5' && $item->effect === 29 => 'B',
                $type === '1' && $item->subType === '5' && $item->effect === 36 => 'C',
                default => '0',
            };
        }

        // luck (25%), no en anillos ni colgantes
        if ($wearable && $rng->int(0, 3) < 1) {
            $changes['luck'] = 1;
        }

        return $item->with($changes);
    }

    /** @param list<array{int, string}> $tiers */
    private static function tier(array $tiers, int $level): string
    {
        foreach ($tiers as [$max, $table]) {
            if ($level <= $max) {
                return $table;
            }
        }

        return $tiers[array_key_last($tiers)][1];
    }

    /** @return non-empty-list<string> códigos de ítem de una tabla */
    private function table(string $name): array
    {
        if (!isset($this->tables[$name])) {
            $xml = simplexml_load_file($this->dir . '/' . $name . '.xml');
            if ($xml === false) {
                throw new RuntimeException("No se pudo leer la tabla de drop $name.");
            }
            $codes = [];
            foreach ($xml->item as $node) {
                $codes[] = trim((string) $node);
            }
            if ($codes === []) {
                throw new RuntimeException("La tabla de drop $name está vacía.");
            }
            $this->tables[$name] = $codes;
        }

        return $this->tables[$name];
    }
}
