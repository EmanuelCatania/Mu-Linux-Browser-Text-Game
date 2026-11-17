<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Battle\Battle;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\CharacterService;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CharacterController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly CharacterService $characters,
        private readonly SkillCatalog $skills,
        private readonly Translator $translator,
        private readonly BattleService $battles,
    ) {
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page($request, $response);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);

        try {
            $this->characters->create(self::account($request), $form('name'), (int) $form('class'));
        } catch (ValidationException $e) {
            return $this->page($request, $response, [
                'errors' => $e->errors,
                'old' => ['name' => $form('name'), 'class' => (int) $form('class')],
            ], 422);
        }

        return $this->done($response, 'flash.character_created');
    }

    /** @param array{id: string} $args */
    public function select(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->attempt(
            $request,
            $response,
            fn () => $this->characters->select(self::account($request), (int) $args['id']),
            'flash.character_selected',
        );
    }

    /** @param array{id: string} $args */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->attempt(
            $request,
            $response,
            fn () => $this->characters->delete(self::account($request), (int) $args['id']),
            'flash.character_deleted',
        );
    }

    public function addStat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        $stat = $form('stat');

        // Desde la pantalla de inventario se vuelve ahí mismo, para repartir puntos mirando el equipo.
        if ($form('return') === 'inventory') {
            try {
                $this->characters->addStatPoint(self::account($request), $stat);
                $this->session->flash('ok', 'flash.point_added');
            } catch (ValidationException $e) {
                $this->session->flash('error', $e->errors[0] ?? 'error.no_points');
            }

            return $response->withStatus(302)->withHeader('Location', '/inventory');
        }

        return $this->attempt(
            $request,
            $response,
            fn () => $this->characters->addStatPoint(self::account($request), $stat),
            'flash.point_added',
        );
    }

    public function reset(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->attempt($request, $response, function () use ($request): void {
            $active = $request->getAttribute('character');
            if ($active instanceof Character && $this->battles->current($active) !== null) {
                throw new ValidationException(['inventory.error_in_battle']);
            }
            $this->characters->reset(self::account($request));
        }, 'flash.reset_done');
    }

    private function attempt(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $action,
        string $flash,
    ): ResponseInterface {
        try {
            $action();
        } catch (ValidationException $e) {
            return $this->page($request, $response, ['errors' => $e->errors], 422);
        }

        return $this->done($response, $flash);
    }

    private function done(ResponseInterface $response, string $flash): ResponseInterface
    {
        $this->session->flash('ok', $flash);

        return $response->withStatus(302)->withHeader('Location', '/characters');
    }

    /** @param array<string, mixed> $extra */
    private function page(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $extra = [],
        int $status = 200,
    ): ResponseInterface {
        $account = self::account($request);
        $locale = (string) $request->getAttribute('locale');
        $active = $request->getAttribute('character');

        $skills = [];
        if ($active instanceof Character) {
            foreach ($active->skills as $code) {
                $skills[] = [
                    'code' => $code,
                    'name' => $this->skills->name($code),
                    'lines' => $this->skills->describe($code, $this->translator, $locale),
                ];
            }
        }

        $classes = [];
        $open = $this->characters->creatable($account);
        foreach ([...CharacterClass::CREATABLE, ...array_map(CharacterClass::from(...), array_keys(CharacterClass::UNLOCKABLE))] as $class) {
            $classes[] = [
                'id' => $class->value,
                'label' => $class->label(),
                'stats' => $class->startingStats(),
                'unlock_level' => in_array($class, $open, true) ? 0 : CharacterClass::UNLOCKABLE[$class->value],
            ];
        }

        return $this->view->render($response, $request, 'characters.twig', $extra + [
            'list' => $this->characters->listFor($account),
            'max' => CharacterService::MAX_PER_ACCOUNT,
            'reset' => ['level' => Battle::MAX_LEVEL, 'zen' => $this->characters->resetZen(), 'enabled' => $this->characters->resetEnabled()],
            'classes' => $classes,
            'stats' => $active instanceof Character ? $active->class->stats() : Character::STATS,
            'active_skills' => $skills,
            'derived' => $active instanceof Character ? $this->battles->stats($active) : null,
            'in_battle' => $active instanceof Character && $this->battles->current($active) !== null,
        ], $status);
    }

    private static function account(ServerRequestInterface $request): Account
    {
        $account = $request->getAttribute('account');
        if (!$account instanceof Account) {
            throw new \LogicException('Ruta de personajes sin cuenta: falta el filtro de login.');
        }

        return $account;
    }
}
