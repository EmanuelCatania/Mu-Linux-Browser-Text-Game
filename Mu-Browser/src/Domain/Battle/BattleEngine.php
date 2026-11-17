<?php

declare(strict_types=1);

namespace Mu\Domain\Battle;

use DateTimeImmutable;
use Mu\Domain\Character\MasterTree;
use Mu\Domain\Item\DropGenerator;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Domain\World\GameMap;
use Mu\Domain\World\Spot;
use Mu\Support\Rng;

/**
 * Reglas del combate por turnos (port de battle_insert.php, battle_click.php y battle_click_targets.php).
 * No toca la base: recibe el estado, lo modifica y deja en $battle->log lo que pasó.
 *
 * Efectos (código de la habilidad que los causa): 8 Defense, 23 Greater Fortitude, 36 veneno, 42 Soul Barrier,
 * 48 Wizardry Enhance, 52 Greater Defense, 53 Greater Damage, 71 Sleep, 73 Damage Reflection, 74 Berserker,
 * 75 Weakness, 77 Innovation. Cada combatiente tiene uno solo a la vez; uno nuevo reemplaza al anterior.
 */
final class BattleEngine
{
    public const string ATTACK = 'attack';

    /** @param array<string, mixed> $config data/game.php */
    public function __construct(
        private readonly SkillCatalog $skills,
        private readonly DropGenerator $drops,
        private readonly array $config,
    ) {
    }

    /**
     * Arma los enemigos de una ronda nueva.
     *
     * @param ?string $golden monstruo dorado disponible ahora en este mapa, si hay invasión
     *
     * @return bool si apareció el monstruo dorado
     */
    public function startRound(Battle $battle, GameMap $map, Spot $spot, ?string $golden, Rng $rng, ?string $worldBoss = null): bool
    {
        $battle->enemies = [];
        $battle->log = [];
        $goldenSpawned = false;
        $count = $rng->int((int) $this->config['enemies_min'], (int) $this->config['enemies_max']);

        for ($i = 0; $i < $count; $i++) {
            $name = $spot->encounters[$rng->int(0, count($spot->encounters) - 1)];
            $kind = '';

            if ($spot->boss !== null && !$battle->bossSeen && $rng->int(1, 100) <= (int) $this->config['boss_chance']) {
                $name = $spot->boss;
                $kind = 'boss';
                $battle->bossSeen = true;
            }
            if (
                $golden !== null && $map->monster($golden) !== null && !$battle->bossSeen
                && $rng->int(1, 100) <= (int) $this->config['golden']['chance']
            ) {
                $name = $golden;
                $kind = 'golden';
                $battle->bossSeen = true;
                $goldenSpawned = true;
            }
            // jefe de mapa, si está vivo: uno por visita, como los demás
            if (
                $worldBoss !== null && $map->monster($worldBoss) !== null && !$battle->bossSeen
                && $rng->int(1, 100) <= (int) ($this->config['world_boss']['chance'] ?? 0)
            ) {
                $name = $worldBoss;
                $kind = 'world';
                $battle->bossSeen = true;
            }

            $battle->enemies[] = self::spawn($map, $name, $kind);
        }

        return $goldenSpawned;
    }

    /**
     * Ronda de un mapa de evento: monstruos del spot dado, sin jefes al azar ni dorados.
     * En la última ronda aparece un solo enemigo, el jefe del evento.
     */
    public function startEventRound(Battle $battle, GameMap $map, Spot $spot, bool $boss, Rng $rng): void
    {
        $battle->enemies = [];
        $battle->log = [];
        $pool = [...$spot->encounters, ...($spot->boss === null ? [] : [$spot->boss])];
        $count = $boss ? 1 : $rng->int((int) $this->config['enemies_min'], (int) $this->config['enemies_max']);

        for ($i = 0; $i < $count; $i++) {
            $battle->enemies[] = self::spawn($map, $pool[$rng->int(0, count($pool) - 1)], $boss ? 'boss' : '');
        }
    }

    /** @return array<string, mixed> un enemigo recién aparecido */
    private static function spawn(GameMap $map, string $name, string $kind): array
    {
        $monster = $map->monster($name) ?? throw new \LogicException("Monstruo sin datos: $name");

        return [
            'name' => $monster->name,
            'level' => $monster->level,
            'hp' => $monster->hp,
            'hpCur' => $monster->hp,
            'damage' => $monster->damage,
            'defense' => $monster->defense,
            'damageRate' => $monster->damageRate,
            'defenseRate' => $monster->defenseRate,
            'attribute' => $monster->attribute,
            'effect' => '',
            'effectTurns' => 0,
            'kind' => $kind,
            'dead' => false,
            'exp' => 0,
            'expDelta' => 0,
            'zen' => 0,
            'zenBonus' => 0,
            'item' => null,
            'taken' => false,
        ];
    }

    /** Vida máxima efectiva: Greater Fortitude la sube mientras dura. */
    public function hpMax(Battle $battle, CharacterStats $stats): int
    {
        if ($battle->effect !== '23') {
            return $stats->hpMax;
        }

        return $stats->hpMax + intdiv($stats->hpMax * (12 + intdiv($stats->energy, 20)), 100);
    }

    /**
     * Juega un turno: la acción del jugador, la respuesta de los enemigos y lo que deje cada baja.
     *
     * @param string $action BattleEngine::ATTACK o un código de habilidad
     * @param ?int   $target índice del enemigo, o null para usarla sobre uno mismo
     *
     * @throws BattleException si la acción no es válida (no se gasta el turno)
     */
    public function act(Battle $battle, CharacterStats $stats, string $action, ?int $target, Rng $rng, DateTimeImmutable $now): void
    {
        if ($battle->defeated) {
            throw new BattleException('battle.error_defeated');
        }
        if ($battle->roundOver()) {
            throw new BattleException('battle.error_round_over');
        }
        if ($target !== null && (!isset($battle->enemies[$target]) || $battle->enemies[$target]['dead'])) {
            throw new BattleException('battle.error_target');
        }

        $definition = null;
        if ($action !== self::ATTACK) {
            $definition = $this->skills->all()[$action]['battle'] ?? null;
            if ($definition === null || !in_array($action, $stats->skills, true)) {
                throw new BattleException('battle.error_skill');
            }
        }
        $type = $definition[0] ?? 'plain';
        if ($type === 'summon') {
            $target = null; // la invocación no se apunta a nadie
        }
        // Sobre uno mismo sólo tienen sentido las curas y los efectos.
        if ($target === null && $type !== 'effect' && $type !== 'heal' && $type !== 'summon') {
            throw new BattleException('battle.error_target');
        }

        $battle->turn++;
        $battle->log = [];
        $ene = $stats->energy;
        $hpMax = $this->hpMax($battle, $stats);
        $battle->hp = min($battle->hp, $hpMax);

        $mana = $action === self::ATTACK ? 0 : (int) $this->skills->all()[$action]['mana'];
        $targets = $action === self::ATTACK ? 1 : (int) ($this->skills->all()[$action]['targets'] ?? 1);
        $hit = null; // daño del golpe principal, si pegó

        if ($battle->mp < $mana) {
            $battle->log[] = ['type' => 'no_mp'];
            $targets = 1;
        } elseif ($stats->usesArrows && $target !== null && $type !== 'heal' && $battle->arrows < 1) {
            // con arco o ballesta no se puede atacar sin flechas
            $battle->log[] = ['type' => 'no_arrows'];
            $targets = 1;
        } elseif ($type === 'summon') {
            $battle->mp -= $mana;
            $battle->summon = $action;
            $battle->summonHp = (int) $this->skills->all()[$action]['life'];
            $battle->log[] = ['type' => 'summon', 'skill' => $action];
        } elseif ($type === 'heal') {
            $battle->mp -= $mana;
            $amount = 5 + intdiv($ene, 5);
            if ($target === null) {
                $battle->hp = min($hpMax, $battle->hp + $amount);
            } else {
                $battle->enemies[$target]['hpCur'] = min($battle->enemies[$target]['hp'], $battle->enemies[$target]['hpCur'] + $amount);
            }
            $battle->log[] = ['type' => 'heal', 'target' => $target, 'n' => $amount];
        } elseif ($target === null) {
            $battle->mp -= $mana;
            // Sleep sobre uno mismo siempre falla, como en el original.
            if ($action === '71') {
                $battle->log[] = ['type' => 'miss', 'target' => null];
            } else {
                $battle->effect = $action;
                $battle->effectTurns = (int) $this->skills->all()[$action]['duration'] + 1;
                $battle->log[] = ['type' => 'effect', 'target' => null, 'skill' => $action];
            }
        } else {
            $battle->mp -= $mana;
            [$min, $max] = $this->damageRange($stats, $definition);
            $effectTurns = match ($type) {
                'effect' => (int) $this->skills->all()[$action]['duration'],
                default => (int) ($definition[3] ?? 0),
            };

            $strike = $this->strike($battle, $stats, $target, $min, $max, $action !== self::ATTACK, true, $rng);
            if ($strike === null) {
                $battle->log[] = ['type' => 'miss', 'target' => $target];
            } elseif ($max < 1) {
                $battle->enemies[$target]['effect'] = $action;
                $battle->enemies[$target]['effectTurns'] = $effectTurns + 1;
                $battle->log[] = ['type' => 'effect', 'target' => $target, 'skill' => $action];
            } else {
                $hit = $strike['damage'];
                $battle->enemies[$target]['hpCur'] -= $hit;
                if ($battle->arrows > 0 && $battle->effect !== 'F') {
                    // Triple Shot y Multi Shot gastan tres (con Infinity Arrow, ninguna)
                    $battle->arrows = max(0, $battle->arrows - (in_array($action, ['9', '56'], true) ? 3 : 1));
                }
                if ($effectTurns > 0) {
                    $battle->enemies[$target]['effect'] = $action;
                    $battle->enemies[$target]['effectTurns'] = $effectTurns + 1;
                }
                $battle->log[] = ['type' => 'dmg', 'target' => $target, 'n' => $hit, 'tag' => $strike['tag']];
            }

            // Combo del Dark Knight: tres habilidades distintas seguidas que pegan suman un golpe más.
            if ($stats->comboDamage > 0) {
                $battle->combo = $hit === null || $action === self::ATTACK ? [] : array_slice([...$battle->combo, $action], -3);
                if (count($battle->combo) === 3 && count(array_unique($battle->combo)) === 3) {
                    $battle->enemies[$target]['hpCur'] -= $stats->comboDamage;
                    $battle->log[] = ['type' => 'combo', 'target' => $target, 'n' => $stats->comboDamage];
                    $battle->combo = [];
                }
            }

            // Drain Life devuelve como vida el daño hecho.
            if ($action === '70' && $hit !== null) {
                $battle->hp = min($hpMax, $battle->hp + $hit);
                $battle->log[] = ['type' => 'drain', 'n' => $hit];
            }
        }

        // veneno sobre el jugador
        if ($battle->effect === '36') {
            $poison = intdiv(max(0, $battle->hp), 30);
            $battle->hp -= $poison;
            $battle->log[] = ['type' => 'poison', 'target' => null, 'n' => $poison];
        }

        // el enemigo con Damage Reflection devuelve parte del golpe
        if ($hit !== null && $target !== null && $battle->enemies[$target]['effect'] === '73') {
            $reflected = intdiv($hit * (20 + intdiv($ene, 40)), 100);
            if ($reflected > 0) {
                $battle->hp -= $reflected;
                $battle->log[] = ['type' => 'reflect', 'target' => null, 'n' => $reflected];
            }
        }

        // pegar con bonus de mascota o alas cuesta 1 de vida por golpe
        if ($hit !== null && $stats->damageBonus > 0) {
            $battle->hp -= 1;
        }

        // objetivos adicionales de las habilidades de área
        if ($targets > 1 && $target !== null && $definition !== null) {
            [$min, $max] = $this->damageRange($stats, $definition);
            foreach (array_keys($battle->enemies) as $i) {
                if ($targets <= 1) {
                    break;
                }
                if ($i === $target || $battle->enemies[$i]['dead']) {
                    continue;
                }
                $strike = $this->strike($battle, $stats, $i, $min, $max, true, false, $rng);
                if ($strike === null) {
                    $battle->log[] = ['type' => 'miss', 'target' => $i, 'extra' => true];
                } else {
                    $battle->enemies[$i]['hpCur'] -= $strike['damage'];
                    $battle->log[] = ['type' => 'dmg', 'target' => $i, 'n' => $strike['damage'], 'tag' => $strike['tag'], 'extra' => true];
                }
                $targets--;
            }
        }

        $this->finishTurn($battle, $stats, $hpMax, $rng, $now);
    }

    /**
     * Juega un turno tomando una poción (siempre sobre uno mismo). El servicio es quien la saca del inventario.
     *
     * @param string $kind    hp, mp o antidote
     * @param int    $percent cuánto restaura la poción, en % del máximo
     *
     * @throws BattleException
     */
    public function usePotion(Battle $battle, CharacterStats $stats, string $kind, int $percent, Rng $rng, DateTimeImmutable $now): void
    {
        if ($battle->defeated) {
            throw new BattleException('battle.error_defeated');
        }
        if ($battle->roundOver()) {
            throw new BattleException('battle.error_round_over');
        }

        $battle->turn++;
        $battle->log = [];
        $hpMax = $this->hpMax($battle, $stats);
        $battle->hp = min($battle->hp, $hpMax);

        if ($kind === 'hp') {
            $amount = min($hpMax - $battle->hp, intdiv($hpMax * $percent, 100));
            $battle->hp += $amount;
            $battle->log[] = ['type' => 'heal', 'target' => null, 'n' => $amount];
        } elseif ($kind === 'mp') {
            $amount = min($stats->mpMax - $battle->mp, intdiv($stats->mpMax * $percent, 100));
            $battle->mp += $amount;
            $battle->log[] = ['type' => 'mana', 'n' => $amount];
        } else {
            if ($battle->effect === '36') {
                $battle->effect = '';
                $battle->effectTurns = 0;
            }
            $battle->log[] = ['type' => 'antidote'];
        }

        if ($battle->effect === '36') {
            $poison = intdiv(max(0, $battle->hp), 30);
            $battle->hp -= $poison;
            $battle->log[] = ['type' => 'poison', 'target' => null, 'n' => $poison];
        }

        $this->finishTurn($battle, $stats, $hpMax, $rng, $now);
    }

    /** Respuesta de los enemigos, bajas, efectos que vencen y derrota. */
    private function finishTurn(Battle $battle, CharacterStats $stats, int $hpMax, Rng $rng, DateTimeImmutable $now): void
    {
        $this->summonTurn($battle, $rng);
        $this->ravenTurn($battle, $stats, $rng);
        $this->enemyTurn($battle, $stats, $rng);
        $this->collectKills($battle, $stats, $hpMax, $rng, $now);
        $this->tickEffects($battle);

        if ($battle->hp < 1) {
            // Derrota: se pierde la mitad de la experiencia ganada en el nivel actual.
            $previous = Battle::experienceFor($battle->level - 1);
            $battle->experience -= intdiv(max(0, $battle->experience - $previous), 2);
            $battle->hp = 0;
            $battle->defeated = true;
            $battle->log[] = ['type' => 'defeat'];
        }
    }

    /**
     * Rango de daño de la acción antes de tirar los dados.
     *
     * @param ?array<int, mixed> $definition clave `battle` de la habilidad; null = ataque normal
     *
     * @return array{int, int}
     */
    public static function damageRange(CharacterStats $s, ?array $definition): array
    {
        $ene = intdiv($s->energy, 10);
        $rise = static fn (int $base, int $percent): int => $base + intdiv($base * $percent, 100);

        return match ($definition[0] ?? 'plain') {
            'phys' => [$s->damageMin + $definition[1] + $ene, $s->damageMax + $definition[2] + $ene],
            'fixed' => [intdiv(($definition[1] + $ene) * 5, 6), $definition[1] + $ene],
            'wiz' => [$rise($s->wizDamageMin + $definition[1], $s->staffRise), $rise($s->wizDamageMax + $definition[2], $s->staffRise)],
            'curse' => [$rise($s->wizDamageMin + $definition[1], $s->curseIncrement), $rise($s->wizDamageMax + $definition[2], $s->curseIncrement)],
            'effect' => [0, 0],
            default => [$s->damageMin, $s->damageMax],
        };
    }

    /**
     * Un golpe del jugador contra un enemigo.
     *
     * @return array{damage: int, tag: string}|null null si falló
     */
    private function strike(Battle $b, CharacterStats $s, int $i, int $min, int $max, bool $isSkill, bool $mainTarget, Rng $rng): ?array
    {
        $ene = $s->energy;
        $damage = $rng->int($min, $max);
        $tag = '';

        // Critical Damage del Dark Lord: mientras dura, los golpes críticos y excellent pegan más.
        $critical = $s->criticalDamageBonus + ($b->effect === '82' ? intdiv($s->command, 25) + intdiv($ene, 30) : 0);

        if ($s->excellentRate !== 0 && $rng->int(1, 100) <= $s->excellentRate) {
            $top = $max + $critical;
            $damage = $top + (int) floor($top * 0.2);
            $tag = 'exc';
        }
        if ($s->luck !== 0 && $tag === '' && $rng->int(1, 100) <= $s->luck) {
            $damage = $max + $critical;
            $tag = 'crt';
        }

        $damage += match ($b->effect) {
            '53' => (int) floor(3 + $ene / 7),
            '74' => (int) floor(30 + $ene / 20),
            '75' => -(int) floor(20 + $ene / 20),
            default => 0,
        };
        if ($b->effect === '48' && $s->wizDamageMax > 0) {
            $damage += intdiv($damage * (20 + intdiv($ene, 100)), 100);
        }
        if ($isSkill) {
            $damage += $s->skillDamageBonus;
        }

        $enemy = $b->enemies[$i];
        $defense = max(0, $enemy['defense'] + self::defenseShift($enemy['effect'], $ene));
        if ($mainTarget && $s->ignoreDefense > 0 && $rng->int(1, 100) <= $s->ignoreDefense) {
            $defense = 0;
            $tag = trim($tag . ' ign');
        }

        $damage -= $defense;
        $damage += (int) floor($damage * $s->damageBonus / 100);
        if ($enemy['effect'] === '8') {
            $damage = (int) ceil($damage / 2);
        }
        // Un golpe despierta al enemigo dormido.
        if ($enemy['effect'] === '71') {
            $b->enemies[$i]['effect'] = '';
            $b->enemies[$i]['effectTurns'] = 0;
        }
        $damage = max(1, $damage);

        $missChance = self::clamp(intdiv($enemy['defenseRate'] * 50, max(1, $s->damageRate)), 5, 95);
        if ($rng->int(1, 100) <= $missChance) {
            return null;
        }

        return ['damage' => $damage, 'tag' => $tag];
    }

    /** La invocación pega al primer enemigo que siga en pie. */
    private function summonTurn(Battle $b, Rng $rng): void
    {
        $definition = $b->summon === '' ? null : ($this->skills->all()[$b->summon]['battle'] ?? null);
        if ($definition === null) {
            return;
        }
        foreach ($b->enemies as $i => $enemy) {
            if ($enemy['dead'] || $enemy['hpCur'] < 1) {
                continue;
            }
            $damage = max(1, $rng->int((int) $definition[1], (int) $definition[2]) - intdiv((int) $enemy['defense'], 2));
            $b->enemies[$i]['hpCur'] -= $damage;
            $b->log[] = ['type' => 'summon_dmg', 'skill' => $b->summon, 'target' => $i, 'n' => $damage];

            return;
        }
    }

    /** El Dark Raven ataca por su cuenta al primer enemigo en pie, como una invocación que no recibe golpes. */
    private function ravenTurn(Battle $b, CharacterStats $s, Rng $rng): void
    {
        if ($s->ravenMax < 1) {
            return;
        }
        foreach ($b->enemies as $i => $enemy) {
            if ($enemy['dead'] || $enemy['hpCur'] < 1) {
                continue;
            }
            $damage = max(1, $rng->int($s->ravenMin, $s->ravenMax) - intdiv((int) $enemy['defense'], 2));
            $b->enemies[$i]['hpCur'] -= $damage;
            $b->log[] = ['type' => 'raven_dmg', 'target' => $i, 'n' => $damage];

            return;
        }
    }

    private function enemyTurn(Battle $b, CharacterStats $s, Rng $rng): void
    {
        $ene = $s->energy;
        $defense = max(0, $s->defense + self::defenseShift($b->effect, $ene));

        foreach ($b->enemies as $i => $enemy) {
            if ($enemy['dead'] || $enemy['hpCur'] < 1 || $enemy['effect'] === '71') {
                continue;
            }

            // Con una invocación en pie, la mitad de los golpes van contra ella.
            if ($b->summon !== '' && $rng->int(0, 1) === 1) {
                $blow = $rng->int(intdiv($enemy['damage'] * 5, 6), $enemy['damage']);
                $b->summonHp -= $blow;
                $b->log[] = ['type' => 'summon_hit', 'skill' => $b->summon, 'enemy' => $i, 'n' => $blow];
                if ($b->summonHp < 1) {
                    $b->log[] = ['type' => 'summon_dead', 'skill' => $b->summon];
                    $b->summon = '';
                    $b->summonHp = 0;
                }
                continue;
            }

            $damage = $rng->int(intdiv($enemy['damage'] * 5, 6), $enemy['damage']);
            $damage += match ($enemy['effect']) {
                '53' => (int) floor(3 + $ene / 7),
                '74' => (int) floor(30 + $ene / 20),
                '75' => -(int) floor(20 + $ene / 20),
                default => 0,
            };

            $damage -= $defense;
            $damage -= (int) floor($damage * $s->damageAbsorb / 100);
            $damage -= (int) floor($damage * $s->damageDecrease / 100);
            if ($b->effect === '8') {
                $damage = (int) ceil($damage / 2);
            }
            if ($b->effect === '42') {
                // Soul Barrier: parte del daño lo paga el maná.
                $absorbed = (int) floor($damage * (10 + intdiv($ene, 100)) / 100);
                if ($absorbed > 0) {
                    $damage -= $absorbed;
                    $b->mp -= $absorbed;
                }
            }

            $resisted = 0;
            if ($enemy['attribute'] !== null) {
                $resisted = ($s->elementResist[$enemy['attribute']] ?? 0) * 5;
                $damage -= $resisted;
            }
            $damage = max((int) ceil($enemy['level'] / 10), $damage);

            $missChance = self::clamp(intdiv($s->defenseRate * 50, max(1, $enemy['damageRate'])), 5, 95);
            if ($rng->int(1, 100) <= $missChance) {
                $b->log[] = ['type' => 'enemy_miss', 'enemy' => $i];
            } else {
                $b->hp -= $damage;
                $b->log[] = ['type' => 'enemy_dmg', 'enemy' => $i, 'n' => $damage, 'resisted' => $resisted];

                if ($enemy['attribute'] === 'poison' && $rng->int(0, 1) > 0) {
                    $b->effect = '36';
                    $b->effectTurns = 11;
                }

                $reflect = $s->reflect + ($b->effect === '73' ? 20 + intdiv($ene, 40) : 0);
                $reflected = intdiv($damage * $reflect, 100);
                if ($reflected > 0) {
                    $b->enemies[$i]['hpCur'] -= $reflected;
                    $b->log[] = ['type' => 'reflect', 'target' => $i, 'n' => $reflected];
                }

                // los golpes fuertes desgastan a la mascota, la mitad de las veces
                if ($b->petLife > 0 && $damage > 4 && $rng->int(0, 1) > 0) {
                    $b->petLife--;
                }
            }

            if ($enemy['effect'] === '36') {
                $poison = intdiv(max(0, $b->enemies[$i]['hpCur']), 30);
                $b->enemies[$i]['hpCur'] -= $poison;
                $b->log[] = ['type' => 'poison', 'target' => $i, 'n' => $poison];
            }
        }

        $b->mp = max(0, $b->mp);
    }

    /**
     * Suma experiencia a la batalla. A lo sumo sube un nivel por vez; lo que sobra se pierde.
     *
     * @return bool si subió de nivel
     */
    public function grantExperience(Battle $b, int $gained): bool
    {
        $next = Battle::experienceFor($b->level);
        if ($b->level >= Battle::MAX_LEVEL) {
            // En el nivel máximo la experiencia se llena y queda ahí…
            $b->experience = min($next, $b->experience + $gained);
            // …y, con la tercera clase, además sube el nivel master.
            if ($b->master && $b->masterLevel < MasterTree::MAX_LEVEL) {
                $b->masterExp += $gained;
                if ($b->masterExp >= MasterTree::experienceFor($b->masterLevel)) {
                    $b->masterExp = 0;
                    $b->masterLevel++;
                    $b->masterUps++;
                    $b->log[] = ['type' => 'master_up', 'n' => $b->masterLevel];
                }
            }

            return false;
        }
        if ($b->experience + $gained >= $next) {
            $b->experience = $next;
            $b->level++;
            $b->levelUps++;

            return true;
        }
        $b->experience += $gained;

        return false;
    }

    /** Experiencia, zen e ítem de cada enemigo que cayó en este turno. */
    private function collectKills(Battle $b, CharacterStats $s, int $hpMax, Rng $rng, DateTimeImmutable $now): void
    {
        foreach ($b->enemies as $i => $enemy) {
            if ($enemy['dead'] || $enemy['hpCur'] > 0) {
                continue;
            }

            $base = $enemy['level'] + $enemy['defense'];
            // El original sólo contaba nivel y defensa: los monstruos de los mapas altos, con decenas de veces más
            // vida, daban apenas el triple de experiencia. Con un divisor configurado, la vida también cuenta.
            $divisor = (int) ($this->config['exp_hp_divisor'] ?? 0);
            if ($divisor > 0) {
                $base += intdiv((int) $enemy['hp'], $divisor);
            }
            $exp = $rng->int($base, $base * 2);
            // la mitad de las veces no deja zen
            $zen = ($rng->int($exp, (int) floor($exp * 1.5)) + 1) * $rng->int(0, 1);
            $item = $this->drops->drop($enemy['name'], $enemy['level'], $rng, $now);

            $b->kills++;
            $b->expEarned += $exp;
            if ($b->questTarget !== '' && $enemy['name'] === $b->questTarget) {
                $b->questKills++;
            }

            // Misión de clase: ítems que sólo caen mientras está activa, y bajas que cuentan en su mapa.
            foreach ($b->evoDrops as $k => $want) {
                $match = $want['monster'] !== ''
                    ? $enemy['name'] === $want['monster']
                    : $enemy['level'] >= $want['min'] && $enemy['level'] <= $want['max']
                        && $rng->int(1, max(1, (int) ($this->config['evolution']['drop_one_in'] ?? 20))) === 1;
                if ($match) {
                    $b->evoFound[] = $want['item'];
                    unset($b->evoDrops[$k]);
                    $b->evoDrops = array_values($b->evoDrops);
                    $b->log[] = ['type' => 'quest_item', 'item' => $want['item']];
                    break;
                }
            }
            if ($b->map === $b->evoMap && ($b->evoKills[$enemy['name']] ?? 0) > 0) {
                $b->evoKills[$enemy['name']]--;
                $b->evoKilled[$enemy['name']] = ($b->evoKilled[$enemy['name']] ?? 0) + 1;
                $b->log[] = ['type' => 'quest_kill', 'enemy' => $i, 'left' => $b->evoKills[$enemy['name']]];
            }

            // Reparto de experiencia cuando hay más jugadores en el spot.
            $gained = $exp;
            if ($b->expPercent < 100) {
                $gained = max(1, min($exp - 1, intdiv($exp * $b->expPercent, 100)));
            } elseif ($b->expPercent > 100) {
                $gained = max($exp + 1, intdiv($exp * $b->expPercent, 100));
            }

            $levelUp = $this->grantExperience($b, $gained);

            $zenBonus = $zen > 0 ? (int) floor($zen * $s->afterHuntZen * 0.4) : 0;
            $b->zen += $zen + $zenBonus;

            if ($s->afterHuntHp > 0 && $b->hp > 0) {
                $b->hp = min($hpMax, $b->hp + (int) floor($hpMax / 8 * $s->afterHuntHp));
            }
            if ($s->afterHuntMp > 0) {
                $b->mp = min($s->mpMax, $b->mp + (int) floor($s->mpMax / 8 * $s->afterHuntMp));
            }

            $b->enemies[$i] = [
                'dead' => true,
                'hpCur' => 0,
                'exp' => $gained,
                'expDelta' => $gained - $exp,
                'zen' => $zen + $zenBonus,
                'zenBonus' => $zenBonus,
                'item' => $item?->toCode(),
                'taken' => false,
            ] + $b->enemies[$i];

            $b->log[] = ['type' => 'kill', 'enemy' => $i, 'exp' => $gained, 'zen' => $zen + $zenBonus];
            if ($levelUp) {
                $b->log[] = ['type' => 'level_up', 'n' => $b->level];
            }
        }
    }

    private function tickEffects(Battle $b): void
    {
        if ($b->effectTurns > 0) {
            $b->effectTurns--;
        }
        if ($b->effectTurns < 1) {
            $b->effect = '';
        }
        foreach ($b->enemies as $i => $enemy) {
            $turns = max(0, $enemy['effectTurns'] - 1);
            $b->enemies[$i]['effectTurns'] = $turns;
            if ($turns < 1) {
                $b->enemies[$i]['effect'] = '';
            }
        }
    }

    /** Cuánto cambia la defensa de quien tiene el efecto (se calcula con la energía del jugador). */
    private static function defenseShift(string $effect, int $energy): int
    {
        return match ($effect) {
            '52' => (int) floor(2 + $energy / 8),
            '74' => -(int) floor(10 + $energy / 20),
            '77' => -(int) floor(20 + $energy / 20),
            default => 0,
        };
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
