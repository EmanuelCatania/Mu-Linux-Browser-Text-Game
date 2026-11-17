<?php

declare(strict_types=1);

namespace Mu\Domain\Admin;

use DateTimeImmutable;
use Mu\Support\Clock;
use PDO;

/**
 * Valores de data/game.php que se pueden cambiar desde el panel de administración.
 *
 * El archivo sigue siendo la fuente de los valores por defecto; lo que se cambia en el panel se guarda en la tabla
 * `settings` (ruta con puntos → JSON) y se aplica encima en cada pedido. "Volver al original" borra el cambio.
 */
final class GameSettings
{
    /**
     * Ruta → [grupo, tipo, mínimo, máximo]. Tipos: int, bool, hours (lista de horas 0-23),
     * range (dos números: desde-hasta), list3 (tres números).
     */
    public const array FIELDS = [
        'spot_capacity' => ['battle', 'int', 1, 100],
        'enemies_min' => ['battle', 'int', 1, 5],
        'enemies_max' => ['battle', 'int', 1, 5],
        'exp_hp_divisor' => ['battle', 'int', 0, 100000],
        'boss_chance' => ['battle', 'int', 0, 100],
        'defeat_hp_percent' => ['battle', 'int', 0, 100],

        'drop.regular_one_in' => ['drop', 'int', 1, 100000],
        'drop.skills_one_in' => ['drop', 'int', 1, 100000],
        'drop.parts_one_in' => ['drop', 'int', 1, 100000],
        'drop.rings_one_in' => ['drop', 'int', 1, 100000],
        'drop.jewels_one_in' => ['drop', 'int', 1, 100000],
        'drop.sunday_box_one_in' => ['drop', 'int', 1, 100000],
        'drop.excellent_chance' => ['drop', 'int', 0, 100],

        'golden.hours' => ['events', 'hours', 0, 23],
        'golden.chance' => ['events', 'int', 0, 100],
        'events.devil_square.hours' => ['events', 'hours', 0, 23],
        'events.devil_square.minutes' => ['events', 'int', 1, 59],
        'events.blood_castle.hours' => ['events', 'hours', 0, 23],
        'events.blood_castle.minutes' => ['events', 'int', 1, 59],
        'event_rewards.devil_square_exp' => ['events', 'int', 100, 1000],
        'event_rewards.blood_castle_zen_per_level' => ['events', 'int', 0, 10000000],
        'world_boss.chance' => ['events', 'int', 0, 100],
        'world_boss.respawn_seconds' => ['events', 'int', 60, 604800],

        'party.share_percent' => ['community', 'int', 0, 100],
        'party.max' => ['community', 'int', 2, 10],
        'pvp.expire_seconds' => ['community', 'int', 60, 86400],
        'pvp.max_rounds' => ['community', 'int', 5, 200],
        'pvp.max_zen' => ['community', 'int', 0, 2000000000],

        'reset.enabled' => ['quests', 'bool', 0, 1],
        'reset.zen' => ['quests', 'int', 0, 2000000000],
        'evolution.level' => ['quests', 'int', 1, 400],
        'evolution.scroll_zen' => ['quests', 'int', 0, 2000000000],
        'evolution.scroll_levels' => ['quests', 'range', 1, 400],
        'evolution.treasure_zen' => ['quests', 'int', 0, 2000000000],
        'evolution.treasure_levels' => ['quests', 'range', 1, 400],
        'evolution.points' => ['quests', 'int', 0, 1000],
        'evolution.marlon_level' => ['quests', 'int', 1, 400],
        'evolution.ring_zen' => ['quests', 'int', 0, 2000000000],
        'evolution.stone_zen' => ['quests', 'int', 0, 2000000000],
        'evolution.marlon_levels' => ['quests', 'range', 1, 400],
        'evolution.devin_level' => ['quests', 'int', 1, 400],
        'evolution.devin_zen' => ['quests', 'list3', 0, 2000000000],
        'evolution.devin_points' => ['quests', 'list3', 0, 1000],
        'evolution.drop_one_in' => ['quests', 'int', 1, 100000],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Configuración con los cambios del panel aplicados encima.
     *
     * @param array<string, mixed> $game lo que devuelve data/game.php
     *
     * @return array<string, mixed>
     */
    public function apply(array $game): array
    {
        foreach ($this->overrides() as $path => $value) {
            if (isset(self::FIELDS[$path])) {
                self::set($game, $path, $value);
            }
        }

        return $game;
    }

    /** @return array<string, mixed> ruta → valor cambiado */
    public function overrides(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR) as $name => $json) {
            if (isset(self::FIELDS[(string) $name])) {
                $out[(string) $name] = json_decode((string) $json, true);
            }
        }

        return $out;
    }

    /**
     * Valida y guarda lo que llegó del formulario. Lo que no cambia respecto del archivo no se guarda.
     *
     * @param array<string, mixed> $defaults lo que devuelve data/game.php
     * @param array<string, string> $form ruta → texto escrito (las casillas sin marcar no llegan)
     *
     * @return list<string> rutas con valores inválidos (no se guardó nada si hay alguna)
     */
    public function save(array $defaults, array $form, string $group, DateTimeImmutable $now): array
    {
        $values = [];
        $invalid = [];
        foreach (self::FIELDS as $path => [$fieldGroup, $type, $min, $max]) {
            if ($fieldGroup !== $group) {
                continue;
            }
            $value = self::parse($type, $form[$path] ?? null, $min, $max);
            if ($value === null) {
                $invalid[] = $path;
            } else {
                $values[$path] = $value;
            }
        }
        if (($values['enemies_min'] ?? 0) > ($values['enemies_max'] ?? 5)) {
            $invalid[] = 'enemies_min';
        }
        if ($invalid !== []) {
            return $invalid;
        }

        $delete = $this->pdo->prepare('DELETE FROM settings WHERE name = ?');
        $insert = $this->pdo->prepare('INSERT INTO settings (name, value, updated_at) VALUES (?, ?, ?)');
        foreach ($values as $path => $value) {
            $delete->execute([$path]);
            if ($value !== self::get($defaults, $path)) {
                $insert->execute([$path, json_encode($value, JSON_THROW_ON_ERROR), $now->format(Clock::SQL_FORMAT)]);
            }
        }

        return [];
    }

    /** Vuelve un grupo a los valores del archivo. */
    public function reset(string $group): void
    {
        $delete = $this->pdo->prepare('DELETE FROM settings WHERE name = ?');
        foreach (self::FIELDS as $path => [$fieldGroup]) {
            if ($fieldGroup === $group) {
                $delete->execute([$path]);
            }
        }
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return array_values(array_unique(array_column(self::FIELDS, 0)));
    }

    /**
     * Para el formulario: cada campo con su valor actual en texto y si está cambiado.
     *
     * @param array<string, mixed> $defaults
     *
     * @return list<array{path: string, key: string, type: string, value: string, default: string, changed: bool, min: int, max: int}>
     */
    public function fields(array $defaults, string $group): array
    {
        $overrides = $this->overrides();
        $out = [];
        foreach (self::FIELDS as $path => [$fieldGroup, $type, $min, $max]) {
            if ($fieldGroup !== $group) {
                continue;
            }
            $default = self::get($defaults, $path);
            $current = array_key_exists($path, $overrides) ? $overrides[$path] : $default;
            $out[] = [
                'path' => $path,
                'key' => 'admin.set_' . str_replace('.', '_', $path),
                'type' => $type,
                'value' => self::format($type, $current),
                'default' => self::format($type, $default),
                'changed' => array_key_exists($path, $overrides),
                'min' => $min,
                'max' => $max,
            ];
        }

        return $out;
    }

    private static function parse(string $type, mixed $text, int $min, int $max): int|bool|array|null
    {
        if ($type === 'bool') {
            return $text !== null && $text !== '' && $text !== '0';
        }
        if (!is_string($text)) {
            return null;
        }
        $parts = array_values(array_filter(array_map('trim', preg_split('/[,;\s-]+/', trim($text)) ?: []), static fn (string $p): bool => $p !== ''));
        foreach ($parts as $part) {
            if (!ctype_digit($part) || strlen($part) > 10 || (int) $part < $min || (int) $part > $max) {
                return null;
            }
        }
        $numbers = array_map(intval(...), $parts);

        return match ($type) {
            'int' => count($numbers) === 1 ? $numbers[0] : null,
            'range' => count($numbers) === 2 && $numbers[0] <= $numbers[1] ? $numbers : null,
            'list3' => count($numbers) === 3 ? $numbers : null,
            'hours' => $numbers === [] ? null : array_values(array_unique($numbers)),
            default => null,
        };
    }

    private static function format(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'range' => implode(' - ', (array) $value),
            'hours', 'list3' => implode(', ', (array) $value),
            default => (string) $value,
        };
    }

    /** @param array<string, mixed> $config */
    private static function get(array $config, string $path): mixed
    {
        foreach (explode('.', $path) as $key) {
            if (!is_array($config) || !array_key_exists($key, $config)) {
                return null;
            }
            $config = $config[$key];
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    private static function set(array &$config, string $path, mixed $value): void
    {
        $node = &$config;
        foreach (explode('.', $path) as $key) {
            $node[$key] ??= [];
            $node = &$node[$key];
        }
        $node = $value;
    }
}
