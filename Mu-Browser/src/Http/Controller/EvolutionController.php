<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Inventory\InventoryException;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** NPC de las misiones de clase: Sebina the Priestess, Marlon y Apostle Devin. */
final class EvolutionController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly EvolutionService $evolution,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        return $this->view->render($response, $request, 'npc/sebina.twig', ['evo' => $this->evolution->status($character)]);
    }

    public function talk(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');
        if (!$account instanceof Account || !$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        try {
            $this->session->flash('ok', $this->evolution->talk($account, $character));
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withStatus(302)->withHeader('Location', '/npc/sebina');
    }

    public function showMarlon(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        return $this->view->render($response, $request, 'npc/marlon.twig', ['evo' => $this->evolution->status($character)]);
    }

    public function talkMarlon(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');
        if (!$account instanceof Account || !$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        try {
            $this->session->flash('ok', $this->evolution->talkMarlon($account, $character));
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withStatus(302)->withHeader('Location', '/npc/marlon');
    }

    public function showDevin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        return $this->view->render($response, $request, 'npc/devin.twig', ['evo' => $this->evolution->status($character)]);
    }

    public function talkDevin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');
        if (!$account instanceof Account || !$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        try {
            $this->session->flash('ok', $this->evolution->talkThird($account, $character));
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return $response->withStatus(302)->withHeader('Location', '/npc/devin');
    }
}
