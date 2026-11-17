<?php

declare(strict_types=1);

namespace Mu;

use Mu\Data\NotificationRepository;
use Mu\Http\Controller\NotificationController;
use Mu\Domain\Admin\AccountEditor;
use Mu\Http\Controller\AdminAccountController;
use Mu\Domain\Admin\FirstRun;
use Mu\Http\Controller\SetupController;
use Mu\Http\Middleware\FirstRunMiddleware;
use Mu\Data\NewsRepository;
use Mu\Domain\Admin\GameSettings;
use Mu\Domain\Forum\ForumService;
use Mu\Http\Controller\AdminController;
use Mu\Http\Controller\ForumController;
use Mu\Http\Middleware\RequireAdmin;
use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\DuelRepository;
use Mu\Data\GoldenRepository;
use Mu\Data\GuildRepository;
use Mu\Data\ItemRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Data\PresenceRepository;
use Mu\Data\Transaction;
use Mu\Data\RememberTokenRepository;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\RememberMe;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\CharacterService;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Chat\ChatService;
use Mu\Domain\Guild\GuildService;
use Mu\Domain\Inventory\InventoryService;
use Mu\Domain\Party\PartyService;
use Mu\Domain\Npc\BoxOpener;
use Mu\Domain\Npc\NpcService;
use Mu\Domain\Npc\SpecialNpcs;
use Mu\Domain\Quest\QuestCatalog;
use Mu\Domain\Quest\QuestService;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Item\ItemCatalog;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Market\StoreService;
use Mu\Domain\Pvp\DuelEngine;
use Mu\Domain\Pvp\DuelService;
use Mu\Domain\Shop\ShopCatalog;
use Mu\Domain\Shop\ShopService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\Announcements;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\Monster;
use Mu\Domain\World\ScheduledEvents;
use Mu\Domain\World\WorldBosses;
use Mu\Domain\World\MapCatalog;
use Mu\Http\Controller\AccountController;
use Mu\Http\Controller\AuthController;
use Mu\Http\Controller\BattleController;
use Mu\Http\Controller\CatalogController;
use Mu\Http\Controller\CharacterController;
use Mu\Http\Controller\CommunityController;
use Mu\Http\Controller\EvolutionController;
use Mu\Http\Controller\HomeController;
use Mu\Http\Controller\InventoryController;
use Mu\Http\Controller\MapController;
use Mu\Http\Controller\NpcController;
use Mu\Http\Controller\MasterController;
use Mu\Http\Controller\PartyController;
use Mu\Http\Controller\PvpController;
use Mu\Http\Middleware\AuthMiddleware;
use Mu\Http\Middleware\CharacterMiddleware;
use Mu\Http\Middleware\CsrfMiddleware;
use Mu\Http\Middleware\LocaleMiddleware;
use Mu\Http\Middleware\RequireLogin;
use Mu\Http\Middleware\SessionMiddleware;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Clock;
use Mu\Support\Config;
use Mu\Support\RandomRng;
use Mu\Support\Translator;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App as SlimApp;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Throwable;

/** Arma la aplicación: dependencias, middlewares y rutas. */
final class App
{
    /** @return SlimApp<null> */
    public static function create(Config $config, PDO $pdo, Clock $clock, string $rootDir): SlimApp
    {
        $session = new Session();
        $translator = new Translator($rootDir . '/lang');
        $view = new View($rootDir . '/templates', $translator, $session, sys_get_temp_dir() . '/mu-twig', $rootDir . '/public');
        $view->setTimezone($config->timezone);

        $accountRepo = new AccountRepository($pdo);
        $accounts = new AccountService($accountRepo, new LoginAttemptRepository($pdo), $clock);
        $rememberMe = new RememberMe(new RememberTokenRepository($pdo), $accountRepo, $clock, $config->sessionSecret);

        $characterRepo = new CharacterRepository($pdo);
        // Los valores de data/game.php, con lo que se haya cambiado desde el panel de administración encima.
        $gameDefaults = require $rootDir . '/data/game.php';
        $gameSettings = new GameSettings($pdo);
        $game = $gameSettings->apply($gameDefaults);
        $characterService = new CharacterService($characterRepo, $accountRepo, $clock, (int) $game['reset']['zen'], (bool) ($game['reset']['enabled'] ?? false));
        $skillCatalog = new SkillCatalog($rootDir . '/data/skills.php');
        $itemCatalog = new ItemCatalog($rootDir . '/data/items');
        $itemDescriber = new ItemDescriber($translator, $skillCatalog);

        $mapCatalog = new MapCatalog($rootDir . '/data/maps');
        $golden = new GoldenInvasion(new GoldenRepository($pdo), $game['golden']['hours']);
        $announcements = new Announcements();
        $announcements->add(static fn (\DateTimeImmutable $now): array => [
            Announcements::timed('ticker.golden', $golden->isActive($now), $golden->secondsToChange($now), '/map'),
        ]);
        $worldBosses = new WorldBosses($pdo, (int) $game['world_boss']['respawn_seconds']);
        $announcements->add(static fn (\DateTimeImmutable $now): array => array_map(
            static fn (array $b): array => Announcements::boss(Monster::label($b['monster']), Monster::label($b['map']), $b['alive'], $b['seconds'], '/map'),
            $worldBosses->status($now),
        ));
        $events = new ScheduledEvents($game['events']);
        $announcements->add(static fn (\DateTimeImmutable $now): array => array_map(
            static fn (string $id): array => Announcements::timed('event.' . $id, $events->isOpen($id, $now), $events->secondsToChange($id, $now), '/map'),
            $events->ids(),
        ));
        $view->setTicker(static fn (): array => $announcements->current($clock->now()));
        $battleRepo = new BattleRepository($pdo);
        $itemRepo = new ItemRepository($pdo);
        $calculator = new StatsCalculator();
        $transaction = new Transaction($pdo);
        $rng = new RandomRng();
        $questCatalog = new QuestCatalog($rootDir . '/data/quests.xml');
        $notifications = new NotificationRepository($pdo, $clock);
        $party = new PartyService($pdo, $game['party'], $notifications);
        $battleService = new BattleService(
            new BattleEngine($skillCatalog, new DropGenerator($rootDir . '/data/drop', $game['drop']), $game),
            $calculator,
            $mapCatalog,
            $golden,
            $battleRepo,
            $characterRepo,
            $accountRepo,
            $itemRepo,
            new PresenceRepository($pdo),
            $clock,
            $rng,
            $game,
            $questCatalog,
            $worldBosses,
            $events,
            $party,
        );
        $shopCatalog = new ShopCatalog($rootDir . '/data/shops', $game['shop_quality']);
        $duelRepo = new DuelRepository($pdo, (int) $game['pvp']['expire_seconds']);
        $duelService = new DuelService(
            $duelRepo,
            new DuelEngine($skillCatalog, (int) $game['pvp']['max_rounds']),
            $characterRepo,
            $accountRepo,
            $itemRepo,
            $calculator,
            $transaction,
            $clock,
            $rng,
            (int) $game['pvp']['max_zen'],
            $notifications,
        );
        $inventoryService = new InventoryService($itemRepo, $characterRepo, $battleRepo, $calculator, $skillCatalog, $transaction, $rng, $duelRepo, $clock);
        $shopService = new ShopService($shopCatalog, $itemRepo, $accountRepo, $battleRepo, $transaction, $clock);
        $npcService = new NpcService(
            new SpecialNpcs(new BoxOpener($rootDir . '/data/drop')),
            $itemRepo,
            $accountRepo,
            $battleRepo,
            $transaction,
            $clock,
            $rng,
        );
        $questService = new QuestService($questCatalog, $characterRepo, $itemRepo, $battleRepo, $calculator, $transaction, $clock);

        $newsRepo = new NewsRepository($pdo);
        $home = new HomeController($view, $characterRepo, $newsRepo);
        $map = new MapController($view, $mapCatalog, $golden, $battleService, $clock, $game, $worldBosses, $events, $party);
        $battle = new BattleController($view, $session, $battleService, $skillCatalog, $itemDescriber, $translator);
        $npc = new NpcController($view, $session, $npcService, $questService, $itemRepo, $itemDescriber, $battleService);
        $community = new CommunityController(
            $view,
            $session,
            new StoreService($itemRepo, $accountRepo, $battleRepo, $transaction, $notifications),
            new GuildService(new GuildRepository($pdo), $characterRepo, $accountRepo, $transaction, $clock, $notifications),
            new ChatService($pdo, $clock),
            $characterRepo,
            $itemRepo,
            $itemDescriber,
            $battleService,
            $translator,
            $clock,
        );
        $pvp = new PvpController($view, $session, $duelService, $itemRepo, $itemDescriber, $skillCatalog, $battleService, (int) $game['pvp']['max_zen']);
        $evolution = new EvolutionController($view, $session, new EvolutionService($characterRepo, $accountRepo, $battleRepo, $transaction, $game['evolution']));
        $inventory = new InventoryController(
            $view,
            $session,
            $inventoryService,
            $shopService,
            $shopCatalog,
            $itemRepo,
            $itemDescriber,
            $battleService,
            $translator,
        );
        $characters = new CharacterController($view, $session, $characterService, $skillCatalog, $translator, $battleService);
        $catalog = new CatalogController($view, $itemCatalog, $itemDescriber, $skillCatalog, $translator);
        $auth = new AuthController($view, $session, $accounts, $rememberMe);
        $account = new AccountController($view, $session, $accounts, $rememberMe);

        $app = AppFactory::create();
        $responses = $app->getResponseFactory();
        $guest = new RequireLogin($responses, loggedIn: false);
        $member = new RequireLogin($responses, loggedIn: true);

        $firstRun = new FirstRun($pdo, $accountRepo, $accounts, new LoginAttemptRepository($pdo), $clock);
        $setup = new SetupController($view, $session, $firstRun);
        $app->get('/setup', $setup->show(...));
        $app->post('/setup', $setup->create(...));

        $app->get('/', $home->index(...));
        $app->get('/register', $auth->showRegister(...))->add($guest);
        $app->post('/register', $auth->register(...))->add($guest);
        $app->get('/login', $auth->showLogin(...))->add($guest);
        $app->post('/login', $auth->login(...))->add($guest);
        $app->post('/logout', $auth->logout(...));
        $app->get('/account', $account->show(...))->add($member);
        $app->post('/account/email', $account->updateEmail(...))->add($member);
        $app->post('/account/password', $account->updatePassword(...))->add($member);
        $app->post('/locale', $account->switchLocale(...));
        $app->post('/theme', $account->switchTheme(...));

        $app->get('/characters', $characters->index(...))->add($member);
        $app->post('/characters', $characters->create(...))->add($member);
        $app->post('/characters/stats', $characters->addStat(...))->add($member);
        $app->post('/characters/reset', $characters->reset(...))->add($member);
        $app->post('/characters/{id:[0-9]+}/select', $characters->select(...))->add($member);
        $app->post('/characters/{id:[0-9]+}/delete', $characters->delete(...))->add($member);

        $app->get('/map', $map->world(...));
        $app->get('/map/{map}', $map->zone(...));
        $app->get('/battle', $battle->show(...))->add($member);
        $app->post('/battle/enter', $battle->enter(...))->add($member);
        $app->post('/battle/enter-event', $battle->enterEvent(...))->add($member);
        $app->post('/battle/act', $battle->act(...))->add($member);
        $app->post('/battle/next', $battle->next(...))->add($member);
        $app->post('/battle/pick', $battle->pick(...))->add($member);
        $app->post('/battle/leave', $battle->leave(...))->add($member);

        $app->get('/inventory', $inventory->inventory(...))->add($member);
        $app->post('/inventory/move', $inventory->move(...))->add($member);
        $app->post('/inventory/use', $inventory->use(...))->add($member);
        $app->post('/inventory/jewel', $inventory->jewel(...))->add($member);
        $app->get('/vault', $inventory->vault(...))->add($member);
        $app->post('/shops/sell', $inventory->sell(...))->add($member);
        $app->get('/shops[/{id}]', $inventory->shop(...))->add($member);
        $app->post('/shops/{id}/buy', $inventory->buy(...))->add($member);

        $master = new MasterController($view, $session, $characterRepo);
        $app->get('/master', $master->show(...))->add($member);
        $app->post('/master', $master->learn(...))->add($member);
        $partyController = new PartyController($session, $party);
        $app->post('/party', $partyController->act(...))->add($member);
        $app->get('/npc/sebina', $evolution->show(...))->add($member);
        $app->post('/npc/sebina', $evolution->talk(...))->add($member);
        $app->get('/npc/marlon', $evolution->showMarlon(...))->add($member);
        $app->post('/npc/marlon', $evolution->talkMarlon(...))->add($member);
        $app->get('/npc/devin', $evolution->showDevin(...))->add($member);
        $app->post('/npc/devin', $evolution->talkDevin(...))->add($member);
        $app->get('/npc[/{id}]', $npc->show(...))->add($member);
        $app->post('/npc/{id}/mix', $npc->mix(...))->add($member);
        $app->get('/quests', $npc->quests(...))->add($member);
        $app->post('/quests', $npc->questAction(...))->add($member);

        $app->get('/store', $community->store(...))->add($member);
        $app->post('/store', $community->storeAction(...))->add($member);
        $app->get('/market', $community->market(...))->add($member);
        $app->post('/market/buy', $community->buy(...))->add($member);
        $app->get('/guild', $community->guild(...))->add($member);
        $app->post('/guild', $community->guildAction(...))->add($member);
        $app->get('/chat', $community->chat(...))->add($member);
        $app->post('/chat', $community->chat(...))->add($member);
        $app->get('/pvp', $pvp->show(...))->add($member);
        $app->post('/pvp', $pvp->action(...))->add($member);
        $app->get('/pvp/{id:[0-9]+}', $pvp->report(...))->add($member);
        $app->get('/ranking', $community->ranking(...));
        $app->get('/online', $community->online(...));

        // foro y publicaciones
        $forumService = new ForumService($pdo, $notifications);
        $forum = new ForumController($view, $session, $forumService, $newsRepo, $clock);
        $categories = implode('|', ForumService::CATEGORIES);
        $app->get('/news/{id:[0-9]+}', $forum->news(...));
        $app->get('/forum', $forum->index(...));
        $app->get('/forum/{category:' . $categories . '}', $forum->category(...));
        $app->get('/forum/{category:' . $categories . '}/new', $forum->newThread(...))->add($member);
        $app->post('/forum/{category:' . $categories . '}/new', $forum->createThread(...))->add($member);
        $app->get('/forum/thread/{id:[0-9]+}', $forum->thread(...));
        $app->post('/forum/thread/{id:[0-9]+}/reply', $forum->reply(...))->add($member);
        $requireAdmin = new RequireAdmin();
        $app->post('/forum/moderate', $forum->moderate(...))->add($requireAdmin);

        // panel de administración
        $admin = new AdminController($view, $session, $gameSettings, $newsRepo, $accountRepo, $clock, $gameDefaults, $rootDir . '/public/uploads/news');
        $app->get('/admin', $admin->index(...))->add($requireAdmin);
        $app->get('/admin/settings[/{group:[a-z]+}]', $admin->settings(...))->add($requireAdmin);
        $app->post('/admin/settings/{group:[a-z]+}', $admin->saveSettings(...))->add($requireAdmin);
        $app->get('/admin/news', $admin->newsList(...))->add($requireAdmin);
        $app->get('/admin/news/new', $admin->newsForm(...))->add($requireAdmin);
        $app->post('/admin/news/new', $admin->newsSave(...))->add($requireAdmin);
        $app->get('/admin/news/{id:[0-9]+}', $admin->newsForm(...))->add($requireAdmin);
        $app->post('/admin/news/{id:[0-9]+}', $admin->newsSave(...))->add($requireAdmin);
        $app->get('/admin/accounts', $admin->accounts(...))->add($requireAdmin);
        $adminAccounts = new AdminAccountController(
            $view,
            $session,
            $accountRepo,
            $characterRepo,
            new AccountEditor($pdo, $accountRepo, $characterRepo, $itemRepo, $battleRepo, new PresenceRepository($pdo), $itemCatalog),
            $itemCatalog,
            $forumService,
            $clock,
        );
        $app->get('/admin/accounts/{id:[0-9]+}', $adminAccounts->show(...))->add($requireAdmin);
        $app->post('/admin/accounts/{id:[0-9]+}/edit', $adminAccounts->action(...))->add($requireAdmin);
        $app->get('/admin/forum', $adminAccounts->forum(...))->add($requireAdmin);
        $app->post('/admin/accounts/{id:[0-9]+}', $admin->accountAction(...))->add($requireAdmin);

                $notificationController = new NotificationController($view, $notifications);
        $app->get('/notifications', $notificationController->index(...))->add($member);
        $app->get('/notifications/count', $notificationController->count(...))->add($member);
        $app->post('/notifications/clear', $notificationController->clear(...))->add($member);

        $app->get('/items[/{type}]', $catalog->items(...));
        $app->get('/skills', $catalog->skills(...));

        // Slim ejecuta primero el último middleware agregado: el orden real es
        // errores → sesión → cuenta → personaje activo → idioma → CSRF → ruteo.
        $app->addRoutingMiddleware();
        // corre justo antes del ruteo: con sesión, cuenta e idioma ya resueltos y el CSRF verificado
        $app->add(new FirstRunMiddleware($firstRun, $responses));
        $app->add(new CsrfMiddleware($session, $view, $responses));
        $app->add(new LocaleMiddleware());
        $app->add(new CharacterMiddleware($characterService, $characterRepo, $clock, $duelRepo, $battleRepo, $notifications));
        $app->add(new AuthMiddleware($session, $accountRepo, $rememberMe));
        $app->add(new SessionMiddleware($session, $config->trustProxy));

        $errors = $app->addErrorMiddleware(false, true, true);
        $errorPage = static function (int $status, string $titleKey, string $messageKey) use ($view, $responses) {
            return static fn (ServerRequestInterface $request): ResponseInterface => $view->render(
                $responses->createResponse(),
                $request,
                'error.twig',
                ['title_key' => $titleKey, 'message_key' => $messageKey],
                $status,
            );
        };
        $errors->setErrorHandler(HttpNotFoundException::class, $errorPage(404, 'error.404_title', 'error.404'));
        $errors->setErrorHandler(HttpMethodNotAllowedException::class, $errorPage(405, 'error.404_title', 'error.404'));
        $errors->setDefaultErrorHandler(
            static function (ServerRequestInterface $request, Throwable $e) use ($errorPage): ResponseInterface {
                error_log((string) $e);

                return $errorPage(500, 'error.500_title', 'error.500')($request);
            }
        );

        return $app;
    }
}
