<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\NewsRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Forum\ForumException;
use Mu\Domain\Forum\ForumService;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Foro y publicaciones de la portada vistas de a una. */
final class ForumController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly ForumService $forum,
        private readonly NewsRepository $news,
        private readonly Clock $clock,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'forum/index.twig', ['categories' => $this->forum->categories()]);
    }

    /** @param array{category: string} $args */
    public function category(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $category = self::validCategory($request, $args['category']);
        $total = $this->forum->countThreads($category);
        [$page, $pages] = self::page($request, $total);

        return $this->view->render($response, $request, 'forum/category.twig', [
            'category' => $category,
            'threads' => $this->forum->threads($category, $page),
            'can_post' => $this->canOpen($request, $category),
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** @param array{id: string} $args */
    public function thread(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $thread = $this->forum->thread((int) $args['id']) ?? throw new HttpNotFoundException($request);
        [$page, $pages] = self::page($request, $thread['replies'] + 1);
        $posts = array_map(static fn (array $p): array => $p + [
            'class_label' => $p['class'] === null ? null : CharacterClass::tryFrom($p['class'])?->label(),
        ], $this->forum->posts($thread['id'], $page));

        return $this->view->render($response, $request, 'forum/thread.twig', [
            'thread' => $thread,
            'posts' => $posts,
            'page' => $page,
            'pages' => $pages,
            'old_body' => $this->session->get('forum_draft') ?? '',
        ] + self::clearDraft($this->session));
    }

    /** @param array{category: string} $args */
    public function newThread(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $category = self::validCategory($request, $args['category']);
        if (!$this->canOpen($request, $category)) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, $request, 'forum/new.twig', [
            'category' => $category,
            'old' => (array) ($this->session->get('forum_new') ?? []),
        ] + self::clearDraft($this->session));
    }

    /** @param array{category: string} $args */
    public function createThread(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $category = self::validCategory($request, $args['category']);
        $account = self::account($request);
        $form = AuthController::form($request);
        try {
            $id = $this->forum->createThread($account, $category, $form('title'), $form('body'), $this->clock->now());

            return self::redirect($response, '/forum/thread/' . $id);
        } catch (ForumException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->session->set('forum_new', ['title' => mb_substr($form('title'), 0, 200), 'body' => mb_substr($form('body'), 0, 6000)]);

            return self::redirect($response, '/forum/' . $category . '/new');
        }
    }

    /** @param array{id: string} $args */
    public function reply(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $form = AuthController::form($request);
        try {
            $page = $this->forum->reply(self::account($request), $id, $form('body'), $this->clock->now());

            return self::redirect($response, '/forum/thread/' . $id . '?page=' . $page . '#last');
        } catch (ForumException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->session->set('forum_draft', mb_substr($form('body'), 0, 6000));

            return self::redirect($response, '/forum/thread/' . $id . '#reply');
        }
    }

    /** Moderación (sólo administradores): fijar, cerrar o borrar un tema, o borrar un mensaje. */
    public function moderate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        $thread = (int) $form('thread');
        $back = '/forum/thread/' . $thread;
        switch ($form('do')) {
            case 'lock':
            case 'unlock':
                $this->forum->setFlag($thread, 'locked', $form('do') === 'lock');
                break;
            case 'pin':
            case 'unpin':
                $this->forum->setFlag($thread, 'pinned', $form('do') === 'pin');
                break;
            case 'delete_thread':
                $category = $this->forum->thread($thread)['category'] ?? null;
                $this->forum->deleteThread($thread);
                $back = $category === null ? '/forum' : '/forum/' . $category;
                break;
            case 'delete_post':
                $category = $this->forum->deletePost((int) $form('post'));
                if ($category !== null) {
                    $back = '/forum/' . $category;
                }
                break;
        }
        $this->session->flash('ok', 'forum.moderated');
        // desde el panel de moderación se vuelve al panel
        $return = $form('return');
        if (preg_match('#^/admin/forum(\?[A-Za-z0-9=&%_.-]*)?$#', $return) === 1) {
            $back = $return;
        }

        return self::redirect($response, $back);
    }

    /** @param array{id: string} $args */
    public function news(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $post = $this->news->find((int) $args['id']);
        $account = $request->getAttribute('account');
        if ($post === null || (!$post['published'] && !($account instanceof Account && $account->isAdmin))) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, $request, 'news/show.twig', ['post' => $post]);
    }

    private function canOpen(ServerRequestInterface $request, string $category): bool
    {
        $account = $request->getAttribute('account');

        return $account instanceof Account && (!in_array($category, ForumService::ADMIN_ONLY, true) || $account->isAdmin);
    }

    private static function validCategory(ServerRequestInterface $request, string $category): string
    {
        return in_array($category, ForumService::CATEGORIES, true) ? $category : throw new HttpNotFoundException($request);
    }

    /** @return array{int, int} página pedida (acotada) y cantidad de páginas */
    private static function page(ServerRequestInterface $request, int $total): array
    {
        $pages = max(1, (int) ceil($total / ForumService::PAGE_SIZE));
        $requested = $request->getQueryParams()['page'] ?? '1';
        $page = is_string($requested) && ctype_digit($requested) && strlen($requested) < 6 ? (int) $requested : 1;

        return [max(1, min($pages, $page)), $pages];
    }

    /** Lo que quedó guardado de un envío fallido se muestra una sola vez. @return array{} */
    private static function clearDraft(Session $session): array
    {
        $session->remove('forum_draft');
        $session->remove('forum_new');

        return [];
    }

    private static function account(ServerRequestInterface $request): Account
    {
        $account = $request->getAttribute('account');
        assert($account instanceof Account);

        return $account;
    }

    private static function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $to);
    }
}
