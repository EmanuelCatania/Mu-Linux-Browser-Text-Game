<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Party\PartyService;
use Mu\Http\Session;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Invitar, aceptar, rechazar y salir del party. Se usa desde la página de cada mapa. */
final class PartyController
{
    public function __construct(private readonly Session $session, private readonly PartyService $party)
    {
    }

    public function act(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        $form = AuthController::form($request);
        $back = $form('return');
        $back = preg_match('#^/[A-Za-z0-9/_?=&-]*$#', $back) === 1 ? $back : '/map';
        if (!$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        try {
            switch ($form('do')) {
                case 'invite':
                    $this->party->invite($character, $form('name'));
                    $this->session->flash('ok', 'party.invited');
                    break;
                case 'accept':
                    $this->party->accept($character);
                    $this->session->flash('ok', 'party.joined');
                    break;
                case 'decline':
                    $this->party->decline($character);
                    break;
                case 'leave':
                    $this->party->leave($character);
                    $this->session->flash('ok', 'party.left');
                    break;
            }
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withStatus(302)->withHeader('Location', $back);
    }
}
