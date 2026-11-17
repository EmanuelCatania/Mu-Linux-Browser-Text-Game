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
use Mu\Domain\Npc\NpcService;
use Mu\Domain\Quest\Quest;
use Mu\Domain\Quest\QuestService;
use Mu\Domain\World\Monster;
use Mu\Http\Session;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Chaos Machine, NPC especiales y misiones del Shadow Phantom Soldier. */
final class NpcController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly NpcService $npcs,
        private readonly QuestService $quests,
        private readonly ItemRepository $items,
        private readonly ItemDescriber $describer,
        private readonly BattleService $battles,
    ) {
    }

    /** @param array{id?: string} $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? array_key_first(NpcService::NPCS);
        if (!isset(NpcService::NPCS[$id])) {
            throw new HttpNotFoundException($request);
        }
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $locale = (string) $request->getAttribute('locale');

        return $this->view->render($response, $request, 'npc/npc.twig', [
            'npc_id' => $id,
            'npc_name' => NpcService::NPCS[$id],
            'npcs' => NpcService::NPCS,
            'price' => NpcService::PRICE,
            'mix' => $this->cards($this->items->items(Container::Mix, $account->id), $locale, $character),
            'inventory' => $this->cards($this->items->items(Container::Inventory, $character->id), $locale, $character),
            'rows' => ['mix' => Container::Mix->rows(), 'inventory' => Container::Inventory->rows()],
            'in_battle' => $this->battles->current($character) !== null,
        ]);
    }

    /** @param array{id: string} $args */
    public function mix(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }

        try {
            $result = $this->npcs->mix($account, $character, $args['id']);
            $this->session->flash($result->success ? 'ok' : 'error', $result->success ? 'npc.mix_ok' : 'npc.mix_failed');
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return self::redirect($response, '/npc/' . rawurlencode($args['id']));
    }

    public function quests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $locale = (string) $request->getAttribute('locale');
        $current = $this->quests->current($character);

        return $this->view->render($response, $request, 'npc/quests.twig', [
            'current' => $current === null ? null : $this->questView($current, $locale, $character) + [
                'score' => min($character->questScore, $current->amount),
                'done' => $character->questScore >= $current->amount,
            ],
            'available' => array_map(
                fn (Quest $q): array => $this->questView($q, $locale, $character),
                array_filter($this->quests->available($character), static fn (Quest $q): bool => $q->id !== $current?->id),
            ),
            'in_battle' => $this->battles->current($character) !== null,
        ]);
    }

    public function questAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $form = AuthController::form($request);

        try {
            switch ($form('do')) {
                case 'accept':
                    $this->quests->accept($account, $character, (int) $form('quest'));
                    $this->session->flash('ok', 'quest.accepted');
                    break;
                case 'give_up':
                    $this->quests->giveUp($account, $character);
                    $this->session->flash('ok', 'quest.given_up');
                    break;
                case 'claim':
                    $this->session->flash('ok', 'quest.reward_' . $this->quests->claim($account, $character));
                    break;
                case 'restore':
                    $this->quests->restore($account, $character);
                    $this->session->flash('ok', 'quest.restored');
                    break;
            }
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        // El botón de curarse del menú lateral vuelve a la página desde la que se usó.
        $back = $form('return');

        return self::redirect($response, preg_match('#^/[A-Za-z0-9/_-]*$#', $back) === 1 ? $back : '/quests');
    }

    /** @return array<string, mixed> */
    private function questView(Quest $quest, string $locale, Character $character): array
    {
        return [
            'id' => $quest->id,
            'target' => $quest->target,
            'target_label' => Monster::label($quest->target),
            'amount' => $quest->amount,
            'level_min' => $quest->levelMin,
            'level_max' => $quest->levelMax,
            'reward' => $quest->reward === null
                ? null
                : $this->describer->describe($quest->reward, $locale, $character) + ['image' => $quest->reward->imagePath()],
        ];
    }

    /**
     * @param array<int, Item> $items
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
