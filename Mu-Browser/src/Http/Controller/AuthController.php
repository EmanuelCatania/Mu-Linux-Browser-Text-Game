<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\RememberMe;
use Mu\Domain\Account\ValidationException;
use Mu\Http\Cookie;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountService $accounts,
        private readonly RememberMe $rememberMe,
    ) {
    }

    public function showRegister(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'auth/register.twig');
    }

    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = self::form($request);

        try {
            $account = $this->accounts->register(
                $form('username'),
                $form('email'),
                $form('password'),
                $form('password_again'),
                (string) $request->getAttribute('ip'),
                (string) $request->getAttribute('locale'),
            );
        } catch (ValidationException $e) {
            return $this->view->render($response, $request, 'auth/register.twig', [
                'errors' => $e->errors,
                'old' => ['username' => $form('username'), 'email' => $form('email')],
            ], 422);
        }

        $this->session->regenerate();
        $this->session->set('account_id', $account->id);
        $this->session->flash('ok', 'flash.registered');

        return $response->withStatus(302)->withHeader('Location', '/');
    }

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'auth/login.twig');
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = self::form($request);

        try {
            $account = $this->accounts->authenticate(
                $form('username'),
                $form('password'),
                (string) $request->getAttribute('ip'),
            );
        } catch (ValidationException $e) {
            return $this->view->render($response, $request, 'auth/login.twig', [
                'errors' => $e->errors,
                'old' => ['username' => $form('username')],
            ], 422);
        }

        $this->session->regenerate();
        $this->session->set('account_id', $account->id);

        $response = $response->withStatus(302)->withHeader('Location', '/');
        if ($form('remember') === '1') {
            $response = Cookie::set(
                $response,
                RememberMe::COOKIE,
                $this->rememberMe->issue($account),
                RememberMe::LIFETIME_SECONDS,
                (bool) $request->getAttribute('secure'),
            );
        }

        return $response;
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookie = $request->getCookieParams()[RememberMe::COOKIE] ?? null;
        if (is_string($cookie)) {
            $this->rememberMe->forget($cookie);
        }
        $this->session->destroy();

        $response = $response->withStatus(302)->withHeader('Location', '/');

        return Cookie::set($response, RememberMe::COOKIE, '', 0, (bool) $request->getAttribute('secure'));
    }

    /** @return \Closure(string): string lector de campos del formulario, siempre como string */
    public static function form(ServerRequestInterface $request): \Closure
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        return static fn (string $field): string => is_string($body[$field] ?? null) ? $body[$field] : '';
    }
}
