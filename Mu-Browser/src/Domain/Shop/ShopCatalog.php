<?php

declare(strict_types=1);

namespace Mu\Domain\Shop;

use Mu\Domain\Item\Item;
use RuntimeException;

/**
 * Tiendas NPC, leídas de data/shops/<id>.xml. Cada una es una grilla de 8x15 con ítems en posiciones fijas.
 *
 * Los XML los arma tools/shops.py: cada NPC vende un rubro, y sólo lo básico y de nivel bajo;
 * lo demás se consigue cazando.
 */
final class ShopCatalog
{
    public const int ROWS = 15;

    /** Tiendas en el orden del menú, con el NPC que las atiende. */
    public const array SHOPS = [
        'lorencia1' => 'Lumen the Barmaid',
        'lorencia2' => 'Hanzo the Blacksmith',
        'lorencia3' => 'Pasi the Mage',
        'lorencia4' => 'Harold the Wandering Merchant',
        'noria1' => 'Elf Lala',
        'noria2' => 'Eo the Craftsman',
        'elbeland1' => 'Leah',
        'elbeland2' => 'Marseille',
        'devias1' => 'Isabel the Wizard',
        'devias2' => 'Zienna the Weapon Merchant',
        'devias3' => 'Alex the Wandering Merchant',
        'devias4' => 'Thompson Kenel',
    ];

    /** @var array<string, array<int, Item>> */
    private array $loaded = [];

    /**
     * @param string $quality 'normal' vende los ítems sin los valores trucados que traen los datos de Classic-2011
     *                        (luck 9, excellent 09…); 'table' los vende tal cual figuran en el XML
     */
    public function __construct(private readonly string $dir, private readonly string $quality = 'normal')
    {
    }

    public static function exists(string $id): bool
    {
        return isset(self::SHOPS[$id]);
    }

    /** @return array<int, Item> ítems en venta, por celda */
    public function stock(string $id): array
    {
        if (!self::exists($id)) {
            return [];
        }

        return $this->loaded[$id] ??= $this->load($id);
    }

    /** @return array<int, Item> */
    private function load(string $id): array
    {
        $xml = simplexml_load_file($this->dir . '/' . $id . '.xml');
        if ($xml === false) {
            throw new RuntimeException("No se pudo leer la tienda $id.");
        }

        $stock = [];
        foreach ($xml->item as $node) {
            $code = trim((string) $node);
            // "0" = celda libre, "1" = celda tapada por un ítem más grande.
            // Las entradas sin id son datos sueltos del final del archivo (nombre de la tienda y del NPC).
            if ($code === '0' || $code === '1' || !isset($node['id'])) {
                continue;
            }
            $item = Item::parse($code);
            if ($this->quality === 'normal') {
                $item = $item->with([
                    'luck' => min(1, $item->luck === 9 ? 0 : $item->luck),
                    'excellent' => $item->excellent > 6 ? 0 : $item->excellent,
                    'harmonyType' => $item->harmonyType > 6 ? 0 : $item->harmonyType,
                    'harmonyValue' => $item->harmonyType > 6 ? 0 : $item->harmonyValue,
                    'guardian' => '0',
                ]);
            }
            $stock[(int) $node['id']] = $item;
        }

        return $stock;
    }
}
