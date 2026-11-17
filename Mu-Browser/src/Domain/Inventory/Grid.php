<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

/**
 * Grilla de inventario, baúl o tienda: 8 columnas, celdas numeradas de izquierda a derecha y de arriba abajo.
 * Un ítem ocupa un rectángulo y se identifica por su celda superior izquierda.
 */
final class Grid
{
    public const int COLUMNS = 8;
    public const int ROWS = 8;

    /**
     * Primera celda donde entra un ítem de $width x $height, recorriendo fila por fila, o null si no hay lugar.
     *
     * @param list<array{slot: int, width: int, height: int}> $placed ítems que ya están en la grilla
     */
    public static function findSpace(array $placed, int $width, int $height, int $rows = self::ROWS): ?int
    {
        $taken = self::occupied($placed);

        for ($row = 0; $row + $height <= $rows; $row++) {
            for ($col = 0; $col + $width <= self::COLUMNS; $col++) {
                $slot = $row * self::COLUMNS + $col;
                if (self::isFree($taken, $slot, $width, $height)) {
                    return $slot;
                }
            }
        }

        return null;
    }

    /**
     * ¿Entra un ítem de $width x $height con su esquina en $slot, sin salirse ni pisar a otro?
     *
     * @param list<array{slot: int, width: int, height: int}> $placed
     */
    public static function fits(array $placed, int $slot, int $width, int $height, int $rows = self::ROWS): bool
    {
        if ($slot < 0 || $width < 1 || $height < 1) {
            return false;
        }
        $col = $slot % self::COLUMNS;
        $row = intdiv($slot, self::COLUMNS);
        if ($col + $width > self::COLUMNS || $row + $height > $rows) {
            return false;
        }

        return self::isFree(self::occupied($placed), $slot, $width, $height);
    }

    /** @return list<int> celdas que ocupa un ítem puesto en $slot */
    public static function cells(int $slot, int $width, int $height): array
    {
        $cells = [];
        for ($dy = 0; $dy < $height; $dy++) {
            for ($dx = 0; $dx < $width; $dx++) {
                $cells[] = $slot + $dy * self::COLUMNS + $dx;
            }
        }

        return $cells;
    }

    /** @param array<int, true> $taken */
    private static function isFree(array $taken, int $slot, int $width, int $height): bool
    {
        foreach (self::cells($slot, $width, $height) as $cell) {
            if (isset($taken[$cell])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{slot: int, width: int, height: int}> $placed
     *
     * @return array<int, true> celdas ocupadas
     */
    private static function occupied(array $placed): array
    {
        $taken = [];
        foreach ($placed as $p) {
            foreach (self::cells($p['slot'], $p['width'], $p['height']) as $cell) {
                $taken[$cell] = true;
            }
        }

        return $taken;
    }
}
