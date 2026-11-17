<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Data\CharacterRepository;
use Mu\Data\NewsRepository;
use Mu\Http\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HomeController
{
    /** Publicaciones por página en la portada. */
    private const int NEWS_PER_PAGE = 5;

    public function __construct(
        private readonly View $view,
        private readonly CharacterRepository $characters,
        private readonly ?NewsRepository $news = null,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $total = $this->news?->count() ?? 0;
        $pages = max(1, (int) ceil($total / self::NEWS_PER_PAGE));
        $requested = $request->getQueryParams()['page'] ?? '1';
        $page = is_string($requested) && ctype_digit($requested) && strlen($requested) < 5 ? max(1, min($pages, (int) $requested)) : 1;

        return $this->view->render($response, $request, 'home.twig', [
            'top10' => $this->characters->ranking([], 'experience', 10, 0)['rows'],
            'posts' => $this->news?->page(self::NEWS_PER_PAGE, ($page - 1) * self::NEWS_PER_PAGE) ?? [],
            'page' => $page,
            'pages' => $pages,
        ]);
    }
}
