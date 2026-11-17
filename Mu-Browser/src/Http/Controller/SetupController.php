<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Account\ValidationException;
use Mu\Domain\Admin\FirstRun;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Primer inicio: crear la cuenta administradora con el código que el contenedor deja en su log. */
final class SetupController
{
    public function __construct(private readonly View $view, private readonly Session $session, private readonly FirstRun $firstRun)
    {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->firstRun->needed()) {
            return $response->withStatus(302)->withHeader('Location', '/');
        }
        $this->firstRun->code(); // por si el contenedor arrancó antes de que existiera la tabla

        return $this->view->render($response, $request, 'setup.twig');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        try {
            $account = $this->firstRun->complete(
                $form('code'),
                $form('username'),
                $form('email'),
                $form('password'),
                $form('password_again'),
                (string) $request->getAttribute('ip'),
                (string) $request->getAttribute('locale'),
            );
        } catch (ValidationException $e) {
            return $this->view->render($response, $request, 'setup.twig', [
                'errors' => $e->errors,
                'old' => ['username' => $form('username'), 'email' => $form('email')],
            ], 422);
        }

        // queda adentro, como administrador
        $this->session->regenerate();
        $this->session->set('account_id', $account->id);
        $this->session->flash('ok', 'setup.done');

        return $response->withStatus(302)->withHeader('Location', '/admin');
    }
}
