<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\CharacterRepository;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\MasterTree;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Árbol master: ver el avance y gastar los puntos. */
final class MasterController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CharacterRepository $characters,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }

        $branches = [];
        foreach (MasterTree::BRANCHES as $branch => $nodes) {
            $rows = [];
            foreach ($nodes as $tier => $node) {
                $rank = $character->master[$node] ?? 0;
                $rows[] = [
                    'id' => $node,
                    'rank' => $rank,
                    'max' => MasterTree::MAX_RANK,
                    'per_rank' => MasterTree::PER_RANK[$node],
                    'total' => $rank * MasterTree::PER_RANK[$node],
                    'needs' => MasterTree::TIER_POINTS[$tier],
                    'open' => MasterTree::canLearn($node, $character->master),
                ];
            }
            $branches[] = ['id' => $branch, 'spent' => MasterTree::spentIn($branch, $character->master), 'nodes' => $rows];
        }

        return $this->view->render($response, $request, 'master.twig', [
            'is_master' => $character->class->tier() === 3,
            'branches' => $branches,
            'need' => MasterTree::experienceFor($character->masterLevel),
            'max_level' => MasterTree::MAX_LEVEL,
        ]);
    }

    public function learn(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        $node = AuthController::form($request)('node');
        if ($character instanceof Character) {
            if ($character->class->tier() !== 3 || !MasterTree::canLearn($node, $character->master)) {
                $this->session->flash('error', 'master.error_locked');
            } elseif (!$this->characters->spendMasterPoint($character->id, $node)) {
                $this->session->flash('error', 'master.error_points');
            }
        }

        return $response->withStatus(302)->withHeader('Location', '/master');
    }
}
