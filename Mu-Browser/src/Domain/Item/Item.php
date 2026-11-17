<?php

declare(strict_types=1);

namespace Mu\Domain\Item;

use InvalidArgumentException;
use Mu\Domain\Character\CharacterClass;

/**
 * Un ítem, tal como lo codifica el juego original: una cadena de campos de ancho fijo separados por puntos.
 *
 *   1.13.1.0007.00.0.1025.0000.00.0.0.00.00.0.020.020.000000.short_sword
 *   │ │  │ │    │  │ │    │    │  │ │ │  │  │ │   │   │      └ nombre (y archivo de imagen)
 *   │ │  │ │    │  │ │    │    │  │ │ │  │  │ │   │   └ sockets ("00000A" = ítem de nivel 380)
 *   │ │  │ │    │  │ │    │    │  │ │ │  │  │ │   └ durabilidad máxima (en orbes/pergaminos: habilidad que enseña)
 *   │ │  │ │    │  │ │    │    │  │ │ │  │  │ └ durabilidad actual
 *   │ │  │ │    │  │ │    │    │  │ │ │  │  └ guardian
 *   │ │  │ │    │  │ │    │    │  │ │ │  └ harmony (tipo, valor)
 *   │ │  │ │    │  │ │    │    │  │ │ └ opción excellent (1-6; dos dígitos = "ancient")
 *   │ │  │ │    │  │ │    │    │  │ └ opción adicional (0-9)
 *   │ │  │ │    │  │ │    │    │  └ habilidad del arma
 *   │ │  │ │    │  │ │    │    └ clase requerida
 *   │ │  │ │    │  │ │    └ requisito 2 (tipo + 3 dígitos)
 *   │ │  │ │    │  │ └ requisito 1; si lo sigue "!" en vez de "." además pide nivel 380
 *   │ │  │ │    │  └ luck
 *   │ │  │ │    └ nivel (+0 a +15)
 *   │ │  │ └ efecto base (daño, defensa, % de poción…)
 *   │ │  └ subtipo
 *   │ └ tamaño en celdas: ancho, alto
 *   └ tipo (ver ItemType)
 */
final readonly class Item
{
    private const string PATTERN = '/^([0-9A-Z])\.(\d)(\d)\.([0-9A-Z])\.(\d{4})\.(\d{2})\.(\d)\.(\d)(\d{3})([.!])(\d)(\d{3})'
        . '\.(\d{2})\.([0-9A-Z])\.(\d)\.(\d{2})\.(\d)(\d)\.([0-9A-Z])\.(\d{3})\.(\d{3})\.([0-9A-Z]{6})\.([a-z0-9_+]+)$/';

    public const int LEVEL_380 = 380;

    /** Clase requerida → clases que pueden usarlo. 21-24 exigen exactamente esa evolución. */
    private const array CLASS_GROUPS = [
        1 => [1], 2 => [2], 3 => [3], 4 => [4], 5 => [5], 6 => [6], 7 => [7],
        10 => [1, 2], 11 => [1, 3], 12 => [2, 3], 13 => [2, 4], 14 => [2, 5], 15 => [1, 5], 16 => [2, 4, 5], 17 => [1, 5, 6],
        21 => [21], 22 => [22], 23 => [23], 24 => [24],
        31 => [31], 32 => [32], 33 => [33], 34 => [34], 35 => [35], 36 => [36],
    ];

    public function __construct(
        public string $type,
        public int $width,
        public int $height,
        public string $subType,
        public int $effect,
        public int $level,
        public int $luck,
        public int $requirement1Kind,
        public int $requirement1Value,
        public bool $requiresLevel380,
        public int $requirement2Kind,
        public int $requirement2Value,
        public int $requiredClass,
        public string $skill,
        public int $option,
        public int $excellent,
        public int $harmonyType,
        public int $harmonyValue,
        public string $guardian,
        public int $durability,
        public int $durabilityMax,
        public string $socket,
        public string $name,
    ) {
    }

    public static function parse(string $code): self
    {
        if (preg_match(self::PATTERN, $code, $m) !== 1) {
            throw new InvalidArgumentException("Código de ítem inválido: $code");
        }

        return new self(
            type: $m[1],
            width: (int) $m[2],
            height: (int) $m[3],
            subType: $m[4],
            effect: (int) $m[5],
            level: (int) $m[6],
            luck: (int) $m[7],
            requirement1Kind: (int) $m[8],
            requirement1Value: (int) $m[9],
            requiresLevel380: $m[10] === '!',
            requirement2Kind: (int) $m[11],
            requirement2Value: (int) $m[12],
            requiredClass: (int) $m[13],
            skill: $m[14],
            option: (int) $m[15],
            excellent: (int) $m[16],
            harmonyType: (int) $m[17],
            harmonyValue: (int) $m[18],
            guardian: $m[19],
            durability: (int) $m[20],
            durabilityMax: (int) $m[21],
            socket: $m[22],
            name: $m[23],
        );
    }

    /**
     * Copia del ítem con algunos campos cambiados (nivel, opción, durabilidad…).
     *
     * @param array<string, int|string|bool> $changes por nombre de propiedad
     */
    public function with(array $changes): self
    {
        return $changes === [] ? $this : new self(...array_merge(get_object_vars($this), $changes));
    }

    public function toCode(): string
    {
        return sprintf(
            '%s.%d%d.%s.%04d.%02d.%d.%d%03d%s%d%03d.%02d.%s.%d.%02d.%d%d.%s.%03d.%03d.%s.%s',
            $this->type,
            $this->width,
            $this->height,
            $this->subType,
            $this->effect,
            $this->level,
            $this->luck,
            $this->requirement1Kind,
            $this->requirement1Value,
            $this->requiresLevel380 ? '!' : '.',
            $this->requirement2Kind,
            $this->requirement2Value,
            $this->requiredClass,
            $this->skill,
            $this->option,
            $this->excellent,
            $this->harmonyType,
            $this->harmonyValue,
            $this->guardian,
            $this->durability,
            $this->durabilityMax,
            $this->socket,
            $this->name,
        );
    }

    /** "short_sword" con nivel 3 → "Short Sword +3". */
    public function displayName(): string
    {
        $name = ucwords(str_replace('_', ' ', $this->name));

        return $this->level > 0 ? $name . ' +' . $this->level : $name;
    }

    public function imagePath(): string
    {
        return $this->type . '/' . $this->name . '.gif';
    }

    public function isExcellent(): bool
    {
        return $this->excellent > 0;
    }

    /** Dos opciones excellent a la vez (el original las llama "Ancient"). */
    public function isAncient(): bool
    {
        return $this->excellent > 9;
    }

    public function hasSockets(): bool
    {
        return $this->socket !== '000000' && $this->socket !== '00000A';
    }

    /** Valor principal del ítem: efecto base + 3 por nivel + 30 si es excellent. */
    public function power(): int
    {
        return $this->effect + $this->level * 3 + ($this->isExcellent() ? 30 : 0);
    }

    /** @return array{int, int}|null mínimo y máximo, sólo para armas (tipos 1 y 2) */
    public function damageRange(): ?array
    {
        if ($this->type !== '1' && $this->type !== '2') {
            return null;
        }

        return [intdiv($this->power() * 5, 6), $this->power()];
    }

    public function defense(): ?int
    {
        return match ($this->type) {
            '3', '4', '5', '6', '7' => $this->power(),
            '8' => $this->effect + $this->level * ($this->subType === '1' ? 3 : 2),
            '9' => $this->effect + $this->level,
            default => null,
        };
    }

    /**
     * Alas y capas: cuánto aumentan el daño y cuánto absorben, en %. Según el subtipo:
     * 1 = 1.er nivel, 2 = 2.º nivel, 3 = 3.er nivel, 4 = Cape of Lord, 5 = Cape of Emperor.
     *
     * @return array{int, int}
     */
    public function wingRates(): array
    {
        return match ($this->subType) {
            '1' => [12 + $this->level * 2, 12 + $this->level * 2],
            '3' => [39 + $this->level * 2, 39 + $this->level * 2],
            '4' => [20 + $this->level * 2, 10 + $this->level * 2],
            '5' => [39 + $this->level * 2, 24 + $this->level * 2],
            default => [32 + $this->level, 25 + $this->level],
        };
    }

    /** Sólo escudos. */
    public function defenseRate(): ?int
    {
        return $this->type === '9'
            ? $this->effect * 3 + $this->level * 3 + ($this->isExcellent() ? 30 : 0)
            : null;
    }

    /**
     * Requisitos para usarlo. Cada nivel suma 5 y ser excellent suma 30.
     *
     * @return list<array{kind: Requirement, value: int}>
     */
    public function requirements(): array
    {
        $extra = $this->level * 5 + ($this->isExcellent() ? 30 : 0);
        $out = [];
        foreach ([[$this->requirement1Kind, $this->requirement1Value], [$this->requirement2Kind, $this->requirement2Value]] as [$kind, $value]) {
            $requirement = Requirement::tryFrom($kind);
            if ($requirement !== null) {
                $out[] = ['kind' => $requirement, 'value' => $value + $extra];
            }
        }
        if ($this->requiresLevel380) {
            $out[] = ['kind' => Requirement::Level, 'value' => self::LEVEL_380];
        }

        return $out;
    }

    /** @return list<CharacterClass> vacío = cualquier clase */
    public function allowedClasses(): array
    {
        return array_map(CharacterClass::from(...), self::CLASS_GROUPS[$this->requiredClass] ?? []);
    }

    public function canBeUsedBy(CharacterClass $class): bool
    {
        $allowed = $this->allowedClasses();
        if ($allowed === []) {
            return true;
        }

        // Un ítem de Dark Knight también sirve a un Blade Knight y a un Blade Master; uno de Blade Knight no le sirve a un Dark Knight.
        return array_any($class->lineage(), static fn (CharacterClass $c): bool => in_array($c, $allowed, true));
    }

    /** Precio de compra en tiendas NPC. */
    public function buyPrice(): int
    {
        $base = match (true) {
            $this->type === '8' => $this->effect * 150,
            $this->type === 'C', $this->type === 'J' => 500,
            $this->type === 'D', $this->type === 'E' => 1000,
            $this->type === 'F' => $this->effect * 2,
            default => 50 + $this->effect * 2,
        };

        return $base * (1 + $this->level + $this->luck + $this->option + ($this->isExcellent() ? 10 : 0));
    }

    /** Las tiendas compran a la mitad. */
    public function sellPrice(): int
    {
        return intdiv($this->buyPrice(), 2);
    }
}
