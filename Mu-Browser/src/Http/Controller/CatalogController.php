<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Character\Character;
use Mu\Domain\Item\ItemCatalog;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Http\View;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/** Páginas de consulta: base de ítems y habilidades. Son públicas. */
final class CatalogController
{
    public function __construct(
        private readonly View $view,
        private readonly ItemCatalog $items,
        private readonly ItemDescriber $describer,
        private readonly SkillCatalog $skills,
        private readonly Translator $translator,
    ) {
    }

    /** @param array{type?: string} $args */
    public function items(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $type = $args['type'] ?? ItemCatalog::TYPES[0];
        if (!ItemCatalog::isType($type)) {
            throw new HttpNotFoundException($request);
        }

        $locale = (string) $request->getAttribute('locale');
        $character = $request->getAttribute('character');
        $character = $character instanceof Character ? $character : null;

        $cards = [];
        foreach ($this->items->ofType($type) as $item) {
            $cards[] = ['image' => $item->imagePath()] + $this->describer->describe($item, $locale, $character);
        }

        return $this->view->render($response, $request, 'catalog/items.twig', [
            'types' => ItemCatalog::TYPES,
            'type' => $type,
            'cards' => $cards,
        ]);
    }

    public function skills(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $locale = (string) $request->getAttribute('locale');

        $groups = array_fill_keys(SkillCatalog::GROUPS, []);
        foreach ($this->skills->all() as $code => $skill) {
            $groups[$skill['group']][] = [
                'code' => (string) $code,
                'name' => $skill['name'],
                'lines' => $this->skills->describe((string) $code, $this->translator, $locale),
            ];
        }

        return $this->view->render($response, $request, 'catalog/skills.twig', ['groups' => $groups]);
    }
}
