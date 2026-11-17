<?php

declare(strict_types=1);

namespace Mu\Domain\Admin;

use DateTimeImmutable;
use Mu\Data\AccountRepository;
use Mu\Data\BattleRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\ItemRepository;
use Mu\Data\PresenceRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Account\ValidationException;
use Mu\Domain\Battle\Battle;
use Mu\Domain\Character\Character;
use Mu\Domain\Character\CharacterClass;
use Mu\Domain\Character\CharacterService;
use Mu\Domain\Character\MasterTree;
use Mu\Domain\Inventory\Container;
use Mu\Domain\Item\Item;
use Mu\Domain\Item\ItemCatalog;
use PDO;

/**
 * Lo que el panel de administración puede cambiar de una cuenta y de sus personajes.
 * No sigue las reglas del juego (para eso está el panel): sólo valida que los valores tengan sentido.
 */
final class AccountEditor
{
    /** Campo editable de un personaje → [mínimo, máximo]. */
    public const array CHARACTER_FIELDS = [
        'level' => [1, Battle::MAX_LEVEL],
        'experience' => [0, PHP_INT_MAX],
        'points' => [0, 1000000],
        'strength' => [1, 65000],
        'agility' => [1, 65000],
        'stamina' => [1, 65000],
        'energy' => [1, 65000],
        'command' => [0, 65000],
        'fruits' => [0, 1000],
        'resets' => [0, 10000],
        'evo_step' => [0, 14],
        'master_level' => [0, MasterTree::MAX_LEVEL],
        'master_points' => [0, 10000],
    ];
    /** Contenedores que el panel muestra de una cuenta, y de quién son. */
    public const array CHARACTER_CONTAINERS = [Container::Equipment, Container::Inventory];
    public const array ACCOUNT_CONTAINERS = [Container::Vault, Container::Store, Container::Duel, Container::Prize];

    public function __construct(
        private readonly PDO $pdo,
        private readonly AccountRepository $accounts,
        private readonly CharacterRepository $characters,
        private readonly ItemRepository $items,
        private readonly BattleRepository $battles,
        private readonly PresenceRepository $presence,
        private readonly ItemCatalog $catalog,
    ) {
    }

    /**
     * Cambia el email y, si se escribe, la contraseña.
     *
     * @throws ValidationException
     */
    public function updateAccount(Account $account, string $email, string $password): void
    {
        $email = trim($email);
        $errors = [];
        if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'error.email_format';
        } elseif ($this->accounts->emailExists($email, $account->id)) {
            $errors[] = 'error.email_taken';
        }
        $length = mb_strlen($password);
        if ($password !== '' && ($length < AccountService::PASSWORD_MIN || $length > 128)) {
            $errors[] = 'error.password_length';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->accounts->updateEmail($account->id, $email);
        if ($password !== '') {
            $this->accounts->updatePasswordHash($account->id, password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT));
            // con la contraseña nueva, los "recordarme" viejos dejan de valer
            $this->pdo->prepare('DELETE FROM remember_tokens WHERE account_id = ?')->execute([$account->id]);
        }
    }

    /**
     * Cambia nombre, clase y valores de un personaje. Si estaba en una batalla, lo saca (sin guardar esa ronda).
     *
     * @param array<string, string> $form
     *
     * @return list<string> campos inválidos (no se guardó nada si hay alguno)
     */
    public function updateCharacter(Character $character, array $form): array
    {
        $invalid = [];
        $values = [];
        foreach (self::CHARACTER_FIELDS as $field => [$min, $max]) {
            $text = trim((string) ($form[$field] ?? ''));
            if (!ctype_digit($text) || strlen($text) > 18 || (int) $text < $min || (int) $text > $max) {
                $invalid[] = $field;
            } else {
                $values[$field] = (int) $text;
            }
        }
        $class = CharacterClass::tryFrom((int) ($form['class'] ?? 0));
        if ($class === null) {
            $invalid[] = 'class';
        }
        $name = trim((string) ($form['name'] ?? ''));
        if (preg_match(CharacterService::NAME_PATTERN, $name) !== 1
            || (strcasecmp($name, $character->name) !== 0 && $this->characters->nameExists($name))) {
            $invalid[] = 'name';
        }
        if ($invalid !== []) {
            return $invalid;
        }

        // la experiencia tiene que caer dentro del nivel: si no, se acomoda al principio del nivel
        $level = $values['level'];
        if ($values['experience'] < Battle::experienceFor($level - 1) || $values['experience'] > Battle::experienceFor($level)) {
            $values['experience'] = Battle::experienceFor($level - 1);
        }
        $values['class'] = $class?->value;
        $values['name'] = $name;

        $this->leaveBattle($character->id);
        $set = implode(', ', array_map(static fn (string $c): string => "$c = ?", array_keys($values)));
        $this->pdo->prepare("UPDATE characters SET $set WHERE id = ?")->execute([...array_values($values), $character->id]);

        return [];
    }

    public function deleteCharacter(Character $character): void
    {
        $this->leaveBattle($character->id);
        $this->characters->delete($character->id);
    }

    /**
     * Da un ítem del catálogo al personaje (en el primer lugar libre del inventario).
     *
     * @param string $ref "tipo/nombre", como lo arma la lista del formulario
     *
     * @throws ValidationException
     */
    public function giveItem(Character $character, string $ref, int $level, bool $luck, int $option, int $excellent, DateTimeImmutable $now): Item
    {
        [$type, $name] = array_pad(explode('/', trim($ref), 2), 2, '');
        $base = null;
        foreach ($this->catalog->ofType($type) as $item) {
            if ($item->name === $name) {
                $base = $item;
                break;
            }
        }
        if ($base === null) {
            throw new ValidationException(['admin.error_item']);
        }
        $wearable = in_array($base->type, ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B'], true);
        $item = $base->with($wearable ? [
            'level' => max(0, min(15, $level)),
            'luck' => $luck ? 1 : 0,
            'option' => max(0, min(7, $option)),
            'excellent' => max(0, min(6, $excellent)),
        ] : []);
        $this->leaveBattle($character->id);
        if ($this->items->addToInventory($character->id, $item, $now) === null) {
            throw new ValidationException(['inventory.error_no_space']);
        }

        return $item;
    }

    /** Borra un ítem, sólo si es de esa cuenta o de uno de sus personajes. */
    public function deleteItem(Account $account, int $itemId): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM items WHERE id = ? AND (account_id = ? OR character_id IN (SELECT id FROM characters WHERE account_id = ?))'
        );
        $stmt->execute([$itemId, $account->id, $account->id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Todo lo que tiene la cuenta: por personaje (equipo e inventario) y de la cuenta (baúl, tienda, PvP).
     *
     * @return array{characters: array<int, array<string, list<array{id: int, slot: int, item: Item}>>>, account: array<string, list<array{id: int, slot: int, item: Item}>>}
     */
    public function itemsOf(Account $account): array
    {
        $list = static fn (array $stored): array => array_values(array_map(
            static fn ($s): array => ['id' => $s->id, 'slot' => $s->slot, 'item' => $s->item],
            $stored,
        ));
        $out = ['characters' => [], 'account' => []];
        foreach ($this->characters->findByAccount($account->id) as $character) {
            foreach (self::CHARACTER_CONTAINERS as $container) {
                $out['characters'][$character->id][$container->value] = $list($this->items->stored($container, $character->id));
            }
        }
        foreach (self::ACCOUNT_CONTAINERS as $container) {
            $out['account'][$container->value] = $list($this->items->stored($container, $account->id));
        }

        return $out;
    }

    public function inBattle(int $characterId): bool
    {
        return $this->battles->find($characterId) !== null;
    }

    /** Lo que se cambia desde el panel pisa la batalla en curso: se descarta para que no lo sobrescriba al cerrar la ronda. */
    private function leaveBattle(int $characterId): void
    {
        $this->battles->delete($characterId);
        $this->presence->remove($characterId);
    }
}
