<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\NotificationRepository;
use Mu\Domain\Account\Account;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** La campana de avisos: la lista, el contador para refrescarla y el borrado de lo leído. */
final class NotificationController
{
    public function __construct(private readonly View $view, private readonly NotificationRepository $notifications)
    {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $account = self::account($request);
        $list = $this->notifications->latest($account->id);
        // al abrir la lista quedan leídas; las nuevas se siguen resaltando en esta vista
        $this->notifications->markAllRead($account->id);

        return $this->view->render($response, $request->withAttribute('unread', 0), 'notifications.twig', ['list' => $list]);
    }

    /** Contador en JSON, para actualizar la campana sin recargar la página. */
    public function count(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(['unread' => $this->notifications->unread(self::account($request)->id)]));

        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    public function clear(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->notifications->clear(self::account($request)->id);

        return $response->withStatus(302)->withHeader('Location', '/notifications');
    }

    private static function account(ServerRequestInterface $request): Account
    {
        $account = $request->getAttribute('account');
        assert($account instanceof Account);

        return $account;
    }
}
