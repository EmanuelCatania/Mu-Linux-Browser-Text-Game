<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\CharacterRepository;
use Mu\Data\ItemRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Chat\ChatService;
use Mu\Domain\Guild\GuildService;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\Grid;
use Mu\Domain\Inventory\InventoryException;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Market\StoreService;
use Mu\Domain\World\Monster;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Clock;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** Tienda personal, mercado, clanes, chat, ranking y quién está conectado. */
final class CommunityController
{
    private const int PAGE_SIZE = 20;
    public const int ONLINE_SECONDS = 300;

    /** Filtro de clase → clases que incluye (la inicial y su evolución). */
    private const array CLASS_FILTERS = [
        'knight' => [CharacterClass::DarkKnight, CharacterClass::BladeKnight],
        'wizard' => [CharacterClass::DarkWizard, CharacterClass::SoulMaster],
        'elf' => [CharacterClass::FairyElf, CharacterClass::MuseElf],
        'summoner' => [CharacterClass::Summoner, CharacterClass::BloodySummoner],
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly StoreService $store,
        private readonly GuildService $guilds,
        private readonly ChatService $chat,
        private readonly CharacterRepository $characters,
        private readonly ItemRepository $items,
        private readonly ItemDescriber $describer,
        private readonly BattleService $battles,
        private readonly Translator $translator,
        private readonly Clock $clock,
    ) {
    }

    // --- tienda personal ---

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $locale = (string) $request->getAttribute('locale');

        $store = [];
        foreach ($this->items->stored(Container::Store, $account->id) as $stored) {
            $store[] = $this->card($stored->slot, $stored->item, $locale, $character, $stored->price);
        }
        $own = [];
        foreach ([Container::Inventory, Container::Equipment] as $container) {
            foreach ($this->items->items($container, $character->id) as $slot => $item) {
                $own[$container->value][] = $this->card($slot, $item, $locale, $character);
            }
        }

        return $this->view->render($response, $request, 'community/store.twig', [
            'store' => $store,
            'inventory' => $own['inventory'] ?? [],
            'equipment' => array_column($own['equipment'] ?? [], null, 'slot'),
            'rows' => ['store' => Container::Store->rows(), 'inventory' => Container::Inventory->rows()],
            'open_price' => StoreService::OPEN_PRICE,
            'max_price' => StoreService::MAX_PRICE,
            'in_battle' => $this->battles->current($character) !== null,
        ]);
    }

    public function storeAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->perform($request, $response, '/store', function (Account $account, Character $character, \Closure $form): string {
            switch ($form('do')) {
                case 'add':
                    [$container, $slot] = array_pad(explode(':', $form('from'), 2), 2, '');
                    $from = Container::tryFrom($container) ?? throw new InventoryException('store.error_pick');
                    if (!ctype_digit($slot) || !ctype_digit($form('price')) || strlen($form('price')) > 9) {
                        throw new InventoryException($slot === '' ? 'store.error_pick' : 'store.error_price');
                    }
                    $this->store->add($account, $character, $from, (int) $slot, (int) $form('price'));

                    return 'store.added';
                case 'move':
                    if (!ctype_digit($form('slot')) || !ctype_digit($form('to')) || strlen($form('to')) > 4) {
                        throw new InventoryException('store.error_pick');
                    }
                    $this->store->move($account, $character, (int) $form('slot'), (int) $form('to'));

                    return 'store.moved';
                case 'remove':
                    if (!ctype_digit($form('slot'))) {
                        throw new InventoryException('store.error_pick');
                    }
                    $this->store->remove($account, $character, (int) $form('slot'));

                    return 'store.removed';
                case 'open':
                    $this->store->open($account);

                    return 'store.opened';
                case 'close':
                    $this->store->close($account);

                    return 'store.closed';
                default:
                    throw new InventoryException('store.error_pick');
            }
        });
    }

    // --- mercado ---

    public function market(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $locale = (string) $request->getAttribute('locale');
        $query = $request->getQueryParams();

        $type = is_string($query['type'] ?? null) && isset(StoreService::TYPE_FILTERS[$query['type']]) ? $query['type'] : null;
        $classKey = is_string($query['class'] ?? null) && isset(self::CLASS_FILTERS[$query['class']]) ? $query['class'] : null;
        $order = is_string($query['order'] ?? null) && in_array($query['order'], StoreService::ORDERS, true) ? $query['order'] : 'newest';

        $all = $this->store->listings($type, $classKey === null ? null : self::CLASS_FILTERS[$classKey][0], $order);

        // Sin vendedor elegido (ni "ver todo") se muestra primero quién tiene la tienda abierta.
        $seller = is_string($query['seller'] ?? null) && preg_match('/^[A-Za-z0-9]{1,20}$/', $query['seller']) === 1 ? $query['seller'] : null;
        if ($seller === null && !isset($query['all'])) {
            $stores = [];
            foreach ($this->store->listings() as $listing) {
                $stores[$listing['seller']] ??= ['seller' => $listing['seller'], 'count' => 0, 'own' => $listing['account_id'] === $account->id, 'samples' => []];
                $stores[$listing['seller']]['count']++;
                if (count($stores[$listing['seller']]['samples']) < 6) {
                    $stores[$listing['seller']]['samples'][] = $listing['item']->imagePath();
                }
            }
            ksort($stores, SORT_NATURAL | SORT_FLAG_CASE);

            return $this->view->render($response, $request, 'community/market_stores.twig', ['stores' => array_values($stores)]);
        }
        if ($seller !== null) {
            $all = array_values(array_filter($all, static fn (array $l): bool => strcasecmp($l['seller'], $seller) === 0));
        }
        [$page, $pages, $offset] = self::paginate(count($all), $query['page'] ?? 1);

        $rows = [];
        foreach (array_slice($all, $offset, self::PAGE_SIZE) as $listing) {
            $rows[] = [
                'id' => $listing['id'],
                'seller' => $listing['seller'],
                'price' => $listing['price'],
                'own' => $listing['account_id'] === $account->id,
                'card' => $this->card(0, $listing['item'], $locale, $character),
            ];
        }

        return $this->view->render($response, $request, 'community/market.twig', [
            'rows' => $rows,
            'total' => count($all),
            'filters' => ['type' => $type, 'class' => $classKey, 'order' => $order],
            'seller' => $seller,
            'types' => array_keys(StoreService::TYPE_FILTERS),
            'classes' => array_map(static fn (array $c): string => $c[0]->label(), self::CLASS_FILTERS),
            'orders' => StoreService::ORDERS,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function buy(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->perform($request, $response, '/market', function (Account $account, Character $character, \Closure $form): string {
            if (!ctype_digit($form('id')) || strlen($form('id')) > 18) {
                throw new InventoryException('market.error_gone');
            }
            $this->store->buy($account, $character, (int) $form('id'));

            return 'market.bought';
        });
    }

    // --- clanes ---

    public function guild(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }
        $guild = $this->guilds->of($character);

        $members = [];
        foreach ($guild === null ? [] : $this->guilds->roster($guild['id']) as $member) {
            $members[] = $member + [
                'class_label' => CharacterClass::from($member['class'])->label(),
                'founder' => $member['id'] === $guild['founder_id'],
            ];
        }

        return $this->view->render($response, $request, 'community/guild.twig', [
            'guild' => $guild,
            'is_founder' => $guild !== null && $guild['founder_id'] === $character->id,
            'members' => $members,
            'invitation' => $this->guilds->invitation($character),
            'max_members' => GuildService::MAX_MEMBERS,
            'create_level' => GuildService::CREATE_LEVEL,
            'create_price' => GuildService::CREATE_PRICE,
        ]);
    }

    public function guildAction(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->perform($request, $response, '/guild', function (Account $account, Character $character, \Closure $form): string {
            switch ($form('do')) {
                case 'create':
                    $this->guilds->create($account, $character, $form('name'));

                    return 'guild.created';
                case 'invite':
                    $this->guilds->invite($account, $character, $form('name'));

                    return 'guild.invited';
                case 'accept':
                case 'decline':
                    $this->guilds->answer($account, $character, $form('do') === 'accept');

                    return $form('do') === 'accept' ? 'guild.joined' : 'guild.declined';
                case 'quit':
                    $this->guilds->quit($account, $character);

                    return 'guild.left';
                case 'kick':
                    $this->guilds->kick($account, $character, ctype_digit($form('character')) ? (int) $form('character') : 0);

                    return 'guild.kicked';
                case 'disband':
                    $this->guilds->disband($account, $character);

                    return 'guild.disbanded';
                default:
                    throw new InventoryException('guild.error_not_member');
            }
        });
    }

    // --- chat (JSON) ---

    public function chat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        [, $character] = self::player($request);
        $payload = ['ok' => true];

        if ($character === null) {
            $payload = ['ok' => false, 'error' => $this->translator->translate('error.character_not_found', (string) $request->getAttribute('locale'))];
        } else {
            $battle = $this->battles->current($character);
            $spot = $battle === null || $battle->defeated ? null : $battle->map . ':' . $battle->spot;

            if ($request->getMethod() === 'POST') {
                try {
                    $this->chat->post($character, $spot ?? ChatService::GLOBAL, AuthController::form($request)('text'));
                } catch (InventoryException $e) {
                    $payload = ['ok' => false, 'error' => $this->translator->translate($e->getMessage(), (string) $request->getAttribute('locale'))];
                }
            }
            $payload['messages'] = $this->chat->recent($spot);
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }

    // --- ranking y conectados ---

    public function ranking(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $classKey = is_string($query['class'] ?? null) && isset(self::CLASS_FILTERS[$query['class']]) ? $query['class'] : null;
        $order = in_array($query['order'] ?? '', ['kills', 'pvp'], true) ? $query['order'] : 'experience';
        $classes = $classKey === null ? [] : array_map(static fn (CharacterClass $c): int => $c->value, self::CLASS_FILTERS[$classKey]);

        // Se cuenta primero para saber cuántas páginas hay y no pedir una que no existe.
        $total = $this->characters->ranking($classes, $order, 1, 0)['total'];
        [$page, $pages, $offset] = self::paginate($total, $query['page'] ?? 1);

        $rows = [];
        foreach ($this->characters->ranking($classes, $order, self::PAGE_SIZE, $offset)['rows'] as $i => $row) {
            $rows[] = $row + ['position' => $offset + $i + 1, 'class_label' => CharacterClass::from($row['class'])->label()];
        }

        return $this->view->render($response, $request, 'community/ranking.twig', [
            'rows' => $rows,
            'total' => $total,
            'filters' => ['class' => $classKey, 'order' => $order],
            'classes' => array_map(static fn (array $c): string => $c[0]->label(), self::CLASS_FILTERS),
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    public function online(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = [];
        foreach ($this->characters->online($this->clock->now()->modify('-' . self::ONLINE_SECONDS . ' seconds')) as $row) {
            [$map, $spot] = array_pad(explode(':', (string) $row['location'], 2), 2, '');
            $rows[] = $row + [
                'class_label' => CharacterClass::from($row['class'])->label(),
                'map' => $row['location'] === null ? null : Monster::label($map),
                'spot' => (int) substr($spot, 4),
            ];
        }

        return $this->view->render($response, $request, 'community/online.twig', ['rows' => $rows]);
    }

    /** @return array<string, mixed> datos para dibujar un ítem con su ficha */
    private function card(int $slot, Item $item, string $locale, Character $character, ?int $price = null): array
    {
        return [
            'slot' => $slot,
            'col' => $slot % Grid::COLUMNS,
            'row' => intdiv($slot, Grid::COLUMNS),
            'width' => $item->width,
            'height' => $item->height,
            'type' => $item->type,
            'image' => $item->imagePath(),
            'usable' => false,
            'price' => $price,
            'price_text' => $price === null ? null : $this->translator->translate('store.price', $locale, ['n' => $price]),
        ] + $this->describer->describe($item, $locale, $character);
    }

    /**
     * Ejecuta una acción de formulario y vuelve a la página con el resultado como aviso.
     *
     * @param callable(Account, Character, \Closure): string $action devuelve la clave del mensaje de éxito
     */
    private function perform(ServerRequestInterface $request, ResponseInterface $response, string $back, callable $action): ResponseInterface
    {
        [$account, $character] = self::player($request);
        if ($account === null || $character === null) {
            return self::redirect($response, '/characters');
        }

        try {
            $this->session->flash('ok', $action($account, $character, AuthController::form($request)));
        } catch (InventoryException $e) {
            $this->session->flash('error', $e->getMessage());
        }

        return self::redirect($response, $back);
    }

    /** @return array{int, int, int} página actual, cantidad de páginas y desplazamiento */
    private static function paginate(int $total, mixed $requested): array
    {
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = is_string($requested) && ctype_digit($requested) && strlen($requested) < 6 ? (int) $requested : 1;
        $page = max(1, min($pages, $page));

        return [$page, $pages, ($page - 1) * self::PAGE_SIZE];
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
