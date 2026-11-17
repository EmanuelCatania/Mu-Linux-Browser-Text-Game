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
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Shop\ShopCatalog;
use Mu\Domain\Shop\ShopService;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Inventario y equipo, baúl y tiendas NPC. Las páginas se renderizan en el servidor; mover, usar,
 * comprar y vender son pedidos POST que responden JSON y, si salió bien, la página se recarga.
 */
final class InventoryController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly InventoryService $inventory,
        private readonly ShopService $shopService,
        private readonly ShopCatalog $shops,
        private readonly ItemRepository $items,
        private readonly ItemDescriber $describer,
        private readonly BattleService $battles,
        private readonly Translator $translator,
    ) {
    }

    public function inventory(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response, 'inventory/inventory.twig');
    }

    public function vault(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response, 'inventory/vault.twig', function (Account $account, Character $character, string $locale): array {
            return ['vault' => $this->cards($this->items->items(Container::Vault, $account->id), $locale, $character)];
        });
    }

    /** @param array{id?: string} $args */
    public function shop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = $args['id'] ?? array_key_first(ShopCatalog::SHOPS);
        if (!ShopCatalog::exists($id)) {
            throw new HttpNotFoundException($request);
        }

        return $this->page($request, $response, 'inventory/shop.twig', function (Account $account, Character $character, string $locale) use ($id): array {
            return [
                'shop_id' => $id,
                'shop_name' => ShopCatalog::SHOPS[$id],
                'shops' => ShopCatalog::SHOPS,
                'stock' => $this->cards($this->shops->stock($id), $locale, $character, 'buy'),
                'shop_rows' => ShopCatalog::ROWS,
            ];
        }, sellPrices: true);
    }

    public function move(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->action($request, $response, function (Account $account, Character $character, \Closure $form): ?array {
            [$from, $fromSlot] = self::place($form('from'));
            [$to, $toSlot] = self::place($form('to'));
            $this->inventory->move($account, $character, $from, $fromSlot, $to, $toSlot);

            return null;
        });
    }

    public function use(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->action($request, $response, function (Account $account, Character $character, \Closure $form): array {
            return $this->inventory->use($account, $character, self::slot($form('slot')));
        });
    }

    public function jewel(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->action($request, $response, function (Account $account, Character $character, \Closure $form): array {
            return $this->inventory->applyJewel($account, $character, self::slot($form('jewel')), self::slot($form('target')));
        });
    }

    /** @param array{id: string} $args */
    public function buy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->action($request, $response, function (Account $account, Character $character, \Closure $form) use ($args): array {
            $item = $this->shopService->buy($account, $character, $args['id'], self::slot($form('slot')));

            return ['key' => 'shop.bought', 'params' => ['name' => $item->displayName(), 'n' => $item->buyPrice()]];
        });
    }

    public function sell(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->action($request, $response, function (Account $account, Character $character, \Closure $form): array {
            [$from, $slot] = self::place($form('from'));

            return ['key' => 'shop.sold', 'params' => ['n' => $this->shopService->sell($account, $character, $from, $slot)]];
        });
    }

    /**
     * Página con el inventario y el equipo del personaje activo, más lo que agregue $extra.
     *
     * @param null|callable(Account, Character, string): array<string, mixed> $extra
     */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        ?callable $extra = null,
        bool $sellPrices = false,
    ): ResponseInterface {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');
        if (!$account instanceof Account || !$character instanceof Character) {
            return $response->withStatus(302)->withHeader('Location', '/characters');
        }
        $locale = (string) $request->getAttribute('locale');
        $price = $sellPrices ? 'sell' : null;

        $equipment = [];
        foreach ($this->cards($this->items->items(Container::Equipment, $character->id), $locale, $character, $price) as $card) {
            $equipment[$card['slot']] = $card;
        }

        return $this->view->render($response, $request, $template, ($extra === null ? [] : $extra($account, $character, $locale)) + [
            'inventory' => $this->cards($this->items->items(Container::Inventory, $character->id), $locale, $character, $price),
            'equipment' => $equipment,
            'stats' => $this->battles->stats($character),
            'in_battle' => $this->battles->current($character) !== null,
            'rows' => ['inventory' => Container::Inventory->rows(), 'vault' => Container::Vault->rows()],
        ]);
    }

    /**
     * Datos de cada ítem para dibujarlo: posición en la grilla, imagen y ficha.
     *
     * @param array<int, Item> $items por slot
     * @param ?string          $price 'buy' o 'sell' para agregar el precio a la ficha
     *
     * @return list<array<string, mixed>>
     */
    private function cards(array $items, string $locale, Character $character, ?string $price = null): array
    {
        $cards = [];
        foreach ($items as $slot => $item) {
            $amount = match ($price) {
                'buy' => $item->buyPrice(),
                'sell' => $item->sellPrice(),
                default => null,
            };
            $cards[] = [
                'slot' => $slot,
                'col' => $slot % Grid::COLUMNS,
                'row' => intdiv($slot, Grid::COLUMNS),
                'width' => $item->width,
                'height' => $item->height,
                'type' => $item->type,
                'image' => $item->imagePath(),
                'usable' => in_array($item->type, ['F', 'G', 'J'], true) || ($item->type === 'K' && $item->subType === '5'),
                'price' => $amount,
                'price_text' => $amount === null ? null : $this->translator->translate('shop.price_' . $price, $locale, ['n' => $amount]),
            ] + $this->describer->describe($item, $locale, $character);
        }

        return $cards;
    }

    /**
     * Ejecuta una acción y responde JSON: {ok: true} o {ok: false, error: "texto"}.
     * Si salió bien deja el mensaje como aviso para mostrarlo cuando la página se recargue.
     *
     * @param callable(Account, Character, \Closure): ?array{key: string, params: array<string, int|string>} $action
     */
    private function action(ServerRequestInterface $request, ResponseInterface $response, callable $action): ResponseInterface
    {
        $account = $request->getAttribute('account');
        $character = $request->getAttribute('character');
        $locale = (string) $request->getAttribute('locale');

        try {
            if (!$account instanceof Account || !$character instanceof Character) {
                throw new InventoryException('error.character_not_found');
            }
            $message = $action($account, $character, AuthController::form($request));
            if ($message !== null) {
                $params = $message['params'];
                if (isset($params['stat'])) {
                    $params['stat'] = $this->translator->translate('stat.' . $params['stat'], $locale);
                }
                $this->session->set('_flash', ['type' => 'ok', 'key' => $message['key'], 'params' => $params]);
            }
            $payload = ['ok' => true];
        } catch (InventoryException $e) {
            $payload = ['ok' => false, 'error' => $this->translator->translate($e->getMessage(), $locale)];
        }

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * "inventory:12" → [Container::Inventory, 12].
     *
     * @return array{Container, int}
     */
    private static function place(string $value): array
    {
        [$container, $slot] = array_pad(explode(':', $value, 2), 2, '');
        $container = Container::tryFrom($container) ?? throw new InventoryException('inventory.error_no_item');

        return [$container, self::slot($slot)];
    }

    private static function slot(string $value): int
    {
        if (!ctype_digit($value) || strlen($value) > 3) {
            throw new InventoryException('inventory.error_no_item');
        }

        return (int) $value;
    }
}
