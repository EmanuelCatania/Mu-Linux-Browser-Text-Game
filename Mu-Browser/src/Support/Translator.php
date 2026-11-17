<?php

declare(strict_types=1);

namespace Mu\Support;

/**
 * Textos de interfaz por idioma. Cada idioma es un archivo lang/<código>.php que devuelve
 * un array clave => texto. Si falta una clave se usa el idioma por defecto y, si tampoco está, la clave.
 */
final class Translator
{
    public const string DEFAULT_LOCALE = 'es';

    /** Idiomas disponibles, en el orden en que se muestran en el selector. */
    public const array LOCALES = ['es' => 'Español', 'en' => 'English', 'pt' => 'Português'];

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    public function __construct(private readonly string $langDir)
    {
    }

    public static function isSupported(string $locale): bool
    {
        return isset(self::LOCALES[$locale]);
    }

    /** @param array<string, string|int> $params valores para los marcadores {nombre} */
    public function translate(string $key, string $locale, array $params = []): string
    {
        $text = $this->catalog($locale)[$key]
            ?? $this->catalog(self::DEFAULT_LOCALE)[$key]
            ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** @return array<string, string> */
    public function catalog(string $locale): array
    {
        if (!self::isSupported($locale)) {
            $locale = self::DEFAULT_LOCALE;
        }

        return $this->catalogs[$locale] ??= $this->load($locale);
    }

    /**
     * lang/<código>.php más, si existe, cada archivo de lang/<código>/ (para no tener un único archivo enorme).
     *
     * @return array<string, string>
     */
    private function load(string $locale): array
    {
        $catalog = require $this->langDir . '/' . $locale . '.php';
        $extra = glob($this->langDir . '/' . $locale . '/*.php') ?: [];
        sort($extra);
        foreach ($extra as $file) {
            $catalog += require $file;
        }

        return $catalog;
    }
}
