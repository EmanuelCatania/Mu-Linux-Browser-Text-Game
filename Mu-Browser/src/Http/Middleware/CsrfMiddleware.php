<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Todo pedido que modifica estado debe traer el token de la sesión en el campo `_csrf`. */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly View $view,
        private readonly ResponseFactoryInterface $responses,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->session->csrfToken();
        $request = $request->withAttribute('csrf', $token);

        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $body = $request->getParsedBody();
            $sent = is_array($body) ? ($body['_csrf'] ?? '') : '';
            if (!is_string($sent) || !hash_equals($token, $sent)) {
                return $this->view->render(
                    $this->responses->createResponse(),
                    $request,
                    'error.twig',
                    ['title_key' => 'error.csrf_title', 'message_key' => 'error.csrf'],
                    403,
                );
            }
        }

        return $handler->handle($request);
    }
}
