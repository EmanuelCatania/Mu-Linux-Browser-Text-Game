<?php

declare(strict_types=1);

namespace Mu\Tests\Domain;

use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Data\NewsRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Admin\GameSettings;
use Mu\Domain\Forum\ForumException;
use Mu\Domain\Forum\ForumService;
use Mu\Tests\Support\FixedClock;
use Mu\Tests\Support\TestDatabase;
use PDO;
use PHPUnit\Framework\TestCase;

/** Ajustes del juego desde el panel, publicaciones, suspensión de cuentas y foro. */
final class AdminForumTest extends TestCase
{
    private PDO $pdo;
    private FixedClock $clock;
    private AccountRepository $accounts;
    /** @var array<string, mixed> */
    private array $game;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FixedClock('2026-01-05 12:00:00');
        $this->accounts = new AccountRepository($this->pdo);
        $this->game = require dirname(__DIR__, 2) . '/data/game.php';
    }

    public function testEveryEditableSettingExistsInTheGameFile(): void
    {
        $settings = new GameSettings($this->pdo);
        foreach (GameSettings::groups() as $group) {
            foreach ($settings->fields($this->game, $group) as $field) {
                self::assertNotSame('', $field['default'], "{$field['path']} no está en data/game.php");
                self::assertFalse($field['changed']);
            }
        }
    }

    public function testSettingsAreValidatedSavedOverTheFileAndResettable(): void
    {
        $settings = new GameSettings($this->pdo);
        $form = static function (GameSettings $s, array $game, string $group, array $changes): array {
            $values = array_column($s->fields($game, $group), 'value', 'path');
            foreach ($values as $path => $value) {
                if (array_column($s->fields($game, $group), 'type', 'path')[$path] === 'bool' && $value === '0') {
                    unset($values[$path]); // una casilla sin marcar no llega
                }
            }

            return array_filter($changes + $values, static fn ($v): bool => $v !== null);
        };

        // inválidos: no se guarda nada
        self::assertSame(['golden.hours', 'golden.chance'], $settings->save($this->game, $form($settings, $this->game, 'events', ['golden.hours' => '0, 25', 'golden.chance' => 'mucho']), 'events', $this->clock->now()));
        self::assertSame([], $settings->overrides());

        self::assertSame([], $settings->save($this->game, $form($settings, $this->game, 'events', ['golden.hours' => '2, 14, 2', 'golden.chance' => '50']), 'events', $this->clock->now()));
        self::assertSame(['golden.hours' => [2, 14], 'golden.chance' => 50], $settings->overrides(), 'sólo se guarda lo que cambió');

        $game = $settings->apply($this->game);
        self::assertSame([[2, 14], 50, 4], [$game['golden']['hours'], $game['golden']['chance'], $game['world_boss']['chance']]);

        // rangos, listas de tres y casillas
        self::assertSame([], $settings->save($this->game, $form($settings, $this->game, 'quests', [
            'evolution.scroll_levels' => '40 - 55', 'evolution.devin_zen' => '1, 2, 3', 'reset.enabled' => '1',
        ]), 'quests', $this->clock->now()));
        $game = $settings->apply($this->game);
        self::assertSame([[40, 55], [1, 2, 3], true], [$game['evolution']['scroll_levels'], $game['evolution']['devin_zen'], $game['reset']['enabled']]);
        self::assertSame(['evolution.scroll_levels'], $settings->save($this->game, $form($settings, $this->game, 'quests', ['evolution.scroll_levels' => '60 - 40']), 'quests', $this->clock->now()));

        $settings->reset('events');
        self::assertArrayNotHasKey('golden.hours', $settings->overrides());
        self::assertArrayHasKey('evolution.devin_zen', $settings->overrides(), 'resetear una pestaña no toca las otras');

        // un valor viejo que ya no está en la lista de editables se ignora
        $this->pdo->exec("INSERT INTO settings (name, value, updated_at) VALUES ('shop_quality', '\"table\"', '2026-01-05 12:00:00')");
        self::assertSame('normal', $settings->apply($this->game)['shop_quality']);
    }

    public function testNewsListOnlyShowsPublishedPostsNewestFirst(): void
    {
        $news = new NewsRepository($this->pdo);
        $admin = $this->account('Admin');
        $news->insert($admin->id, 'Primera', 'Hola', null, true, $this->clock->now());
        $this->clock->advance('+1 hour');
        $draft = $news->insert($admin->id, 'Borrador', 'Todavía no', 'abc.jpg', false, $this->clock->now());
        $this->clock->advance('+1 hour');
        $news->insert($admin->id, 'Segunda', "Línea 1\nLínea 2", null, true, $this->clock->now());

        self::assertSame(['Segunda', 'Primera'], array_column($news->page(10, 0), 'title'));
        self::assertSame([2, 3], [$news->count(), $news->count(false)]);
        self::assertSame(['Segunda', 'Borrador', 'Primera'], array_column($news->page(10, 0, false), 'title'));
        self::assertSame(['Admin', 'abc.jpg'], [$news->find($draft)['author'], $news->find($draft)['image']]);

        $news->update($draft, 'Publicado', 'Ya está', null, true, $this->clock->now());
        self::assertSame(3, $news->count());
        $news->delete($draft);
        self::assertNull($news->find($draft));
    }

    public function testBannedAccountsCannotLogIn(): void
    {
        $service = new AccountService($this->accounts, new LoginAttemptRepository($this->pdo), $this->clock);
        $player = $service->register('Pepe', 'pepe@example.test', 'secreto123', 'secreto123', '10.0.0.1', 'es');
        self::assertSame($player->id, $service->authenticate('pepe', 'secreto123', '10.0.0.1')->id);

        $this->accounts->setBanned($player->id, $this->clock->now());
        self::assertTrue($this->accounts->findById($player->id)?->banned);
        try {
            $service->authenticate('pepe', 'secreto123', '10.0.0.1');
            self::fail('Una cuenta suspendida no entra');
        } catch (ValidationException $e) {
            self::assertSame(['error.banned'], $e->errors);
        }

        $this->accounts->setBanned($player->id, null);
        self::assertSame($player->id, $service->authenticate('pepe', 'secreto123', '10.0.0.1')->id);

        $this->accounts->setAdmin($player->id, true);
        $this->accounts->setZen($player->id, -50);
        $row = $this->accounts->search('pep', 10, 0)[0];
        self::assertSame([true, 0, false], [$row['is_admin'], $row['zen'], $row['banned']]);
        self::assertSame(0, $this->accounts->countSearch('zzz'));
    }

    public function testForumThreadsRepliesModerationAndLimits(): void
    {
        $notes = new \Mu\Data\NotificationRepository($this->pdo, $this->clock);
        $forum = new ForumService($this->pdo, $notes);
        $ana = $this->account('Ana');
        $admin = $this->account('Admin', true);
        $error = static function (callable $action): string {
            try {
                $action();
            } catch (ForumException $e) {
                return $e->getMessage();
            }
            self::fail('Se esperaba un error');
        };

        self::assertSame('forum.error_admin_only', $error(fn () => $forum->createThread($ana, 'news', 'Hola', 'Texto', $this->clock->now())));
        self::assertSame('forum.error_category', $error(fn () => $forum->createThread($ana, 'otra', 'Hola', 'Texto', $this->clock->now())));
        self::assertSame('forum.error_title', $error(fn () => $forum->createThread($ana, 'bugs', ' a ', 'Texto', $this->clock->now())));

        $id = $forum->createThread($ana, 'bugs', "No me deja\x07 comprar", "Paso 1\r\n\r\n\r\n\r\nPaso 2<b>", $this->clock->now());
        self::assertSame('No me deja comprar', $forum->thread($id)['title']);
        self::assertSame("Paso 1\n\nPaso 2<b>", $forum->posts($id, 1)[0]['body'], 'texto plano: el escape lo hace la plantilla');
        self::assertSame('forum.error_too_fast', $error(fn () => $forum->reply($ana, $id, 'Otra cosa', $this->clock->now())));

        $this->clock->advance('+1 minute');
        $forum->reply($admin, $id, 'Lo miramos', $this->clock->now());
        self::assertSame(['notify.forum_reply', ['name' => 'Admin', 'title' => 'No me deja comprar']], [$notes->latest($ana->id)[0]['key'], $notes->latest($ana->id)[0]['params']]);
        self::assertSame(0, $notes->unread($admin->id), 'responder no le avisa a quien responde');
        $posts = $forum->posts($id, 1);
        self::assertSame([['Ana', false, true], ['Admin', true, false]], array_map(static fn ($p) => [$p['author'], $p['author_admin'], $p['first']], $posts));
        self::assertSame([1, 2], [$forum->thread($id)['replies'], $forum->categories()[1]['posts']]);
        self::assertSame('bugs', $forum->categories()[1]['id']);
        self::assertSame('Admin', $forum->categories()[1]['last']['author']);

        $forum->setFlag($id, 'locked', true);
        $this->clock->advance('+1 minute');
        self::assertSame('forum.error_locked', $error(fn () => $forum->reply($ana, $id, 'Hola?', $this->clock->now())));
        $forum->reply($admin, $id, 'Cerrado', $this->clock->now());

        $forum->setFlag($id, 'pinned', true);
        $other = $forum->createThread($ana, 'bugs', 'Otro tema', 'Algo', $this->clock->now()->modify('+1 hour'));
        self::assertSame([$id, $other], array_column($forum->threads('bugs', 1), 'id'), 'los fijados van primero');

        self::assertNull($forum->deletePost($posts[1]['id']));
        self::assertSame(1, $forum->thread($id)['replies']);
        self::assertSame('bugs', $forum->deletePost($posts[0]['id']), 'borrar el primer mensaje borra el tema');
        self::assertNull($forum->thread($id));
        self::assertSame(1, $forum->countThreads('bugs'));
    }

    public function testAdminEditsCharactersAndTheirItems(): void
    {
        $characters = new \Mu\Data\CharacterRepository($this->pdo);
        $items = new \Mu\Data\ItemRepository($this->pdo);
        $battles = new \Mu\Data\BattleRepository($this->pdo);
        $root = dirname(__DIR__, 2);
        $editor = new \Mu\Domain\Admin\AccountEditor($this->pdo, $this->accounts, $characters, $items, $battles,
            new \Mu\Data\PresenceRepository($this->pdo), new \Mu\Domain\Item\ItemCatalog($root . '/data/items'));
        $owner = $this->account('Duenio');
        $other = $this->account('Otro');
        $knight = $characters->insert($owner->id, 'Caballero', \Mu\Domain\Character\CharacterClass::DarkKnight, $this->clock->now());
        $characters->insert($other->id, 'Ocupado', \Mu\Domain\Character\CharacterClass::DarkWizard, $this->clock->now());
        $battles->create($knight->id, new \Mu\Domain\Battle\Battle(), $this->clock->now());

        $form = ['name' => 'Ocupado', 'class' => '21', 'level' => '120', 'experience' => '5', 'points' => '10', 'strength' => '300',
            'agility' => '0', 'stamina' => '100', 'energy' => '50', 'command' => '0', 'fruits' => '0', 'resets' => '0',
            'evo_step' => '5', 'master_level' => '0', 'master_points' => '0'];
        self::assertSame(['agility', 'name'], $editor->updateCharacter($knight, $form), 'nada en cero ni nombres repetidos');
        self::assertSame(1, $characters->findById($knight->id)?->level, 'no se guardó nada');

        self::assertSame([], $editor->updateCharacter($knight, ['name' => 'Blade', 'agility' => '80'] + $form));
        $edited = $characters->findById($knight->id) ?? self::fail('sin personaje');
        self::assertSame(['Blade', \Mu\Domain\Character\CharacterClass::BladeKnight, 120, 300, 80, 5],
            [$edited->name, $edited->class, $edited->level, $edited->strength, $edited->agility, $edited->evoStep]);
        self::assertSame(\Mu\Domain\Battle\Battle::experienceFor(119), $edited->experience, 'la experiencia cae dentro del nivel');
        self::assertNull($battles->find($knight->id), 'lo saca de la batalla');

        $sword = $editor->giveItem($edited, '1/short_sword', 9, true, 4, 2, $this->clock->now());
        self::assertSame([9, 1, 4, 2], [$sword->level, $sword->luck, $sword->option, $sword->excellent]);
        $apple = $editor->giveItem($edited, 'F/apple', 9, true, 4, 2, $this->clock->now());
        self::assertSame([0, 0], [$apple->level, $apple->excellent], 'lo que no es equipo no lleva opciones');
        try {
            $editor->giveItem($edited, '1/inventada', 0, false, 0, 0, $this->clock->now());
            self::fail('Un ítem que no está en el catálogo no se da');
        } catch (ValidationException $e) {
            self::assertSame(['admin.error_item'], $e->errors);
        }

        $list = $editor->itemsOf($this->accounts->findById($owner->id) ?? self::fail('sin cuenta'));
        $ids = array_column($list['characters'][$knight->id]['inventory'], 'id');
        self::assertCount(2, $ids);
        self::assertFalse($editor->deleteItem($other, $ids[0]), 'sólo ítems de esa cuenta');
        self::assertTrue($editor->deleteItem($owner, $ids[0]));
        self::assertCount(1, $items->stored(\Mu\Domain\Inventory\Container::Inventory, $knight->id));

        $editor->updateAccount($owner, 'nuevo@example.test', 'clave-nueva-123');
        $updated = $this->accounts->findById($owner->id) ?? self::fail('sin cuenta');
        self::assertSame('nuevo@example.test', $updated->email);
        self::assertTrue(password_verify('clave-nueva-123', $updated->passwordHash));
        try {
            $editor->updateAccount($owner, 'otro@example.test', 'corta');
            self::fail('Se esperaba un error');
        } catch (ValidationException $e) {
            self::assertSame(['error.email_taken', 'error.password_length'], $e->errors);
        }

        $editor->deleteCharacter($edited);
        self::assertNull($characters->findById($knight->id));
    }

    public function testModerationListsTheLatestPostsByAuthor(): void
    {
        $forum = new ForumService($this->pdo);
        $ana = $this->account('Ana');
        $beto = $this->account('Beto');
        $id = $forum->createThread($ana, 'general', 'Hola', 'Primero', $this->clock->now());
        $this->clock->advance('+1 minute');
        $forum->reply($beto, $id, 'Segundo', $this->clock->now());
        $forum->setFlag($id, 'pinned', true);

        self::assertSame(['Segundo', 'Primero'], array_column($forum->recentPosts(1), 'body'));
        self::assertSame([[false], [true]], array_map(static fn ($p) => [$p['first']], $forum->recentPosts(1)));
        self::assertSame(['Primero'], array_column($forum->recentPosts(1, 'ana'), 'body'));
        self::assertSame([2, 1, 0], [$forum->countPosts(), $forum->countPosts('BETO'), $forum->countPosts('nadie')]);
        self::assertSame([[$id, true, false]], array_map(static fn ($t) => [$t['id'], $t['pinned'], $t['locked']], $forum->flaggedThreads()));
    }

    private function account(string $name, bool $admin = false): Account
    {
        $account = $this->accounts->insert($name, strtolower($name) . '@example.test', 'hash', 'es', '10.0.0.1', $this->clock->now());
        if ($admin) {
            $this->accounts->setAdmin($account->id, true);
        }

        return $this->accounts->findById($account->id) ?? self::fail('sin cuenta');
    }
}
