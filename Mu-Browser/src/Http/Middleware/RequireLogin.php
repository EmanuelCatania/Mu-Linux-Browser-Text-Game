<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Filtro de ruta: con $loggedIn = true exige sesión iniciada (si no, va a /login);
 * con false exige ser invitado (si ya hay sesión, va al inicio).
 */
final class RequireLogin implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly bool $loggedIn = true,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $hasAccount = $request->getAttribute('account') !== null;
        if ($hasAccount === $this->loggedIn) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(302)->withHeader('Location', $this->loggedIn ? '/login' : '/');
    }
}
