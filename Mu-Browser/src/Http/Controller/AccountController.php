<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Account\Account;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\RememberMe;
use Mu\Domain\Account\ValidationException;
use Mu\Http\Cookie;
use Mu\Http\Middleware\LocaleMiddleware;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** "Mi cuenta": email, contraseña e idioma. */
final class AccountController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountService $accounts,
        private readonly RememberMe $rememberMe,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'account.twig');
    }

    public function updateEmail(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        /** @var Account $account */
        $account = $request->getAttribute('account');

        try {
            $this->accounts->changeEmail($account, $form('current_password'), $form('email'));
        } catch (ValidationException $e) {
            return $this->view->render($response, $request, 'account.twig', [
                'errors' => $e->errors,
                'error_form' => 'email',
                'old' => ['email' => $form('email')],
            ], 422);
        }

        $this->session->flash('ok', 'flash.email_changed');

        return $response->withStatus(302)->withHeader('Location', '/account');
    }

    public function updatePassword(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        /** @var Account $account */
        $account = $request->getAttribute('account');

        try {
            $this->accounts->changePassword(
                $account,
                $form('current_password'),
                $form('password'),
                $form('password_again'),
            );
        } catch (ValidationException $e) {
            return $this->view->render($response, $request, 'account.twig', [
                'errors' => $e->errors,
                'error_form' => 'password',
            ], 422);
        }

        // Una contraseña nueva invalida los "recordarme" de otros dispositivos.
        $this->rememberMe->forgetAll($account);
        $this->session->regenerate();
        $this->session->flash('ok', 'flash.password_changed');

        $response = $response->withStatus(302)->withHeader('Location', '/account');

        return Cookie::set($response, RememberMe::COOKIE, '', 0, (bool) $request->getAttribute('secure'));
    }

    /** Selector de idioma: funciona con o sin sesión iniciada. */
    /** Tema claro (el del original) u oscuro. Se guarda en una cookie, también para quien no ingresó. */
    public function switchTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        $back = $form('return');
        if (preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $back) !== 1) {
            $back = '/';
        }
        $theme = $form('theme') === 'dark' ? 'dark' : 'light';

        return Cookie::set($response->withStatus(302)->withHeader('Location', $back), 'mu_theme', $theme, 365 * 24 * 3600, (bool) $request->getAttribute('secure'));
    }

    public function switchLocale(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        $locale = $form('locale');
        $back = $form('return');
        // Sólo rutas internas: nada de "//otro-sitio" ni URLs absolutas.
        if (preg_match('#^/(?!/)[A-Za-z0-9/_\-.]*$#', $back) !== 1) {
            $back = '/';
        }

        $response = $response->withStatus(302)->withHeader('Location', $back);
        if (!Translator::isSupported($locale)) {
            return $response;
        }

        $account = $request->getAttribute('account');
        if ($account instanceof Account) {
            $this->accounts->setLocale($account, $locale);
        }

        return Cookie::set(
            $response,
            LocaleMiddleware::COOKIE,
            $locale,
            365 * 24 * 3600,
            (bool) $request->getAttribute('secure'),
        );
    }
}
