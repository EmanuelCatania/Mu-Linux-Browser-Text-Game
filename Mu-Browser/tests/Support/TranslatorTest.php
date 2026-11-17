<?php

declare(strict_types=1);

namespace Mu\Tests\Support;

use Mu\Support\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private Translator $translator;

    protected function setUp(): void
    {
        $this->translator = new Translator(dirname(__DIR__, 2) . '/lang');
    }

    public function testTranslatesPerLocale(): void
    {
        self::assertSame('Ingresar', $this->translator->translate('nav.login', 'es'));
        self::assertSame('Login', $this->translator->translate('nav.login', 'en'));
        self::assertSame('Entrar', $this->translator->translate('nav.login', 'pt'));
    }

    public function testReplacesParams(): void
    {
        self::assertSame(
            'Sesión iniciada como Tester.',
            $this->translator->translate('home.cta_member', 'es', ['name' => 'Tester']),
        );
    }

    public function testUnknownLocaleFallsBackToSpanish(): void
    {
        self::assertSame('Ingresar', $this->translator->translate('nav.login', 'xx'));
    }

    public function testUnknownKeyReturnsTheKey(): void
    {
        self::assertSame('no.such.key', $this->translator->translate('no.such.key', 'en'));
    }

    /** @return iterable<string, array{string}> */
    public static function otherLocales(): iterable
    {
        foreach (array_keys(Translator::LOCALES) as $locale) {
            if ($locale !== Translator::DEFAULT_LOCALE) {
                yield $locale => [$locale];
            }
        }
    }

    #[DataProvider('otherLocales')]
    public function testEveryLocaleHasExactlyTheSpanishKeys(string $locale): void
    {
        $base = array_keys($this->translator->catalog(Translator::DEFAULT_LOCALE));
        $other = array_keys($this->translator->catalog($locale));

        self::assertSame([], array_values(array_diff($base, $other)), "Faltan claves en $locale");
        self::assertSame([], array_values(array_diff($other, $base)), "Sobran claves en $locale");
    }
}
