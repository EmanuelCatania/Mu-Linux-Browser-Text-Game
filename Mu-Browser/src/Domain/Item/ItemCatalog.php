<?php

declare(strict_types=1);

namespace Mu\Domain\Item;

use RuntimeException;

/** Ítems base del juego, leídos de data/items/<tipo>.xml (un archivo por tipo de ítem). */
final class ItemCatalog
{
    /** Tipos en el orden en que se muestran. La clave es el primer carácter del código de ítem. */
    public const array TYPES = [
        '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K',
    ];

    /** @var array<string, list<Item>> */
    private array $loaded = [];

    public function __construct(private readonly string $dir)
    {
    }

    public static function isType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @return list<Item> */
    public function ofType(string $type): array
    {
        if (!self::isType($type)) {
            return [];
        }

        return $this->loaded[$type] ??= $this->load($type);
    }

    /** @return list<Item> */
    private function load(string $type): array
    {
        $xml = simplexml_load_file($this->dir . '/' . $type . '.xml');
        if ($xml === false) {
            throw new RuntimeException("No se pudo leer el catálogo de ítems $type.");
        }

        $items = [];
        foreach ($xml->item as $node) {
            $items[] = Item::parse(trim((string) $node));
        }

        return $items;
    }
}
