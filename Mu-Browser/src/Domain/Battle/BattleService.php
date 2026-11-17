<?php

declare(strict_types=1);

namespace Mu\Domain\Battle;

use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\ItemRepository;
use Mu\Data\PresenceRepository;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\EvolutionService;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Inventory\StoredItem;
use Mu\Domain\Item\Item;
use Mu\Domain\Party\PartyService;
use Mu\Domain\Quest\QuestCatalog;
use Mu\Domain\Stats\CharacterStats;
use Mu\Domain\Stats\EquipmentSlot;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\GoldenInvasion;
use Mu\Domain\World\MapCatalog;
use Mu\Domain\World\ScheduledEvents;
use Mu\Domain\World\WorldBosses;
use Mu\Support\Clock;
use Mu\Support\Rng;

/**
 * Une el motor de combate con la base: entrar a un spot, jugar turnos, cerrar rondas y salir.
 * El progreso (experiencia, nivel, vida, zen) se guarda en el personaje al cerrar cada ronda,
 * al salir y al ser derrotado.
 */
final class BattleService
{
    /** Cantidad de spots que recorre un evento antes del jefe. */
    public const int EVENT_SPOTS = 14;

    /** Acciones de poción → subtipos de ítem F que sirven, de la más fuerte a la más débil. */
    private const array POTIONS = [
        'potion_hp' => ['5', '4', '3', '2', '1'],
        'potion_mp' => ['9', '8', '7', '6'],
        'potion_antidote' => ['A'],
    ];

    /** @param array<string, mixed> $config data/game.php */
    public function __construct(
        private readonly BattleEngine $engine,
        private readonly StatsCalculator $calculator,
        private readonly MapCatalog $maps,
        private readonly GoldenInvasion $golden,
        private readonly BattleRepository $battles,
        private readonly CharacterRepository $characters,
        private readonly AccountRepository $accounts,
        private readonly ItemRepository $items,
        private readonly PresenceRepository $presence,
        private readonly Clock $clock,
        private readonly Rng $rng,
        private readonly array $config,
        private readonly QuestCatalog $quests,
        private readonly ?WorldBosses $worldBosses = null,
        private readonly ?ScheduledEvents $events = null,
        private readonly ?PartyService $party = null,
    ) {
    }

    public function current(Character $character): ?Battle
    {
        return $this->battles->find($character->id)[0] ?? null;
    }

    public function stats(Character $character): CharacterStats
    {
        return $this->calculator->calculate($character, $this->items->items(Container::Equipment, $character->id));
    }

    /** Vida máxima efectiva en la batalla (cuenta Greater Fortitude). */
    public function hpMax(Battle $battle, CharacterStats $stats): int
    {
        return $this->engine->hpMax($battle, $stats);
    }

    /** @return list<array{character_id: int, name: string, exp_rate: int}> quiénes pelean en el spot */
    public function playersAt(string $map, string $spot): array
    {
        return $this->presence->at(self::location($map, $spot), $this->clock->now());
    }

    /** @throws BattleException */
    public function enter(Character $character, string $mapId, string $spotId): Battle
    {
        if (!MapCatalog::exists($mapId)) {
            throw new BattleException('battle.error_spot'); // a los mapas de evento se entra con enterEvent()
        }
        $map = $this->maps->get($mapId) ?? throw new BattleException('battle.error_spot');
        $spot = $map->spot($spotId) ?? throw new BattleException('battle.error_spot');
        $now = $this->clock->now();

        $found = $this->battles->find($character->id);
        if ($found !== null) {
            [$existing] = $found;
            if ($existing->map === $mapId && $existing->spot === $spotId && !$existing->defeated) {
                return $existing;
            }
            // Venía de otro lado: se cierra aquella batalla antes de empezar esta.
            $this->leave($character);
            $character = $this->characters->findById($character->id) ?? $character;
        }

        $others = array_filter(
            $this->presence->at(self::location($mapId, $spotId), $now),
            static fn (array $p): bool => $p['character_id'] !== $character->id,
        );
        if (count($others) >= (int) $this->config['spot_capacity']) {
            throw new BattleException('battle.error_spot_full');
        }

        $stats = $this->stats($character);
        $battle = new Battle();
        $battle->map = $mapId;
        $battle->spot = $spotId;
        $battle->level = $character->level;
        $battle->experience = $character->experience;
        $battle->hp = max(1, min($character->hpCur, $stats->hpMax));
        $battle->mp = min($character->mpCur, $stats->mpMax);
        $battle->arrows = $stats->arrows;
        $battle->petLife = $stats->petLife;
        $battle->questTarget = $this->questTarget($character);
        $this->huntFor($battle, $character);
        $battle->master = $character->class->tier() === 3;
        $battle->masterLevel = $character->masterLevel;
        $battle->masterExp = $character->masterExp;

        $this->presence->touch($character->id, self::location($mapId, $spotId), 0, $now);
        $this->newRound($battle, $character);
        $this->battles->create($character->id, $battle, $now);

        return $battle;
    }

    /**
     * Entra al mapa de evento para el que el personaje tiene entrada (Kalima, tras usar un Lost Map).
     * La entrada se gasta al entrar: si sale o lo derrotan, necesita otra.
     *
     * @throws BattleException
     */
    public function enterEvent(Character $character): Battle
    {
        $mapId = $character->eventMap ?? throw new BattleException('battle.error_no_event');
        if (!MapCatalog::isEvent($mapId)) {
            throw new BattleException('battle.error_no_event');
        }
        // Devil Square y Blood Castle sólo dejan pasar mientras están abiertos; la entrada no se pierde.
        $scheduled = $this->events?->eventOf($mapId);
        if ($scheduled !== null && $this->events?->isOpen($scheduled, $this->clock->now()) !== true) {
            throw new BattleException('event.error_closed');
        }
        $this->leave($character);
        $character = $this->characters->findById($character->id) ?? $character;
        $stats = $this->stats($character);

        $battle = new Battle();
        $battle->map = $mapId;
        $battle->event = true;
        $battle->eventCountdown = self::EVENT_SPOTS;
        $battle->level = $character->level;
        $battle->experience = $character->experience;
        $battle->hp = max(1, min($character->hpCur, $stats->hpMax));
        $battle->mp = min($character->mpCur, $stats->mpMax);
        $battle->arrows = $stats->arrows;
        $battle->petLife = $stats->petLife;
        $battle->questTarget = $this->questTarget($character);
        $this->huntFor($battle, $character);
        $battle->master = $character->class->tier() === 3;
        $battle->masterLevel = $character->masterLevel;
        $battle->masterExp = $character->masterExp;

        $this->characters->setEventMap($character->id, null);
        $this->newRound($battle, $character);
        $this->battles->create($character->id, $battle, $this->clock->now());

        return $battle;
    }

    /** @throws BattleException */
    public function act(Character $character, string $action, ?int $target): Battle
    {
        [$battle, $version] = $this->battles->find($character->id) ?? throw new BattleException('battle.error_none');
        $now = $this->clock->now();

        $potion = null;
        if (isset(self::POTIONS[$action])) {
            $potion = $this->findPotion($character, $action) ?? throw new BattleException('battle.error_no_potion');
            $kind = substr($action, 7);
            $this->engine->usePotion($battle, $this->stats($character), $kind, $potion->item->effect, $this->rng, $now);
        } else {
            $this->engine->act($battle, $this->stats($character), $action, $target, $this->rng, $now);
        }

        if (!$this->battles->save($character->id, $battle, $version, $now)) {
            // Otro pedido jugó este turno primero: se muestra el estado que quedó.
            return $this->current($character) ?? $battle;
        }
        if ($potion !== null) {
            $this->items->delete($potion->id);
        }
        // Jefe de mapa vencido: queda anotado para todo el servidor (sólo cuenta el primero que lo logra).
        foreach ($battle->enemies as $enemy) {
            if (($enemy['kind'] ?? '') === 'world' && $enemy['dead']) {
                $this->worldBosses?->defeat((string) $enemy['name'], $character->name, $now);
            }
        }
        // Si se acabaron las flechas o murió la mascota, sale del equipo ya: cambian las estadísticas.
        if ($battle->arrows === 0 || $battle->petLife === 0) {
            $this->syncEquipment($character, $battle);
            $this->battles->save($character->id, $battle, $version + 1, $now);
        }

        if ($battle->defeated) {
            $this->settle($character, $battle, defeated: true);
            $this->presence->remove($character->id);
        } else {
            $this->presence->touch($character->id, self::location($battle->map, $battle->spot), self::expRate($battle), $now);
        }

        return $battle;
    }

    /** Cierra la ronda ganada y arma la siguiente. @throws BattleException */
    public function nextRound(Character $character): Battle
    {
        [$battle, $version] = $this->battles->find($character->id) ?? throw new BattleException('battle.error_none');
        if ($battle->defeated || !$battle->roundOver()) {
            throw new BattleException('battle.error_round_open');
        }
        $now = $this->clock->now();

        $this->settle($character, $battle, regenerate: true);
        $character = $this->characters->findById($character->id) ?? $character;

        if ($battle->event && $battle->eventCountdown < 1) {
            // cayó el jefe: el evento terminó. Blood Castle paga su recompensa en zen.
            if (str_starts_with($battle->map, 'blood_castle_')) {
                $this->accounts->addZen($character->accountId, $character->level * (int) ($this->config['event_rewards']['blood_castle_zen_per_level'] ?? 0));
            }
            $this->battles->delete($character->id);
            $this->presence->remove($character->id);
            throw new BattleException('battle.event_over');
        }

        $this->presence->touch($character->id, self::location($battle->map, $battle->spot), self::expRate($battle), $now);
        $this->newRound($battle, $character);
        $this->battles->save($character->id, $battle, $version, $now);

        return $battle;
    }

    /** Sale del spot guardando lo ganado. No hace nada si no hay batalla. */
    public function leave(Character $character): void
    {
        $found = $this->battles->find($character->id);
        if ($found !== null && !$found[0]->defeated) {
            $this->settle($character, $found[0], regenerate: $found[0]->roundOver());
        }
        $this->battles->delete($character->id);
        $this->presence->remove($character->id);
    }

    /** Guarda en el inventario el ítem que dejó un enemigo. @throws BattleException */
    public function pick(Character $character, int $enemy): Item
    {
        [$battle, $version] = $this->battles->find($character->id) ?? throw new BattleException('battle.error_none');
        $code = $battle->enemies[$enemy]['item'] ?? null;
        if ($code === null || !$battle->enemies[$enemy]['dead'] || $battle->enemies[$enemy]['taken']) {
            throw new BattleException('battle.error_no_item');
        }
        $now = $this->clock->now();

        // Primero se marca como tomado (con control de versión) y recién después se entrega:
        // así dos pedidos simultáneos no duplican el ítem.
        $battle->enemies[$enemy]['taken'] = true;
        if (!$this->battles->save($character->id, $battle, $version, $now)) {
            throw new BattleException('battle.error_no_item');
        }

        $item = Item::parse($code);
        if ($this->items->addToInventory($character->id, $item, $now) === null) {
            $battle->enemies[$enemy]['taken'] = false;
            $this->battles->save($character->id, $battle, $version + 1, $now);
            throw new BattleException('battle.error_no_space');
        }

        return $item;
    }

    /**
     * Pociones que tiene en el inventario, para mostrar en la batalla.
     *
     * @return array<string, int> acción (potion_hp, potion_mp, potion_antidote) => cantidad
     */
    public function potions(Character $character): array
    {
        $counts = array_fill_keys(array_keys(self::POTIONS), 0);
        foreach ($this->items->items(Container::Inventory, $character->id) as $item) {
            foreach (self::POTIONS as $action => $subTypes) {
                if ($item->type === 'F' && in_array($item->subType, $subTypes, true)) {
                    $counts[$action]++;
                }
            }
        }

        return $counts;
    }

    /** La mejor poción disponible de ese tipo: primero las más fuertes, como en el original. */
    private function findPotion(Character $character, string $action): ?StoredItem
    {
        $inventory = $this->items->stored(Container::Inventory, $character->id);
        foreach (self::POTIONS[$action] as $subType) {
            foreach ($inventory as $stored) {
                if ($stored->item->type === 'F' && $stored->item->subType === $subType) {
                    return $stored;
                }
            }
        }

        return null;
    }

    /** Lleva al equipo las flechas gastadas y la vida que perdió la mascota; lo que llegó a cero desaparece. */
    private function syncEquipment(Character $character, Battle $battle): void
    {
        $equipment = $this->items->stored(Container::Equipment, $character->id);
        $worn = [
            [$equipment[EquipmentSlot::Hand2->value] ?? null, 'arrows'],
            [$equipment[EquipmentSlot::Pet->value] ?? null, 'petLife'],
        ];
        foreach ($worn as [$stored, $field]) {
            if ($stored === null || $battle->$field < 0) {
                continue;
            }
            if ($battle->$field < 1) {
                $this->items->delete($stored->id);
                $battle->$field = -1;
            } elseif ($stored->item->durability !== $battle->$field) {
                $this->items->updateCode($stored->id, $stored->item->with(['durability' => $battle->$field]));
            }
        }
    }

    /** Lo que tiene que conseguir para la misión de clase activa. */
    private function huntFor(Battle $battle, Character $character): void
    {
        $hunt = EvolutionService::hunt($character, $this->config['evolution'] ?? []);
        $battle->evoDrops = $hunt['drops'];
        $battle->evoMap = $hunt['map'];
        $battle->evoKills = $hunt['kills'];
    }

    /** Premio de Marlon: un punto más por cada nivel ganado por encima del 220. */
    private function plusStat(Character $character, Battle $battle): int
    {
        if ($battle->levelUps < 1 || !EvolutionService::hasPlusStat($character)) {
            return 0;
        }
        $from = (int) ($this->config['evolution']['marlon_level'] ?? 220);

        return max(0, $battle->level - max($from, $battle->level - $battle->levelUps));
    }

    /** Pasa al personaje y a la cuenta lo acumulado en la batalla. */
    private function settle(Character $character, Battle $battle, bool $regenerate = false, bool $defeated = false): void
    {
        // Party: lo ganado desde el último cierre se comparte con los compañeros que pelean en el mismo mapa.
        if ($battle->expEarned > $battle->expShared) {
            $this->party?->share($character->id, $battle->map, $battle->expEarned - $battle->expShared, $this->clock->now());
            $battle->expShared = $battle->expEarned;
        }
        $this->syncEquipment($character, $battle);
        $stats = $this->stats($character);
        if ($regenerate && !$defeated && $battle->hp > 0) {
            // 1% de vida y maná por ronda, más lo que sume el equipo.
            $battle->hp = min($stats->hpMax, $battle->hp + (int) ceil($stats->hpMax / 100 * (1 + $stats->hpRegeneration)));
            $battle->mp = min($stats->mpMax, $battle->mp + (int) ceil($stats->mpMax / 100 * (1 + $stats->mpRegeneration)));
        }

        // Tras una derrota vuelve con el porcentaje de vida configurado (data/game.php).
        $hp = $defeated
            ? intdiv($stats->hpMax * (int) $this->config['defeat_hp_percent'], 100)
            : $battle->hp;

        $this->characters->saveProgress(
            $character->id,
            $battle->level,
            $battle->levelUps * $character->class->pointsPerLevel() + $this->plusStat($character, $battle),
            $battle->experience,
            max(1, $hp),
            max(0, $battle->mp),
            $battle->kills,
        );
        if ($battle->zen > 0) {
            $this->accounts->addZen($character->accountId, $battle->zen);
        }
        if ($battle->master) {
            $this->characters->saveMaster($character->id, $battle->masterLevel, $battle->masterExp, $battle->masterUps);
            $battle->masterUps = 0;
        }
        if ($battle->questKills > 0) {
            $this->characters->addQuestScore($character->id, $battle->questKills);
        }
        $battle->questKills = 0;
        if ($battle->evoFound !== [] || $battle->evoKilled !== []) {
            // Lo conseguido para la misión de clase queda anotado en el personaje.
            $fresh = $this->characters->findById($character->id) ?? $character;
            [$step, $progress] = EvolutionService::record($fresh->evoStep, $fresh->evoProgress, $battle->evoFound, $battle->evoKilled);
            $this->characters->setEvoProgress($character->id, $step, $progress);
            $battle->evoFound = [];
            $battle->evoKilled = [];
        }

        $battle->levelUps = 0;
        $battle->kills = 0;
        $battle->zen = 0;
    }

    private function newRound(Battle $battle, Character $character): void
    {
        $map = $this->maps->get($battle->map) ?? throw new BattleException('battle.error_spot');

        // Party: cobra lo que le dejaron sus compañeros.
        $battle->partyExp = $this->party?->collect($character->id) ?? 0;
        if ($battle->partyExp > 0) {
            $this->engine->grantExperience($battle, $battle->partyExp);
        }

        if ($battle->event) {
            // Cada ronda se pelea en el spot que marca la cuenta regresiva (del 14 al 1).
            $battle->spot = 'spot' . $battle->eventCountdown;
            $spot = $map->spot($battle->spot) ?? throw new BattleException('battle.error_spot');
            // La cuenta baja la mitad de las veces; del último spot se pasa siempre al jefe.
            if ($battle->eventCountdown === 1 || $this->rng->int(0, 1) > 0) {
                $battle->eventCountdown--;
            }
            $battle->expPercent = str_starts_with($battle->map, 'devil_square_') ? (int) ($this->config['event_rewards']['devil_square_exp'] ?? 100) : 100;
            $this->engine->startEventRound($battle, $map, $spot, $battle->eventCountdown === 0, $this->rng);

            return;
        }

        $spot = $map->spot($battle->spot) ?? throw new BattleException('battle.error_spot');
        $now = $this->clock->now();

        // Si hay más gente en el spot, la experiencia se reparte según cuánto saca cada uno.
        // Lo que sacan los compañeros de clan presentes cuenta a favor: pelear juntos rinde más.
        $total = 0;
        $guildmates = 0;
        foreach ($this->presence->at(self::location($battle->map, $battle->spot), $now) as $other) {
            $total += $other['exp_rate'];
            if ($other['character_id'] !== $character->id && $character->guildId !== null && $other['guild_id'] === $character->guildId) {
                $guildmates += $other['exp_rate'];
            }
        }
        $battle->expPercent = $total < 1
            ? 100
            : max(10, min(150, intdiv((self::expRate($battle) + $guildmates) * 100, $total) + $guildmates));

        $golden = $this->golden->availableIn($battle->map, $now);
        $world = $this->worldBosses?->aliveIn($battle->map, $now);
        if ($this->engine->startRound($battle, $map, $spot, $golden, $this->rng, $world) && $golden !== null) {
            if (!$this->golden->claim($golden, $character->name, $now)) {
                // Otro jugador lo encontró en el mismo instante: acá aparece un monstruo común.
                $this->engine->startRound($battle, $map, $spot, null, $this->rng, $world);
            }
        }
    }

    private function questTarget(Character $character): string
    {
        return $character->questId === null ? '' : ($this->quests->get($character->questId)?->target ?? '');
    }

    /** Experiencia base por turno desde que entró al spot. */
    private static function expRate(Battle $battle): int
    {
        return (int) ceil($battle->expEarned / max(1, $battle->turn));
    }

    private static function location(string $map, string $spot): string
    {
        return $map . ':' . $spot;
    }
}
