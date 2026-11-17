<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\ItemRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Pvp\Duel;
use Mu\Domain\Pvp\DuelService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** PvP: mesa de apuestas, desafíos y parte de cada duelo. */
final class PvpController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly DuelService $duels,
        private readonly ItemRepository $items,
        private readonly ItemDescriber $describer,
        private readonly SkillCatalog $skills,
        private readonly BattleService $battles,
        private readonly int $maxZen,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $locale = (string) $request->getAttribute('locale');
        $cards = fn (Container $container, int $ownerId): array => $this->cards($this->items->items($container, $ownerId), $locale, $character);

        $duel = $this->duels->open($account);
        $rivalStake = [];
        if ($duel !== null) {
            $rivalAccount = $duel->isChallenger($account->id) ? $duel->targetAccountId : $duel->challengerAccountId;
            // Lo del desafiado sólo cuenta como apuesta cuando aceptó: antes todavía la está armando.
            if (!$duel->isChallenger($account->id) || $duel->status === Duel::ANSWERED) {
                $rivalStake = $cards(Container::Duel, $rivalAccount);
            }
        }

        $history = [];
        foreach ($this->duels->history($character) as $past) {
            $mine = $past->challengerId === $character->id ? 0 : 1;
            $history[] = [
                'id' => $past->id,
                'rival' => $mine === 0 ? $past->targetName : $past->challengerName,
                'outcome' => $past->winnerId === null ? 'draw' : ($past->winnerId === $character->id ? 'win' : 'loss'),
                'zen' => $past->zen,
                'items' => count($past->result['stakes'][$past->winnerId === $character->id ? 1 - $mine : $mine] ?? []),
                'when' => $past->updatedAt->format('Y-m-d H:i'),
            ];
        }

        $rival = $request->getQueryParams()['rival'] ?? '';

        return $this->view->render($response, $request, 'community/pvp.twig', [
            'duel' => $duel,
            'role' => $duel === null ? null : ($duel->isChallenger($account->id) ? 'challenger' : 'target'),
            'locked' => $duel !== null && $duel->stakeLocked($account->id),
            'rival_stake' => $rivalStake,
            'stake' => $cards(Container::Duel, $account->id),
            'prize' => $cards(Container::Prize, $account->id),
            'inventory' => $cards(Container::Inventory, $character->id),
            'rows' => ['duel' => Container::Duel->rows(), 'prize' => Container::Prize->rows(), 'inventory' => Container::Inventory->rows()],
            'record' => $this->duels->record($character),
            'history' => $history,
            'rival' => is_string($rival) ? mb_substr($rival, 0, 12) : '',
            'max_zen' => $this->maxZen,
            'in_battle' => $this->battles->current($character) !== null,
        ]);
    }

    public function action(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $form = AuthController::form($request);
        $back = '/pvp';

        try {
            $id = ctype_digit($form('duel')) && strlen($form('duel')) < 10 ? (int) $form('duel') : 0;
            switch ($form('do')) {
                case 'challenge':
                    $zen = $form('zen') === '' ? '0' : $form('zen');
                    if (!ctype_digit($zen) || strlen($zen) > 9) {
                        throw new InventoryException('pvp.error_zen');
                    }
                    $this->duels->challenge($account, $character, $form('rival'), (int) $zen);
                    $this->session->flash('ok', 'pvp.challenged');
                    break;
                case 'accept':
                case 'confirm':
                    $outcome = $form('do') === 'accept' ? $this->duels->accept($account, $id) : $this->duels->confirm($account, $id);
                    if ($outcome === DuelService::OUTCOME_VOID) {
                        throw new InventoryException('pvp.error_zen_gone');
                    }
                    if ($outcome === DuelService::OUTCOME_FINISHED) {
                        $back = '/pvp/' . $id;
                    } else {
                        $this->session->flash('ok', 'pvp.accepted');
                    }
                    break;
                case 'withdraw':
                    $this->duels->withdraw($account, $id);
                    $this->session->flash('ok', 'pvp.withdrawn');
                    break;
                default:
                    throw new InventoryException('pvp.error_gone');
            }
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return self::redirect($response, $back);
    }

    /** @param array{id: string} $args */
    public function report(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $duel = strlen($args['id']) < 10 ? $this->duels->find((int) $args['id']) : null;
        if ($duel === null || $duel->status !== Duel::FINISHED || $duel->result === null) {
            throw new HttpNotFoundException($request);
        }
        $locale = (string) $request->getAttribute('locale');
        $result = $duel->result;

        $log = [];
        foreach ($result['log'] as $entry) {
            $log[] = $entry + [
                'actor' => $result['names'][$entry['by']],
                'foe' => $result['names'][1 - $entry['by']],
                'skill_name' => $entry['skill'] === null ? null : $this->skills->name((string) $entry['skill']),
                'tags' => $entry['tag'] === '' ? [] : explode(' ', $entry['tag']),
            ];
        }

        $character = $request->getAttribute('character');
        $stakes = [];
        foreach ($result['stakes'] as $side => $codes) {
            $items = array_map(Item::parse(...), $codes);
            $stakes[$side] = $character instanceof Character
                ? array_column($this->cards($items, $locale, $character), 'name')
                : array_map(static fn (Item $i): string => $i->displayName(), $items);
        }

        $winner = $duel->winnerId === null ? null : ($duel->winnerId === $duel->challengerId ? 0 : 1);

        return $this->view->render($response, $request, 'community/pvp_report.twig', [
            'duel' => $duel,
            'result' => $result,
            'log' => $log,
            'stakes' => $stakes,
            'winner' => $winner,
            'percent' => [
                (int) round($result['hp'][0] * 100 / max(1, $result['hpMax'][0])),
                (int) round($result['hp'][1] * 100 / max(1, $result['hpMax'][1])),
            ],
        ]);
    }

    /**
     * @param array<int, Item> $items por slot
     *
     * @return list<array<string, mixed>>
     */
    private function cards(array $items, string $locale, Character $character): array
    {
        $cards = [];
        foreach ($items as $slot => $item) {
            $cards[] = [
                'slot' => $slot,
                'col' => $slot % Grid::COLUMNS,
                'row' => intdiv($slot, Grid::COLUMNS),
                'width' => $item->width,
                'height' => $item->height,
                'type' => $item->type,
                'image' => $item->imagePath(),
                'usable' => false,
                'price' => null,
                'price_text' => null,
            ] + $this->describer->describe($item, $locale, $character);
        }

        return $cards;
    }

    /** @return array{?Account, ?Character} */
    private static function player(ServerRequestInterface $request): array
    {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');

        return [$account instanceof Account ? $account : null, $character instanceof Character ? $character : null];
    }

    private static function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $to);
    }
}
