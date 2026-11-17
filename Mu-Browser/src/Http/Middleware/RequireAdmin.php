<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Domain\Account\Account;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Exception\HttpNotFoundException;

/** Filtro de ruta del panel: para quien no es administrador, las páginas no existen. */
final class RequireAdmin implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $account = $request->getAttribute('account');
        if (!$account instanceof Account || !$account->isAdmin) {
            throw new HttpNotFoundException($request);
        }

        return $handler->handle($request);
    }
}
