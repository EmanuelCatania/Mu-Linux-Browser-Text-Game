<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Mu\Data\GoldenRepository;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\Item;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\MapCatalog;
use Mu\Tests\Support\FakeRng;
use Mu\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;

/** Mapas, drops, Golden Invasion y grilla de inventario, contra los datos reales de data/. */
final class WorldTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private static function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    /** @param array<string, mixed> $overrides */
    private function drops(array $overrides = []): DropGenerator
    {
        $config = require $this->root . '/data/game.php';

        return new DropGenerator($this->root . '/data/drop', $overrides + $config['drop']);
    }

    public function testEveryMapLoadsWithImagesForSpotsAndMonsters(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');

        self::assertSame(array_keys(MapCatalog::MAPS), array_keys($maps->all()));
        // Los mapas agregados después del original usan imágenes de reserva hasta tener arte propio.
        $original = ['lorencia', 'noria', 'elbeland', 'dungeon', 'devias', 'lost_tower', 'atlans', 'tarkan'];
        foreach (['maps/zone_blank.jpg', 'maps/spot_blank.jpg', 'monsters/unknown.gif'] as $fallback) {
            self::assertFileExists($this->root . "/public/assets/img/$fallback");
        }
        self::assertCount(20, $maps->all());
        foreach ($maps->all() as $id => $map) {
            if (!in_array($id, $original, true)) {
                self::assertNotEmpty($map->spots);
                foreach ($map->spots as $spot) {
                    self::assertNotEmpty($spot->encounters, "$id/{$spot->id} sin monstruos");
                    self::assertLessThanOrEqual(MapCatalog::IMAGE_SIZE, max($spot->x, $spot->y));
                }
                continue;
            }
            self::assertFileExists($this->root . "/public/assets/img/maps/$id.jpg");
            self::assertNotEmpty($map->spots);
            foreach ($map->spots as $spot) {
                self::assertNotEmpty($spot->encounters, "$id/{$spot->id} sin monstruos");
                self::assertFileExists($this->root . "/public/assets/img/maps/{$spot->id}.jpg");
                self::assertLessThanOrEqual(MapCatalog::IMAGE_SIZE, max($spot->x, $spot->y));
                foreach ([...$spot->encounters, ...($spot->boss === null ? [] : [$spot->boss])] as $name) {
                    self::assertNotNull($map->monster($name));
                    self::assertFileExists($this->root . "/public/assets/img/monsters/$name.gif");
                }
            }
        }
    }

    public function testLorenciaData(): void
    {
        $lorencia = new MapCatalog($this->root . '/data/maps')->get('lorencia');

        self::assertNotNull($lorencia);
        self::assertCount(6, $lorencia->spots);
        $spider = $lorencia->monster('spider');
        self::assertSame([2, 3, 7, 1, 8, 1, null], [
            $spider?->level, $spider?->hp, $spider?->damage, $spider?->defense, $spider?->damageRate, $spider?->defenseRate, $spider?->attribute,
        ]);
        self::assertSame([2, 2], $lorencia->levelRange($lorencia->spot('spot1')));
        self::assertSame(1, $lorencia->spot('spot1')?->number());
    }

    public function testUnknownMapsAreRejected(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');

        self::assertNull($maps->get('nowhere'));
        self::assertNull($maps->get('../drop/regular1'));
        self::assertNull($maps->get('lorencia')?->spot('spot99'));
    }

    public function testElementNamesAreNormalised(): void
    {
        $attributes = [];
        foreach (new MapCatalog($this->root . '/data/maps')->all() as $map) {
            foreach ($map->monsters as $monster) {
                $attributes[$monster->attribute ?? 'none'] = true;
            }
        }

        self::assertArrayNotHasKey('lighting', $attributes);
        self::assertSame([], array_diff(array_keys($attributes), ['none', 'wind', 'fire', 'ice', 'lightning', 'water', 'earth', 'poison']));
    }

    public function testEveryDropTableHasValidItemsWithImages(): void
    {
        foreach (glob($this->root . '/data/drop/*.xml') ?: [] as $file) {
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml);
            self::assertGreaterThan(0, count($xml->item), basename($file) . ' vacía');
            foreach ($xml->item as $node) {
                $item = Item::parse(trim((string) $node));
                self::assertFileExists($this->root . '/public/assets/img/items/' . $item->imagePath(), basename($file));
            }
        }
    }

    public function testMostKillsDropNothing(): void
    {
        self::assertNull($this->drops()->drop('spider', 2, new FakeRng([], 'max'), self::at('2026-01-05 12:00:00')));
    }

    public function testRegularDropIsNormalByDefaultAndGetsRandomBonuses(): void
    {
        // tiradas: común sí (0), índice 0, excellent no (100), nivel +1 (0), opción 1 (0), habilidad sí (0), luck sí (0)
        $item = $this->drops()->drop('spider', 2, new FakeRng([0, 0, 100, 0, 0, 0, 0]), self::at('2026-01-05 12:00:00'));

        self::assertNotNull($item);
        self::assertSame('small_axe', $item->name);
        self::assertSame([0, 1, 1, '7', 1], [$item->excellent, $item->level, $item->option, $item->skill, $item->luck]);
    }

    public function testRegularDropCanRollARealExcellentOption(): void
    {
        // excellent sí (1) con la opción 4; después nada más
        $item = $this->drops()->drop('spider', 2, new FakeRng([0, 0, 1, 4], 'max'), self::at('2026-01-05 12:00:00'));

        self::assertSame(4, $item?->excellent);
        self::assertSame(0, $item?->level);
    }

    public function testTableQualityKeepsTheItemsAsClassicHadThem(): void
    {
        // Con 'table' no se sortea la calidad: la tabla de Classic-2011 marca todo como excellent.
        $item = $this->drops(['regular_quality' => 'table'])
            ->drop('spider', 2, new FakeRng([0, 0], 'max'), self::at('2026-01-05 12:00:00'));

        self::assertTrue($item?->isExcellent());
    }

    public function testHigherLevelMonstersUseHigherTables(): void
    {
        $low = $this->drops()->drop('x', 5, new FakeRng([0, 0], 'max'), self::at('2026-01-05 12:00:00'));
        $high = $this->drops()->drop('x', 95, new FakeRng([0, 0], 'max'), self::at('2026-01-05 12:00:00'));

        self::assertNotSame($low?->name, $high?->name);
        self::assertGreaterThan($low?->effect, $high?->effect);
    }

    public function testGoldenMonstersAlwaysDropTheirBox(): void
    {
        $now = self::at('2026-01-05 12:00:00');

        self::assertSame('box_of_luck', $this->drops()->drop('golden_budge_dragon', 4, new FakeRng(), $now)?->name);
        $box = $this->drops()->drop('golden_devil', 60, new FakeRng(), $now);
        self::assertSame(['box_of_kundun', 2], [$box?->name, $box?->level]);
    }

    public function testSundayBox(): void
    {
        $sunday = self::at('2026-01-04 12:00:00');
        self::assertSame('Sun', $sunday->format('D'));

        self::assertSame('cherry_blossom_box', $this->drops()->drop('spider', 2, new FakeRng([0]), $sunday)?->name);
        self::assertNull($this->drops()->drop('spider', 2, new FakeRng([], 'max'), $sunday));
    }

    public function testGoldenInvasionSchedule(): void
    {
        $golden = new GoldenInvasion(new GoldenRepository(TestDatabase::create()), [0, 8, 16]);

        self::assertTrue($golden->isActive(self::at('2026-01-05 08:30:00')));
        self::assertFalse($golden->isActive(self::at('2026-01-05 09:00:00')));
        self::assertSame(1800, $golden->secondsToChange(self::at('2026-01-05 08:30:00')));
        self::assertSame(7 * 3600, $golden->secondsToChange(self::at('2026-01-05 09:00:00')));
        self::assertSame(3600 + 60, $golden->secondsToChange(self::at('2026-01-05 22:59:00')), 'la próxima es a las 0 del día siguiente');
    }

    public function testEachGoldenMonsterAppearsOncePerInvasion(): void
    {
        $golden = new GoldenInvasion(new GoldenRepository(TestDatabase::create()), [0, 8, 16]);
        $now = self::at('2026-01-05 08:10:00');

        self::assertNull($golden->availableIn('lorencia', self::at('2026-01-05 07:59:00')), 'fuera de horario no hay');
        self::assertNull($golden->availableIn('karutan', $now), 'Karutan no tiene dorado');
        self::assertSame('golden_budge_dragon', $golden->availableIn('lorencia', $now));

        self::assertTrue($golden->claim('golden_budge_dragon', 'Arthur', $now));
        self::assertFalse($golden->claim('golden_budge_dragon', 'Merlin', $now->modify('+5 seconds')), 'el segundo llega tarde');
        self::assertNull($golden->availableIn('lorencia', $now->modify('+20 minutes')));

        self::assertSame('golden_budge_dragon', $golden->availableIn('lorencia', self::at('2026-01-05 16:01:00')), 'vuelve en la invasión siguiente');
    }

    public function testGoldenStatusShowsWhereTheyAreAndWhoFoundThem(): void
    {
        $golden = new GoldenInvasion(new GoldenRepository(TestDatabase::create()), [0, 8, 16]);
        $now = self::at('2026-01-05 08:10:00');
        $golden->claim('golden_budge_dragon', 'Arthur', $now);

        $during = array_column($golden->status($now), null, 'monster');
        self::assertSame(['map' => null, 'by' => 'Arthur'], array_diff_key($during['golden_budge_dragon'], ['monster' => 1]));
        self::assertSame('noria', $during['golden_goblin']['map']);

        $after = array_column($golden->status(self::at('2026-01-05 10:00:00')), null, 'monster');
        self::assertSame('Arthur', $after['golden_budge_dragon']['by']);
        self::assertNull($after['golden_goblin']['map']);

        $muchLater = array_column($golden->status(self::at('2026-01-05 18:30:00')), null, 'monster');
        self::assertNull($muchLater['golden_budge_dragon']['by'], 'pasadas 9 horas ya no se muestra');
    }

    public function testEveryGoldenMonsterHasDataInItsMap(): void
    {
        $maps = new MapCatalog($this->root . '/data/maps');
        foreach (new GoldenRepository(TestDatabase::create())->all() as $spawn) {
            self::assertNotNull($maps->get($spawn['map'])?->monster($spawn['monster']), $spawn['monster']);
        }
    }

    public function testGridFindsTheFirstFreeRectangle(): void
    {
        self::assertSame(0, Grid::findSpace([], 2, 4));

        $placed = [['slot' => 0, 'width' => 2, 'height' => 2]];
        self::assertSame(2, Grid::findSpace($placed, 1, 1));
        self::assertSame(2, Grid::findSpace($placed, 2, 3));

        // fila 0 ocupada salvo la última columna: un ítem de 2 de ancho no puede "doblar" a la fila siguiente
        $row = [['slot' => 0, 'width' => 7, 'height' => 1]];
        self::assertSame(7, Grid::findSpace($row, 1, 1));
        self::assertSame(8, Grid::findSpace($row, 2, 1));
    }

    public function testGridReportsNoSpace(): void
    {
        $full = [['slot' => 0, 'width' => 8, 'height' => 7]];

        self::assertSame(56, Grid::findSpace($full, 8, 1));
        self::assertNull(Grid::findSpace($full, 1, 2));
        self::assertNull(Grid::findSpace([['slot' => 0, 'width' => 8, 'height' => 8]], 1, 1));
        self::assertSame([9, 10, 17, 18], Grid::cells(9, 2, 2));
    }
}
