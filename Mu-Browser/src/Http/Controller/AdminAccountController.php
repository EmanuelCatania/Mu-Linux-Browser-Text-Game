<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Admin\AccountEditor;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Forum\ForumService;
use Mu\Domain\Item\ItemCatalog;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Panel: ficha de una cuenta con sus personajes e ítems, y la moderación del foro. */
final class AdminAccountController
{
    /** Paso de la misión de clase → texto, para el desplegable. */
    private const array EVO_STEPS = [
        EvolutionService::NONE => 'admin.evo_0', EvolutionService::SEEKING_SCROLL => 'admin.evo_1', EvolutionService::HAS_SCROLL => 'admin.evo_2',
        EvolutionService::SEEKING_TREASURE => 'admin.evo_3', EvolutionService::HAS_TREASURE => 'admin.evo_4', EvolutionService::DONE => 'admin.evo_5',
        EvolutionService::SEEKING_RING => 'admin.evo_6', EvolutionService::HAS_RING => 'admin.evo_7', EvolutionService::SEEKING_STONE => 'admin.evo_8',
        EvolutionService::HAS_STONE => 'admin.evo_9', EvolutionService::MARLON_DONE => 'admin.evo_10', EvolutionService::DEVIN_TROPHIES => 'admin.evo_11',
        EvolutionService::DEVIN_BARRACKS => 'admin.evo_12', EvolutionService::DEVIN_DARK_ELF => 'admin.evo_13', EvolutionService::MASTER => 'admin.evo_14',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly AccountRepository $accounts,
        private readonly CharacterRepository $characters,
        private readonly AccountEditor $editor,
        private readonly ItemCatalog $catalog,
        private readonly ForumService $forum,
        private readonly Clock $clock,
    ) {
    }

    /** @param array{id: string} $args */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $account = $this->accounts->findById((int) $args['id']) ?? throw new HttpNotFoundException($request);
        $characters = [];
        foreach ($this->characters->findByAccount($account->id) as $c) {
            $characters[] = ['c' => $c, 'in_battle' => $this->editor->inBattle($c->id)];
        }
        $invalid = $this->session->get('admin_invalid');
        $this->session->remove('admin_invalid');

        $catalog = [];
        foreach (ItemCatalog::TYPES as $type) {
            foreach ($this->catalog->ofType($type) as $item) {
                $catalog[$type . '/' . $item->name] = $item->displayName();
            }
        }

        return $this->view->render($response, $request, 'admin/account.twig', [
            'target' => $account,
            'characters' => $characters,
            'items' => $this->editor->itemsOf($account),
            'classes' => array_map(static fn (CharacterClass $c): array => ['id' => $c->value, 'label' => $c->label()], CharacterClass::cases()),
            'evo_steps' => self::EVO_STEPS,
            'fields' => array_keys(AccountEditor::CHARACTER_FIELDS),
            'catalog' => $catalog,
            'invalid' => is_array($invalid) ? $invalid : [],
            'posts' => $this->forum->countPosts($account->username),
        ]);
    }

    /** @param array{id: string} $args */
    public function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $account = $this->accounts->findById((int) $args['id']) ?? throw new HttpNotFoundException($request);
        $form = AuthController::form($request);
        $body = array_map(static fn ($v): string => is_string($v) ? $v : '', (array) $request->getParsedBody());
        $character = null;
        if (ctype_digit($form('character'))) {
            $character = $this->characters->findById((int) $form('character'));
            if ($character === null || $character->accountId !== $account->id) {
                throw new HttpNotFoundException($request);
            }
        }
        $anchor = $character === null ? '' : '#char-' . $character->id;

        try {
            switch ($form('do')) {
                case 'account':
                    $this->editor->updateAccount($account, $form('email'), $form('password'));
                    break;
                case 'character':
                    $invalid = $character === null ? ['name'] : $this->editor->updateCharacter($character, $body);
                    if ($invalid !== []) {
                        $this->session->set('admin_invalid', array_map(static fn (string $f): string => $character?->id . ':' . $f, $invalid));
                        throw new ValidationException(['admin.settings_invalid']);
                    }
                    break;
                case 'delete_character':
                    if ($character !== null) {
                        $this->editor->deleteCharacter($character);
                        $anchor = '#characters';
                    }
                    break;
                case 'give':
                    if ($character !== null) {
                        $this->editor->giveItem(
                            $character,
                            $form('item'),
                            (int) $form('level'),
                            $form('luck') === '1',
                            (int) $form('option'),
                            (int) $form('excellent'),
                            $this->clock->now(),
                        );
                    }
                    break;
                case 'delete_item':
                    $this->editor->deleteItem($account, (int) $form('item_id'));
                    break;
            }
            $this->session->flash('ok', 'admin.account_saved');
        } catch (ValidationException $e) {
            $this->session->flash('error', $e->errors[0]);
        }

        return $response->withStatus(302)->withHeader('Location', '/admin/accounts/' . $account->id . $anchor);
    }

    public function forum(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $author = is_string($query['author'] ?? null) ? mb_substr(trim($query['author']), 0, 12) : '';
        $total = $this->forum->countPosts($author);
        $pages = max(1, (int) ceil($total / ForumService::PAGE_SIZE));
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min($pages, (int) $query['page'])) : 1;

        return $this->view->render($response, $request, 'admin/forum.twig', [
            'posts' => $this->forum->recentPosts($page, $author),
            'flagged' => $this->forum->flaggedThreads(),
            'author' => $author,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
