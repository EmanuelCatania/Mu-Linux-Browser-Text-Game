<?php

declare(strict_types=1);

namespace Mu\Domain\Skill;

use Mu\Support\Translator;

/** Habilidades del juego, definidas en data/skills.php. */
final class SkillCatalog
{
    /** Grupos en el orden en que se muestran. */
    public const array GROUPS = ['general', 'weapon', 'dk', 'dw', 'elf', 'summoner', 'mg', 'dl'];

    /** @var array<int|string, array<string, mixed>>|null */
    private ?array $skills = null;

    public function __construct(private readonly string $file)
    {
    }

    /**
     * Definiciones por código. Ojo: PHP guarda las claves numéricas ("21") como int,
     * así que al recorrerlo hay que convertir el código a string.
     *
     * @return array<int|string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->skills ??= require $this->file;
    }

    public function has(string $code): bool
    {
        return isset($this->all()[$code]);
    }

    public function name(string $code): string
    {
        return $this->all()[$code]['name'] ?? '?';
    }

    /**
     * Líneas de la ficha de una habilidad, ya traducidas.
     *
     * @return list<array{text: string, color: string}>
     */
    public function describe(string $code, Translator $translator, string $locale): array
    {
        $skill = $this->all()[$code] ?? null;
        if ($skill === null) {
            return [];
        }

        $t = static fn (string $key, array $params = []): string => $translator->translate($key, $locale, $params);
        $lines = [];

        if (isset($skill['life'])) {
            $lines[] = ['text' => $t('skill.life', ['n' => $skill['life']]), 'color' => ''];
        }
        if (isset($skill['damage'])) {
            [$kind, $value] = $skill['damage'];
            $lines[] = ['text' => $t('skill.damage_' . $kind, ['v' => $value]), 'color' => ''];
        }
        foreach ($skill['fx'] ?? [] as [$effect, $value]) {
            $lines[] = ['text' => $t('skill.fx_' . $effect, ['v' => $value]), 'color' => ''];
        }
        if (isset($skill['targets'])) {
            $lines[] = ['text' => $t('skill.targets', ['n' => $skill['targets']]), 'color' => ''];
        }
        if (isset($skill['duration'])) {
            $lines[] = ['text' => $t('skill.duration', ['n' => $skill['duration']]), 'color' => ''];
        }
        $lines[] = ['text' => $t('skill.mana', ['n' => $skill['mana']]), 'color' => ''];
        if (isset($skill['attr'])) {
            $lines[] = ['text' => $t('skill.attr_' . $skill['attr']), 'color' => 'blue'];
        }

        return $lines;
    }
}
