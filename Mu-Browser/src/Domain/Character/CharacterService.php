<?php

declare(strict_types=1);

namespace Mu\Domain\Character;

use Mu\Domain\Battle\Battle;
use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Domain\Account\Account;
use Mu\Domain\Account\ValidationException;
use Mu\Support\Clock;

/** Crear, borrar y elegir personajes, y repartir puntos de atributo. */
final class CharacterService
{
    public const int MAX_PER_ACCOUNT = 3;

    /** Igual que el original: alfanumérico de 3 a 12 caracteres. */
    public const string NAME_PATTERN = '/^[A-Za-z0-9]{3,12}$/';

    public function __construct(
        private readonly CharacterRepository $characters,
        private readonly AccountRepository $accounts,
        private readonly Clock $clock,
        private readonly int $resetZen = 10_000_000,
        private readonly bool $resetEnabled = true,
    ) {
    }

    public function resetEnabled(): bool
    {
        return $this->resetEnabled;
    }

    public function resetZen(): int
    {
        return $this->resetZen;
    }

    /**
     * Reset: al llegar al nivel máximo, y pagando zen, el personaje activo vuelve al nivel 1.
     * Conserva atributos, puntos, habilidades, clase y equipo, y puede repetir las misiones.
     *
     * @throws ValidationException
     */
    public function reset(Account $account): void
    {
        if (!$this->resetEnabled) {
            throw new ValidationException(['reset.error_disabled']);
        }
        $character = $this->active($account) ?? throw new ValidationException(['error.character_not_found']);
        if ($character->level < Battle::MAX_LEVEL) {
            throw new ValidationException(['reset.error_level']);
        }
        if (!$this->accounts->spendZen($account->id, $this->resetZen)) {
            throw new ValidationException(['shop.error_no_money']);
        }
        if (!$this->characters->reset($character->id, Battle::MAX_LEVEL)) {
            $this->accounts->addZen($account->id, $this->resetZen);
            throw new ValidationException(['reset.error_level']);
        }
    }

    /** @return list<Character> en orden de creación */
    public function listFor(Account $account): array
    {
        return $this->characters->findByAccount($account->id);
    }

    public function active(Account $account): ?Character
    {
        if ($account->activeCharacterId === null) {
            return null;
        }

        return $this->owned($account, $account->activeCharacterId);
    }

    /**
     * Clases que la cuenta puede crear: las cuatro iniciales más las que ya desbloqueó.
     *
     * @return list<CharacterClass>
     */
    public function creatable(Account $account): array
    {
        $top = 0;
        foreach ($this->characters->findByAccount($account->id) as $character) {
            // Tras un reset el nivel vuelve a 1, pero lo que desbloqueó sigue desbloqueado.
            $top = max($top, $character->resets > 0 ? PHP_INT_MAX : $character->level);
        }
        $classes = CharacterClass::CREATABLE;
        foreach (CharacterClass::UNLOCKABLE as $class => $level) {
            if ($top >= $level) {
                $classes[] = CharacterClass::from($class);
            }
        }

        return $classes;
    }

    /** @throws ValidationException */
    public function create(Account $account, string $name, int $classId): Character
    {
        $name = trim($name);
        $class = CharacterClass::tryFrom($classId);

        $errors = [];
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = 'error.character_name_format';
        } elseif ($this->characters->nameExists($name)) {
            $errors[] = 'error.character_name_taken';
        }
        if ($class === null || !in_array($class, $this->creatable($account), true)) {
            $errors[] = 'error.character_class';
        }
        if ($this->characters->countByAccount($account->id) >= self::MAX_PER_ACCOUNT) {
            $errors[] = 'error.character_limit';
        }
        if ($errors !== [] || $class === null) {
            throw new ValidationException($errors);
        }

        $character = $this->characters->insert($account->id, $name, $class, $this->clock->now());

        // El primer personaje queda activo solo, como en el original.
        if ($this->active($account) === null) {
            $this->accounts->setActiveCharacter($account->id, $character->id);
        }

        return $character;
    }

    /** @throws ValidationException si el personaje no es de la cuenta */
    public function select(Account $account, int $characterId): void
    {
        $character = $this->owned($account, $characterId)
            ?? throw new ValidationException(['error.character_not_found']);

        $this->accounts->setActiveCharacter($account->id, $character->id);
    }

    /** @throws ValidationException si el personaje no es de la cuenta */
    public function delete(Account $account, int $characterId): void
    {
        $character = $this->owned($account, $characterId)
            ?? throw new ValidationException(['error.character_not_found']);

        $this->characters->delete($character->id);

        // Si era el activo, pasa a serlo el más antiguo que quede.
        if ($account->activeCharacterId === $character->id) {
            $remaining = $this->characters->findByAccount($account->id);
            $this->accounts->setActiveCharacter($account->id, $remaining[0]->id ?? null);
        }
    }

    /**
     * Pasa un punto libre del personaje activo al atributo indicado.
     *
     * @throws ValidationException
     */
    public function addStatPoint(Account $account, string $stat): void
    {
        $character = $this->active($account) ?? throw new ValidationException(['error.character_not_found']);

        if (!in_array($stat, $character->class->stats(), true)) {
            throw new ValidationException(['error.stat_invalid']);
        }
        // El UPDATE vuelve a exigir points > 0: dos pedidos simultáneos no pueden gastar el mismo punto.
        if (!$this->characters->spendPoint($character->id, $stat)) {
            throw new ValidationException(['error.no_points']);
        }
    }

    private function owned(Account $account, int $characterId): ?Character
    {
        $character = $this->characters->findById($characterId);

        return $character !== null && $character->accountId === $account->id ? $character : null;
    }
}
