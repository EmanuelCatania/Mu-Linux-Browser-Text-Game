<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\AccountRepository;
use Mu\Data\NewsRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Admin\GameSettings;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;

/** Panel de administración: ajustes del juego, publicaciones de la portada y cuentas. */
final class AdminController
{
    private const int PAGE_SIZE = 25;
    private const int IMAGE_MAX_BYTES = 3 * 1024 * 1024;
    /** Tipo de imagen detectado → extensión con la que se guarda. */
    private const array IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

    /**
     * @param array<string, mixed> $defaults lo que devuelve data/game.php, sin los cambios del panel
     * @param string               $uploadDir carpeta pública donde se guardan las imágenes (public/uploads/news)
     */
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly GameSettings $settings,
        private readonly NewsRepository $news,
        private readonly AccountRepository $accounts,
        private readonly Clock $clock,
        private readonly array $defaults,
        private readonly string $uploadDir,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'admin/index.twig', [
            'counts' => [
                'news' => $this->news->count(false),
                'accounts' => $this->accounts->countSearch(''),
                'settings' => count($this->settings->overrides()),
            ],
        ]);
    }

    // --- ajustes del juego ---

    /** @param array{group?: string} $args */
    public function settings(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $group = $args['group'] ?? GameSettings::groups()[0];
        if (!in_array($group, GameSettings::groups(), true)) {
            throw new HttpNotFoundException($request);
        }

        $invalid = $this->session->get('admin_invalid');
        $this->session->remove('admin_invalid');

        return $this->view->render($response, $request, 'admin/settings.twig', [
            'groups' => GameSettings::groups(),
            'group' => $group,
            'fields' => $this->settings->fields($this->defaults, $group),
            'invalid' => is_array($invalid) ? $invalid : [],
        ]);
    }

    /** @param array{group: string} $args */
    public function saveSettings(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $group = $args['group'];
        if (!in_array($group, GameSettings::groups(), true)) {
            throw new HttpNotFoundException($request);
        }
        $body = (array) $request->getParsedBody();
        if (($body['do'] ?? '') === 'reset') {
            $this->settings->reset($group);
            $this->session->flash('ok', 'admin.settings_reset');
        } else {
            // los nombres de campo llevan la ruta con "__" en lugar de puntos (PHP cambia los puntos por "_")
            $form = [];
            foreach ($body as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $form[str_replace('__', '.', $name)] = $value;
                }
            }
            $invalid = $this->settings->save($this->defaults, $form, $group, $this->clock->now());
            if ($invalid === []) {
                $this->session->flash('ok', 'admin.settings_saved');
            } else {
                $this->session->set('admin_invalid', $invalid);
                $this->session->flash('error', 'admin.settings_invalid');
            }
        }

        return self::redirect($response, '/admin/settings/' . $group);
    }

    // --- publicaciones ---

    public function newsList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->view->render($response, $request, 'admin/news.twig', ['posts' => $this->news->page(200, 0, false)]);
    }

    /** @param array{id?: string} $args */
    public function newsForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $post = isset($args['id']) ? ($this->news->find((int) $args['id']) ?? throw new HttpNotFoundException($request)) : null;

        return $this->view->render($response, $request, 'admin/news_edit.twig', ['post' => $post]);
    }

    /** @param array{id?: string} $args */
    public function newsSave(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $account = $request->getAttribute('account');
        assert($account instanceof Account);
        $post = isset($args['id']) ? ($this->news->find((int) $args['id']) ?? throw new HttpNotFoundException($request)) : null;
        $body = (array) $request->getParsedBody();
        $now = $this->clock->now();

        if ($post !== null && ($body['do'] ?? '') === 'delete') {
            $this->news->delete($post['id']);
            $this->deleteImage($post['image']);
            $this->session->flash('ok', 'admin.news_deleted');

            return self::redirect($response, '/admin/news');
        }

        $title = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($body['title'] ?? '')) ?? '');
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) ($body['body'] ?? '')));
        $published = isset($body['published']);
        $back = $post === null ? '/admin/news/new' : '/admin/news/' . $post['id'];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 120 || $text === '' || mb_strlen($text) > 20000) {
            $this->session->flash('error', 'admin.news_invalid');

            return self::redirect($response, $back);
        }

        $image = $post['image'] ?? null;
        $upload = $request->getUploadedFiles()['image'] ?? null;
        $stored = $upload instanceof UploadedFileInterface ? $this->storeImage($upload) : null;
        if (is_array($stored)) {
            $this->session->flash('error', $stored[0]);

            return self::redirect($response, $back);
        }
        if ($stored !== null || isset($body['remove_image'])) {
            $this->deleteImage($image);
            $image = $stored;
        }

        if ($post === null) {
            $this->news->insert($account->id, $title, $text, $image, $published, $now);
        } else {
            $this->news->update($post['id'], $title, $text, $image, $published, $now);
        }
        $this->session->flash('ok', 'admin.news_saved');

        return self::redirect($response, '/admin/news');
    }

    // --- cuentas ---

    public function accounts(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $search = is_string($query['q'] ?? null) ? mb_substr(trim($query['q']), 0, 60) : '';
        $total = $this->accounts->countSearch($search);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = is_string($query['page'] ?? null) && ctype_digit($query['page']) ? max(1, min($pages, (int) $query['page'])) : 1;

        return $this->view->render($response, $request, 'admin/accounts.twig', [
            'rows' => $this->accounts->search($search, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'q' => $search,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ]);
    }

    /** @param array{id: string} $args */
    public function accountAction(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $me = $request->getAttribute('account');
        assert($me instanceof Account);
        $target = $this->accounts->findById((int) $args['id']) ?? throw new HttpNotFoundException($request);
        $body = (array) $request->getParsedBody();
        $do = (string) ($body['do'] ?? '');

        if ($target->id === $me->id && in_array($do, ['ban', 'unadmin'], true)) {
            $this->session->flash('error', 'admin.error_self');
        } else {
            match ($do) {
                'ban' => $this->accounts->setBanned($target->id, $this->clock->now()),
                'unban' => $this->accounts->setBanned($target->id, null),
                'admin' => $this->accounts->setAdmin($target->id, true),
                'unadmin' => $this->accounts->setAdmin($target->id, false),
                'zen' => ctype_digit((string) ($body['zen'] ?? '')) && strlen((string) $body['zen']) <= 10
                    ? $this->accounts->setZen($target->id, min(2_000_000_000, (int) $body['zen']))
                    : null,
                default => null,
            };
            $this->session->flash('ok', 'admin.account_saved');
        }
        $back = (string) ($body['return'] ?? '');

        return self::redirect($response, preg_match('#^/admin/(accounts|forum)(/[0-9]+)?(\?[A-Za-z0-9=&%_.-]*)?$#', $back) === 1 ? $back : '/admin/accounts');
    }

    /**
     * Guarda la imagen subida con un nombre al azar.
     *
     * @return string|array{string}|null nombre del archivo, [clave de error], o null si no se subió nada
     */
    private function storeImage(UploadedFileInterface $file): string|array|null
    {
        if ($file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if ($file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) > self::IMAGE_MAX_BYTES) {
            return ['admin.error_image_size'];
        }
        $path = $file->getStream()->getMetadata('uri');
        $info = is_string($path) ? @getimagesize($path) : false;
        $extension = $info === false ? null : (self::IMAGE_TYPES[$info['mime']] ?? null);
        if ($extension === null) {
            return ['admin.error_image_type'];
        }
        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true)) {
            return ['admin.error_image_save'];
        }
        $name = bin2hex(random_bytes(12)) . '.' . $extension;
        try {
            $file->moveTo($this->uploadDir . '/' . $name);
        } catch (\RuntimeException) {
            return ['admin.error_image_save'];
        }

        return $name;
    }

    private function deleteImage(?string $name): void
    {
        // sólo nombres generados por storeImage: nada de rutas
        if ($name !== null && preg_match('/^[0-9a-f]{24}\.(jpg|png|gif|webp)$/', $name) === 1) {
            @unlink($this->uploadDir . '/' . $name);
        }
    }

    private static function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $to);
    }
}
