<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Domain\Admin\FirstRun;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Mientras no haya administrador, todo lleva a la pantalla de primer inicio. */
final class FirstRunMiddleware implements MiddlewareInterface
{
    /** Lo que se puede usar antes de crear el administrador. */
    private const array ALLOWED = ['/setup', '/locale'];

    public function __construct(private readonly FirstRun $firstRun, private readonly ResponseFactoryInterface $responses)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getUri()->getPath(), self::ALLOWED, true) || !$this->firstRun->needed()) {
            return $handler->handle($request);
        }

        return $this->responses->createResponse(302)->withHeader('Location', '/setup');
    }
}
