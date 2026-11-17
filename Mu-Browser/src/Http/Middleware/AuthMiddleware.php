<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Data\AccountRepository;
use Mu\Domain\Account\RememberMe;
use Mu\Http\Cookie;
use Mu\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Resuelve la cuenta logueada (sesión o cookie "recordarme") y la deja en el atributo `account`. */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly RememberMe $rememberMe,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $account = null;
        $newCookie = null;
        $clearCookie = false;

        $id = $this->session->get('account_id');
        if (is_int($id)) {
            $account = $this->accounts->findById($id);
            if ($account === null || $account->banned) {
                $account = null;
                $this->session->remove('account_id');
            }
        }

        $cookie = $request->getCookieParams()[RememberMe::COOKIE] ?? null;
        if ($account === null && is_string($cookie)) {
            $result = $this->rememberMe->consume($cookie);
            if ($result === null) {
                $clearCookie = true;
            } else {
                [$account, $newCookie] = $result;
                if ($account->banned) {
                    $account = null;
                    $newCookie = null;
                    $clearCookie = true;
                } else {
                    $this->session->regenerate();
                    $this->session->set('account_id', $account->id);
                }
            }
        }

        $response = $handler->handle($request->withAttribute('account', $account));
        $secure = (bool) $request->getAttribute('secure', false);

        // Si el handler ya tocó esa cookie (login, logout) no se pisa.
        if (str_contains($response->getHeaderLine('Set-Cookie'), RememberMe::COOKIE . '=')) {
            return $response;
        }
        if ($newCookie !== null) {
            return Cookie::set($response, RememberMe::COOKIE, $newCookie, RememberMe::LIFETIME_SECONDS, $secure);
        }
        if ($clearCookie) {
            return Cookie::set($response, RememberMe::COOKIE, '', 0, $secure);
        }

        return $response;
    }
}
