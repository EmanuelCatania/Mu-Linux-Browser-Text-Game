<?php

declare(strict_types=1);

namespace Mu\Http\Middleware;

use Mu\Domain\Account\Account;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\NotificationRepository;
use Mu\Data\DuelRepository;
use Mu\Domain\Character\CharacterService;
use Mu\Http\Controller\CommunityController;
use Mu\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deja en el atributo `character` el personaje activo de la cuenta logueada (o null), lo marca como
 * conectado, deja en `online` cuántos personajes hay conectados y en `duel_alert` si lo están desafiando.
 */
final class CharacterMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CharacterService $characters,
        private readonly CharacterRepository $repository,
        private readonly Clock $clock,
        private readonly ?DuelRepository $duels = null,
        private readonly ?BattleRepository $battles = null,
        private readonly ?NotificationRepository $notifications = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $character = $account instanceof Account ? $this->characters->active($account) : null;

        $now = $this->clock->now();
        if ($character !== null) {
            $this->repository->touchSeen($character->id, $now);
        }
        $online = $this->repository->countOnline($now->modify('-' . CommunityController::ONLINE_SECONDS . ' seconds'));

        $alert = $account instanceof Account && $this->duels !== null && $this->duels->countIncoming($account->id, $now) > 0;

        return $handler->handle(
            $request->withAttribute('character', $character)->withAttribute('online', $online)->withAttribute('duel_alert', $alert)
                ->withAttribute('in_battle', $character !== null && $this->battles?->find($character->id) !== null)
                ->withAttribute('unread', $account instanceof Account ? ($this->notifications?->unread($account->id) ?? 0) : 0)
        );
    }
}
