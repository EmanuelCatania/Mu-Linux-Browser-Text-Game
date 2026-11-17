<?php

declare(strict_types=1);

namespace Mu\Http\Controller;

use Mu\Domain\Battle\Battle;
use Mu\Domain\Battle\BattleEngine;
use Mu\Domain\Battle\BattleException;
use Mu\Domain\Battle\BattleService;
use Mu\Domain\Character\Character;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemDescriber;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\World\Monster;
use Mu\Http\Session;
use Mu\Http\View;
use Mu\Support\Translator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BattleController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $session,
        private readonly BattleService $battles,
        private readonly SkillCatalog $skills,
        private readonly ItemDescriber $describer,
        private readonly Translator $translator,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return self::redirect($response, '/characters');
        }
        $battle = $this->battles->current($character);
        if ($battle === null) {
            return self::redirect($response, '/map');
        }

        $locale = (string) $request->getAttribute('locale');
        $stats = $this->battles->stats($character);
        $t = fn (string $key, array $params = []): string => $this->translator->translate($key, $locale, $params);

        $actions = [['code' => BattleEngine::ATTACK, 'name' => $t('battle.attack'), 'mana' => 0, 'self' => false, 'image' => 'attack']];
        foreach ($stats->skills as $code) {
            $skill = $this->skills->all()[$code] ?? null;
            if ($skill === null || !isset($skill['battle'])) {
                continue;
            }
            $actions[] = [
                'code' => $code,
                'name' => $skill['name'],
                'mana' => $skill['mana'],
                'self' => in_array($skill['battle'][0], ['effect', 'heal'], true),
                'image' => 'skill' . $code,
            ];
        }
        $chosen = $this->session->get('battle_action', BattleEngine::ATTACK);
        if (!in_array($chosen, array_column($actions, 'code'), true)) {
            $chosen = BattleEngine::ATTACK;
        }

        $enemies = [];
        foreach ($battle->enemies as $i => $enemy) {
            $item = null;
            if ($enemy['item'] !== null) {
                $item = $this->describer->describe(Item::parse($enemy['item']), $locale, $character)
                    + ['image' => Item::parse($enemy['item'])->imagePath()];
            }
            $enemies[] = [
                'index' => $i,
                'label' => Monster::label($enemy['name']),
                'effect' => $enemy['effect'] === '' ? null : $this->skills->name($enemy['effect']),
                'percent' => $enemy['hp'] > 0 ? max(0, (int) floor($enemy['hpCur'] * 100 / $enemy['hp'])) : 0,
                'item' => $item,
            ] + $enemy;
        }

        $next = Battle::experienceFor($battle->level);
        $previous = Battle::experienceFor($battle->level - 1);
        $hpMax = $this->battles->hpMax($battle, $stats);

        return $this->view->render($response, $request, 'battle.twig', [
            'battle' => $battle,
            'map_label' => Monster::label($battle->map),
            'spot_number' => (int) substr($battle->spot, 4),
            'stats' => $stats,
            'hp_max' => $hpMax,
            'hp_percent' => self::percent($battle->hp, $hpMax),
            'mp_percent' => self::percent($battle->mp, $stats->mpMax),
            'exp_percent' => self::percent($battle->experience - $previous, $next - $previous),
            'exp_next' => $next,
            'effect' => $battle->effect === '' ? null : $this->skills->name($battle->effect),
            'actions' => $actions,
            'chosen' => $chosen,
            'enemies' => $enemies,
            'log' => $this->logLines($battle, $t),
            'summon' => $battle->summon === '' ? null : [
                'name' => $this->summonName($battle->summon),
                'hp' => $battle->summonHp,
                'max' => (int) ($this->skills->all()[$battle->summon]['life'] ?? 0),
            ],
            'players' => $this->battles->playersAt($battle->map, $battle->spot),
            'potions' => $this->battles->potions($character),
        ]);
    }

    public function enter(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);

        return $this->attempt($request, $response, function (Character $c) use ($form): void {
            $this->session->remove('battle_action');
            $this->battles->enter($c, $form('map'), $form('spot'));
        }, '/map/' . rawurlencode($form('map')));
    }

    public function enterEvent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->attempt($request, $response, function (Character $c): void {
            $this->session->remove('battle_action');
            $this->battles->enterEvent($c);
        }, '/map');
    }

    public function act(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $form = AuthController::form($request);
        $target = $form('target');
        $potion = $form('potion');

        return $this->attempt($request, $response, function (Character $c) use ($form, $target, $potion): void {
            $this->session->set('battle_action', $form('action'));
            if ($potion !== '') {
                // las pociones siempre son sobre uno mismo
                $this->battles->act($c, $potion, null);

                return;
            }
            $this->battles->act($c, $form('action'), $target === 'self' ? null : (ctype_digit($target) ? (int) $target : -1));
        });
    }

    public function next(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->attempt($request, $response, fn (Character $c) => $this->battles->nextRound($c));
    }

    public function pick(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $enemy = AuthController::form($request)('enemy');

        return $this->attempt($request, $response, function (Character $c) use ($enemy): void {
            $this->battles->pick($c, ctype_digit($enemy) ? (int) $enemy : -1);
            $this->session->flash('ok', 'battle.item_picked');
        });
    }

    public function leave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $character = $request->getAttribute('character');
        $back = '/map';
        if ($character instanceof Character) {
            $battle = $this->battles->current($character);
            if ($battle !== null) {
                $back = '/map/' . rawurlencode($battle->map);
            }
            $this->battles->leave($character);
        }
        $this->session->remove('battle_action');

        return self::redirect($response, $back);
    }

    /** Ejecuta una acción de batalla y vuelve a /battle; si falla, deja el motivo como aviso. */
    private function attempt(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $action,
        string $onErrorBack = '/battle',
    ): ResponseInterface {
        $character = $request->getAttribute('character');
        if (!$character instanceof Character) {
            return self::redirect($response, '/characters');
        }

        try {
            $action($character);
        } catch (BattleException $e) {
            if ($e->getMessage() === 'battle.event_over') {
                // no es un error: se terminó el evento y ya se guardó lo ganado
                $this->session->flash('ok', $e->getMessage());

                return self::redirect($response, '/map');
            }
            $this->session->flash('error', $e->getMessage());

            return self::redirect($response, $e->getMessage() === 'battle.error_none' ? '/map' : $onErrorBack);
        }

        return self::redirect($response, '/battle');
    }

    /**
     * @param \Closure(string, array<string, int|string>=): string $t
     *
     * @return list<array{text: string, class: string}>
     */
    /** "Summon Goblin" → "Goblin". */
    private function summonName(string $skill): string
    {
        return preg_replace('/^Summon /', '', $this->skills->name($skill)) ?? '';
    }

    private function logLines(Battle $battle, \Closure $t): array
    {
        $name = static fn (?int $i): string => $i === null
            ? $t('battle.you')
            : Monster::label($battle->enemies[$i]['name'] ?? '?') . ' #' . ($i + 1);

        $lines = [];
        foreach ($battle->log as $e) {
            $tag = match ($e['tag'] ?? '') {
                'exc' => ' ' . $t('battle.tag_excellent'),
                'crt' => ' ' . $t('battle.tag_critical'),
                'ign' => ' ' . $t('battle.tag_ignore'),
                'exc ign' => ' ' . $t('battle.tag_excellent') . ' ' . $t('battle.tag_ignore'),
                'crt ign' => ' ' . $t('battle.tag_critical') . ' ' . $t('battle.tag_ignore'),
                default => '',
            };
            $lines[] = match ($e['type']) {
                'dmg' => ['text' => $t('battle.log_dmg', ['target' => $name($e['target']), 'n' => $e['n']]) . $tag, 'class' => 'log-player'],
                'miss' => ['text' => $t('battle.log_miss', ['target' => $name($e['target'])]), 'class' => 'log-player gray'],
                'heal' => ['text' => $t('battle.log_heal', ['target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'log-player green'],
                'effect' => ['text' => $t('battle.log_effect', ['target' => $name($e['target']), 'skill' => $this->skills->name($e['skill'])]), 'class' => 'log-player blue'],
                'no_mp' => ['text' => $t('battle.log_no_mp'), 'class' => 'log-player red'],
                'no_arrows' => ['text' => $t('battle.log_no_arrows'), 'class' => 'log-player red'],
                'mana' => ['text' => $t('battle.log_mana', ['n' => $e['n']]), 'class' => 'log-player green'],
                'antidote' => ['text' => $t('battle.log_antidote'), 'class' => 'log-player green'],
                'summon' => ['text' => $t('battle.log_summon', ['name' => $this->summonName($e['skill'])]), 'class' => 'log-player blue'],
                'summon_dmg' => ['text' => $t('battle.log_summon_dmg', ['name' => $this->summonName($e['skill']), 'target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'log-player'],
                'summon_hit' => ['text' => $t('battle.log_summon_hit', ['name' => $this->summonName($e['skill']), 'enemy' => $name($e['enemy']), 'n' => $e['n']]), 'class' => 'log-enemy'],
                'summon_dead' => ['text' => $t('battle.log_summon_dead', ['name' => $this->summonName($e['skill'])]), 'class' => 'red'],
                'raven_dmg' => ['text' => $t('battle.log_summon_dmg', ['name' => 'Dark Raven', 'target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'log-player'],
                'drain' => ['text' => $t('battle.log_drain', ['n' => $e['n']]), 'class' => 'log-player green'],
                'poison' => ['text' => $t('battle.log_poison', ['target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'purple'],
                'reflect' => ['text' => $t('battle.log_reflect', ['target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'yellow'],
                'enemy_dmg' => ['text' => $t('battle.log_enemy_dmg', ['enemy' => $name($e['enemy']), 'n' => $e['n']])
                    . ($e['resisted'] > 0 ? ' ' . $t('battle.log_resisted', ['n' => $e['resisted']]) : ''), 'class' => 'log-enemy'],
                'enemy_miss' => ['text' => $t('battle.log_enemy_miss', ['enemy' => $name($e['enemy'])]), 'class' => 'log-enemy gray'],
                'kill' => ['text' => $t('battle.log_kill', ['enemy' => $name($e['enemy']), 'exp' => $e['exp'], 'zen' => $e['zen']]), 'class' => 'green bold'],
                'quest_item' => ['text' => $t('battle.log_quest_item', ['item' => $e['item']]), 'class' => 'yellow bold'],
                'quest_kill' => ['text' => $t('battle.log_quest_kill', ['enemy' => $name($e['enemy']), 'n' => $e['left']]), 'class' => 'yellow'],
                'combo' => ['text' => $t('battle.log_combo', ['target' => $name($e['target']), 'n' => $e['n']]), 'class' => 'log-player yellow bold'],
                'master_up' => ['text' => $t('battle.log_master_up', ['n' => $e['n']]), 'class' => 'yellow bold'],
                'level_up' => ['text' => $t('battle.log_level_up', ['n' => $e['n']]), 'class' => 'yellow bold'],
                'defeat' => ['text' => $t('battle.log_defeat'), 'class' => 'red bold'],
                default => ['text' => (string) $e['type'], 'class' => ''],
            };
        }

        return $lines;
    }

    private static function percent(int $value, int $max): int
    {
        return $max > 0 ? max(0, min(100, (int) floor($value * 100 / $max))) : 0;
    }

    private static function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $to);
    }
}
