<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Domain\Account\Account;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Idioma del pedido: cookie del selector, si no el guardado en la cuenta, si no español. */
final class LocaleMiddleware implements MiddlewareInterface
{
    public const string COOKIE = 'mu_locale';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $candidates = [
            $request->getCookieParams()[self::COOKIE] ?? null,
            $account instanceof Account ? $account->locale : null,
        ];

        $locale = Translator::DEFAULT_LOCALE;
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && Translator::isSupported($candidate)) {
                $locale = $candidate;
                break;
            }
        }

        return $handler->handle($request->withAttribute('locale', $locale))
            ->withHeader('Content-Language', $locale);
    }
}
