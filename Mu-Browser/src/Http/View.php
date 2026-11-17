<?php

declare(strict_types=1);

namespace Mu\Http;

use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/** Renderiza plantillas Twig con el contexto común (cuenta, idioma, token CSRF, mensaje flash). */
final class View
{
    private readonly Environment $twig;

    /** Anuncios de la barra superior; null mientras no se configure (tests de plantillas sueltas). */
    private ?\Closure $ticker = null;

    public function __construct(
        string $templateDir,
        private readonly Translator $translator,
        private readonly Session $session,
        string|false $cacheDir = false,
        ?string $publicDir = null,
    ) {
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => $cacheDir,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);

        // t('clave', {param: valor}) usa el idioma del pedido en curso.
        $this->twig->addFunction(new TwigFunction(
            't',
            fn (array $context, string $key, array $params = []): string
                => $this->translator->translate($key, $context['locale'], $params),
            ['needs_context' => true],
        ));

        // asset('/assets/css/app.css') agrega ?v=<fecha de modificación>, para que un despliegue nuevo
        // no quede tapado por la caché del navegador (los assets se sirven con max-age de un día).
        $this->twig->addFunction(new TwigFunction('asset', static function (string $path) use ($publicDir): string {
            $mtime = $publicDir === null ? false : @filemtime($publicDir . $path);

            return $mtime === false ? $path : $path . '?v=' . $mtime;
        }));

        // img('/assets/img/monsters/x.gif', '/assets/img/monsters/unknown.gif') devuelve la primera si el archivo
        // existe y si no la de reserva: los mapas agregados después del original todavía no tienen arte propio.
        $this->twig->addFunction(new TwigFunction('img', static function (string $path, string $fallback) use ($publicDir): string {
            return $publicDir === null || is_file($publicDir . $path) ? $path : $fallback;
        }));
    }

    /** Zona horaria en que se muestran las fechas (filtro date de Twig). */
    public function setTimezone(string $timezone): void
    {
        $this->twig->getExtension(\Twig\Extension\CoreExtension::class)->setTimezone($timezone);
    }

    /** @param \Closure(): list<array<string, mixed>> $ticker */
    public function setTicker(\Closure $ticker): void
    {
        $this->ticker = $ticker;
    }

    /** @param array<string, mixed> $data */
    public function render(
        ResponseInterface $response,
        ServerRequestInterface $request,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $context = $data + [
            'account' => $request->getAttribute('account'),
            'character' => $request->getAttribute('character'),
            'online' => $request->getAttribute('online'),
            'duel_alert' => (bool) $request->getAttribute('duel_alert', false),
            'fighting' => (bool) $request->getAttribute('in_battle', false),
            'unread' => (int) $request->getAttribute('unread', 0),
            'theme' => ($request->getCookieParams()['mu_theme'] ?? '') === 'dark' ? 'dark' : 'light',
            'locale' => $request->getAttribute('locale', Translator::DEFAULT_LOCALE),
            'locales' => Translator::LOCALES,
            'csrf' => $request->getAttribute('csrf', ''),
            'path' => $request->getUri()->getPath(),
            'flash' => session_status() === PHP_SESSION_ACTIVE ? $this->session->pullFlash() : null,
            'ticker' => $this->ticker === null ? [] : ($this->ticker)(),
            'errors' => [],
            'old' => [],
        ];

        $response->getBody()->write($this->twig->render($template, $context));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
