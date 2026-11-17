<?php

declare(strict_types=1);

namespace Mu\Domain\Inventory;

use Mu\Domain\Character\Character;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\Requirement;
use Mu\Domain\Stats\EquipmentSlot;

/** Qué ítem puede ir en qué slot de equipo y quién puede usarlo (port de function.move_item.php). */
final class EquipRules
{
    /**
     * @param array<int, Item> $equipment lo que ya tiene puesto, por slot, sin contar el ítem que se mueve
     *
     * @return ?string clave del motivo por el que no se puede, o null si se puede
     */
    public static function check(Item $item, EquipmentSlot $target, array $equipment, Character $character): ?string
    {
        if (!in_array($target, self::slotsFor($item), true)) {
            return 'inventory.error_wrong_slot';
        }

        $hand1 = $equipment[EquipmentSlot::Hand1->value] ?? null;
        $hand2 = $equipment[EquipmentSlot::Hand2->value] ?? null;

        // Un arma a dos manos sólo convive con su munición en la otra mano.
        if ($item->type === '2' && $hand2 !== null && !self::isAmmoFor($hand2, $item)) {
            return 'inventory.error_two_handed';
        }
        if ($target === EquipmentSlot::Hand2 && $hand1 !== null && $hand1->type !== '1' && !self::isAmmoFor($item, $hand1)) {
            return 'inventory.error_two_handed';
        }

        foreach (self::requirements($item) as ['kind' => $kind, 'value' => $value]) {
            if ($value > $kind->valueOf($character)) {
                return 'inventory.error_requirement';
            }
        }
        if (!$item->canBeUsedBy($character->class)) {
            return 'inventory.error_class';
        }

        return null;
    }

    /** @return list<EquipmentSlot> slots donde puede ir el ítem (vacío = no es equipable) */
    public static function slotsFor(Item $item): array
    {
        return match ($item->type) {
            '1' => [EquipmentSlot::Hand1, EquipmentSlot::Hand2],
            '2' => [EquipmentSlot::Hand1],
            '3' => [EquipmentSlot::Armor],
            '4' => [EquipmentSlot::Pants],
            '5' => [EquipmentSlot::Helm],
            '6' => [EquipmentSlot::Gloves],
            '7' => [EquipmentSlot::Boots],
            '8' => [EquipmentSlot::Wings],
            '9' => [EquipmentSlot::Hand2],
            'A' => [EquipmentSlot::Necklace],
            'B' => [EquipmentSlot::Ring1, EquipmentSlot::Ring2],
            // El Dark Raven va en la mano libre del Dark Lord, así puede llevarlo junto con el Dark Horse.
            'C' => $item->subType === 'B' ? [EquipmentSlot::Hand2] : [EquipmentSlot::Pet],
            default => [],
        };
    }

    /** Flechas (1/6) para arcos (2/8) y virotes (1/7) para ballestas (2/9). */
    public static function isAmmoFor(Item $ammo, Item $weapon): bool
    {
        return $ammo->type === '1' && $weapon->type === '2'
            && (($ammo->subType === '6' && $weapon->subType === '8') || ($ammo->subType === '7' && $weapon->subType === '9'));
    }

    /**
     * Requisitos del ítem, con la rebaja de fuerza o agilidad que dan algunas opciones harmony en las armas.
     *
     * @return list<array{kind: Requirement, value: int}>
     */
    private static function requirements(Item $item): array
    {
        $weapon = $item->type === '1' || $item->type === '2';
        $less = [
            Requirement::Strength->value => $weapon && $item->harmonyType === 3 ? $item->harmonyValue * 8 : 0,
            Requirement::Agility->value => $weapon && $item->harmonyType === 4 ? $item->harmonyValue * 8 : 0,
        ];

        return array_map(
            static fn (array $r): array => ['kind' => $r['kind'], 'value' => $r['value'] - ($less[$r['kind']->value] ?? 0)],
            $item->requirements(),
        );
    }
}
