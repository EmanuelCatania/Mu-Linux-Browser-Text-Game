<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Abre la sesión y deja en el pedido la IP del cliente (`ip`) y si la conexión es HTTPS (`secure`). */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Session $session, private readonly bool $trustProxy)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $server = $request->getServerParams();
        $ip = (string) ($server['REMOTE_ADDR'] ?? '0.0.0.0');
        $secure = $request->getUri()->getScheme() === 'https';

        // Estas cabeceras las puede inventar cualquiera: sólo valen si hay un proxy propio delante.
        if ($this->trustProxy) {
            $forwarded = trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);
            if (filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
                $ip = $forwarded;
            }
            $secure = $secure || strtolower($request->getHeaderLine('X-Forwarded-Proto')) === 'https';
        }

        $this->session->start($secure);

        return $handler->handle(
            $request->withAttribute('ip', $ip)->withAttribute('secure', $secure)
        );
    }
}
