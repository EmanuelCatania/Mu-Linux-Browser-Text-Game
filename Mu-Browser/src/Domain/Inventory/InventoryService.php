<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\DuelRepository;
use Mu\Data\ItemRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Item\Item;
use Mu\Domain\Skill\SkillCatalog;
use Mu\Domain\Stats\EquipmentSlot;
use Mu\Domain\Stats\StatsCalculator;
use Mu\Domain\World\MapCatalog;
use Mu\Domain\World\Monster;
use Mu\Domain\World\ScheduledEvents;
use Mu\Support\Clock;
use Mu\Support\Rng;

/**
 * Mover ítems entre inventario, equipo y baúl, usarlos y mejorarlos con joyas
 * (port de function.move_item.php, function.use_item.php y function.jewel_drop.php).
 */
final class InventoryService
{
    /** Subtipo de fruta → atributo que sube. */
    private const array FRUIT_STATS = ['1' => 'strength', '2' => 'agility', '3' => 'stamina', '4' => 'energy'];

    public function __construct(
        private readonly ItemRepository $items,
        private readonly CharacterRepository $characters,
        private readonly BattleRepository $battles,
        private readonly StatsCalculator $calculator,
        private readonly SkillCatalog $skills,
        private readonly Transaction $transaction,
        private readonly Rng $rng,
        private readonly ?DuelRepository $duels = null,
        private readonly ?Clock $clock = null,
    ) {
    }

    /** Id del dueño de un contenedor: la cuenta para el baúl, el personaje para el resto. */
    public static function owner(Container $container, Account $account, Character $character): int
    {
        return $container->ownedByAccount() ? $account->id : $character->id;
    }

    /** @throws InventoryException */
    public function move(Account $account, Character $character, Container $from, int $fromSlot, Container $to, int $toSlot): void
    {
        $this->transaction->run($account->id, function () use ($account, $character, $from, $fromSlot, $to, $toSlot): void {
            $this->assertNotInBattle($character);
            if ($from === Container::Store || $to === Container::Store) {
                // la tienda personal tiene sus propias reglas (precio, abierta o cerrada): ver StoreService
                throw new InventoryException('inventory.error_no_item');
            }

            if ($to === Container::Prize && $from !== Container::Prize) {
                throw new InventoryException('pvp.error_prize_only_out');
            }
            if ($from === Container::Duel || $to === Container::Duel) {
                $this->assertStakeFree($account);
            }

            $stored = $this->items->find($from, self::owner($from, $account, $character), $fromSlot)
                ?? throw new InventoryException('inventory.error_no_item');
            $item = $stored->item;
            $targetOwner = self::owner($to, $account, $character);
            $targetItems = $this->items->stored($to, $targetOwner);

            if ($to === Container::Equipment) {
                $slot = EquipmentSlot::tryFrom($toSlot) ?? throw new InventoryException('inventory.error_wrong_slot');
                $others = [];
                foreach ($targetItems as $other) {
                    if ($other->id !== $stored->id) {
                        $others[$other->slot] = $other->item;
                    }
                }
                if (isset($others[$slot->value])) {
                    throw new InventoryException('inventory.error_no_space');
                }
                $error = EquipRules::check($item, $slot, $others, $character);
                if ($error !== null) {
                    throw new InventoryException($error);
                }
            } elseif (!Grid::fits(ItemRepository::placed($targetItems, $stored->id), $toSlot, $item->width, $item->height, $to->rows())) {
                throw new InventoryException('inventory.error_no_space');
            }

            $this->items->relocate($stored->id, $to, $targetOwner, $toSlot);
        });
    }

    /**
     * Usa un ítem del inventario: poción, orbe o pergamino de habilidad, o fruta.
     *
     * @return array{key: string, params: array<string, int|string>} mensaje del resultado
     *
     * @throws InventoryException
     */
    public function use(Account $account, Character $character, int $slot): array
    {
        return $this->transaction->run($account->id, function () use ($character, $slot): array {
            $this->assertNotInBattle($character);
            $stored = $this->items->find(Container::Inventory, $character->id, $slot)
                ?? throw new InventoryException('inventory.error_no_item');
            $item = $stored->item;

            $result = match ($item->type) {
                'F' => $this->drink($character, $item),
                'G' => $this->learn($character, $item),
                'J' => $this->eatFruit($character, $item),
                'K' => $this->openEvent($character, $item),
                default => throw new InventoryException('inventory.error_not_usable'),
            };

            $this->items->delete($stored->id);

            return $result;
        });
    }

    /**
     * Aplica una joya del inventario sobre otro ítem del inventario.
     *
     * @return array{key: string, params: array<string, int|string>}
     *
     * @throws InventoryException
     */
    public function applyJewel(Account $account, Character $character, int $jewelSlot, int $targetSlot): array
    {
        return $this->transaction->run($account->id, function () use ($character, $jewelSlot, $targetSlot): array {
            $this->assertNotInBattle($character);
            $jewel = $this->items->find(Container::Inventory, $character->id, $jewelSlot);
            $target = $this->items->find(Container::Inventory, $character->id, $targetSlot);
            if ($jewel === null || $target === null || $jewel->item->type !== 'D') {
                throw new InventoryException('inventory.error_no_item');
            }

            [$upgraded, $outcome] = Jewels::apply($jewel->item, $target->item, $this->rng);

            $this->items->delete($jewel->id);
            if ($upgraded !== null) {
                $this->items->updateCode($target->id, $upgraded);
            }

            return ['key' => 'inventory.jewel_' . $outcome, 'params' => ['name' => ($upgraded ?? $target->item)->displayName()]];
        });
    }

    /** Con una batalla abierta no se toca el inventario: cambiaría las estadísticas a mitad del combate. */
    private function assertNotInBattle(Character $character): void
    {
        if ($this->battles->find($character->id) !== null) {
            throw new InventoryException('inventory.error_in_battle');
        }
    }

    /** La apuesta de un duelo en curso no se toca: el rival ya la vio (o está por verla) tal como está. */
    private function assertStakeFree(Account $account): void
    {
        if ($this->duels === null || $this->clock === null) {
            return;
        }
        if ($this->duels->openFor($account->id, $this->clock->now())?->stakeLocked($account->id)) {
            throw new InventoryException('pvp.error_stake_locked');
        }
    }

    /** @return array{key: string, params: array<string, int|string>} */
    private function drink(Character $character, Item $potion): array
    {
        $stats = $this->calculator->calculate($character, $this->items->items(Container::Equipment, $character->id));
        $hp = min($character->hpCur, $stats->hpMax);
        $mp = min($character->mpCur, $stats->mpMax);

        if (in_array($potion->subType, ['1', '2', '3', '4', '5'], true)) {
            $amount = min($stats->hpMax - $hp, intdiv($stats->hpMax * $potion->effect, 100));
            if ($amount < 1) {
                throw new InventoryException('inventory.error_already_full'); // no se desperdicia la poción
            }
            $this->characters->setVitals($character->id, $hp + $amount, $mp);

            return ['key' => 'inventory.used_hp', 'params' => ['n' => $amount]];
        }
        if (in_array($potion->subType, ['6', '7', '8', '9'], true)) {
            $amount = min($stats->mpMax - $mp, intdiv($stats->mpMax * $potion->effect, 100));
            if ($amount < 1) {
                throw new InventoryException('inventory.error_already_full');
            }
            $this->characters->setVitals($character->id, $hp, $mp + $amount);

            return ['key' => 'inventory.used_mp', 'params' => ['n' => $amount]];
        }

        // El antídoto sólo sirve contra el veneno, que existe únicamente en batalla.
        throw new InventoryException('inventory.error_only_in_battle');
    }

    /** @return array{key: string, params: array<string, int|string>} */
    private function learn(Character $character, Item $scroll): array
    {
        // El campo de durabilidad máxima del orbe o pergamino guarda el código de la habilidad que enseña.
        $code = (string) $scroll->durabilityMax;
        if (!$this->skills->has($code)) {
            throw new InventoryException('inventory.error_not_usable');
        }
        if (in_array($code, $character->skills, true)) {
            throw new InventoryException('inventory.error_skill_known');
        }
        foreach ($scroll->requirements() as ['kind' => $kind, 'value' => $value]) {
            if ($value > $kind->valueOf($character)) {
                throw new InventoryException('inventory.error_requirement');
            }
        }
        if (!$scroll->canBeUsedBy($character->class)) {
            throw new InventoryException('inventory.error_class');
        }

        $this->characters->addSkill($character->id, $code);

        return ['key' => 'inventory.used_skill', 'params' => ['name' => $this->skills->name($code)]];
    }

    /**
     * Lost Map: da entrada al nivel de Kalima que indica su nivel.
     *
     * @return array{key: string, params: array<string, int|string>}
     */
    private function openEvent(Character $character, Item $ticket): array
    {
        // Cloak of Invisibility y Devil's Invitation: el nivel del evento lo da el nivel del personaje.
        $scheduled = ['2' => 'blood_castle', '3' => 'devil_square'][$ticket->subType] ?? null;
        if ($scheduled !== null) {
            $map = ScheduledEvents::mapFor($scheduled, $character->level);
            $this->characters->setEventMap($character->id, $map);

            return ['key' => 'inventory.used_ticket', 'params' => ['map' => Monster::label($map)]];
        }

        $map = 'kalima_' . $ticket->level;
        if ($ticket->subType !== '5' || !MapCatalog::isEvent($map)) {
            throw new InventoryException('inventory.error_not_usable');
        }
        $this->characters->setEventMap($character->id, $map);

        return ['key' => 'inventory.used_lost_map', 'params' => ['n' => $ticket->level]];
    }

    /** @return array{key: string, params: array<string, int|string>} */
    private function eatFruit(Character $character, Item $fruit): array
    {
        $stat = self::FRUIT_STATS[$fruit->subType] ?? throw new InventoryException('inventory.error_not_usable');

        // Cada fruta que funcionó baja 1% la probabilidad de la siguiente.
        if ($this->rng->int(1, 100) <= $character->fruits) {
            return ['key' => 'inventory.fruit_failed', 'params' => []];
        }

        $points = $this->rng->int(1, 3);
        $this->characters->applyFruit($character->id, $stat, $points);

        return ['key' => 'inventory.fruit_ok', 'params' => ['n' => $points, 'stat' => $stat]];
    }
}
