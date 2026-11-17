<?php

declare(strict_types=1);

namespace Mu\Domain\Guild;

use Mu\Data\AccountRepository;
use Mu\Data\CharacterRepository;
use Mu\Data\GuildRepository;
use Mu\Data\NotificationRepository;
use Mu\Data\Transaction;
use Mu\Domain\Account\Account;
use Mu\Domain\Character\Character;
use Mu\Domain\Inventory\InventoryException;
use Mu\Support\Clock;

/**
 * Clanes (port de guild.php). Pertenecer a uno da más experiencia al pelear en el mismo spot que
 * otros miembros. Sólo el fundador invita, expulsa y disuelve.
 */
final class GuildService
{
    public const int CREATE_PRICE = 1000;
    public const int CREATE_LEVEL = 10;
    /** Miembros más invitaciones pendientes. */
    public const int MAX_MEMBERS = 20;
    public const string NAME_PATTERN = '/^[A-Za-z0-9]{3,12}$/';

    public function __construct(
        private readonly GuildRepository $guilds,
        private readonly CharacterRepository $characters,
        private readonly AccountRepository $accounts,
        private readonly Transaction $transaction,
        private readonly Clock $clock,
        private readonly ?NotificationRepository $notifications = null,
    ) {
    }

    /** @return array{id: int, name: string, founder_id: int}|null */
    public function of(Character $character): ?array
    {
        return $character->guildId === null ? null : $this->guilds->find($character->guildId);
    }

    /** @return array{id: int, name: string, founder_id: int}|null clan que lo invitó */
    public function invitation(Character $character): ?array
    {
        return $character->guildInviteId === null ? null : $this->guilds->find($character->guildInviteId);
    }

    /** @return list<array{id: int, name: string, class: int, level: int, invited: bool}> */
    public function roster(int $guildId): array
    {
        return $this->guilds->roster($guildId);
    }

    /** @throws InventoryException */
    public function create(Account $account, Character $character, string $name): void
    {
        $name = trim($name);
        $this->transaction->run($account->id, function () use ($account, $character, $name): void {
            if ($character->guildId !== null) {
                throw new InventoryException('guild.error_member');
            }
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new InventoryException('guild.error_name');
            }
            if ($this->guilds->nameExists($name)) {
                throw new InventoryException('guild.error_taken');
            }
            if ($character->level < self::CREATE_LEVEL) {
                throw new InventoryException('guild.error_level');
            }
            if (!$this->accounts->spendZen($account->id, self::CREATE_PRICE)) {
                throw new InventoryException('shop.error_no_money');
            }
            $this->guilds->create($name, $character->id, $this->clock->now());
        });
    }

    /** @throws InventoryException */
    public function invite(Account $account, Character $founder, string $name): void
    {
        $this->transaction->run($account->id, function () use ($founder, $name): void {
            $guild = $this->foundedBy($founder);
            $target = $this->characters->findByName(trim($name)) ?? throw new InventoryException('guild.error_no_character');
            if ($target->guildId !== null || $target->guildInviteId !== null) {
                throw new InventoryException('guild.error_taken_character');
            }
            if (count($this->guilds->roster($guild['id'])) >= self::MAX_MEMBERS) {
                throw new InventoryException('guild.error_full');
            }
            $this->guilds->setMembership($target->id, null, $guild['id']);
            $this->notifications?->notify($target->accountId, 'notify.guild_invited', ['guild' => $guild['name'], 'target' => $target->name], '/guild');
        });
    }

    /** Acepta o rechaza la invitación pendiente. @throws InventoryException */
    public function answer(Account $account, Character $character, bool $accept): void
    {
        $this->transaction->run($account->id, function () use ($character, $accept): void {
            if ($character->guildInviteId === null) {
                throw new InventoryException('guild.error_no_invite');
            }
            $this->guilds->setMembership($character->id, $accept ? $character->guildInviteId : null, null);
        });
    }

    /** @throws InventoryException */
    public function quit(Account $account, Character $character): void
    {
        $this->transaction->run($account->id, function () use ($character): void {
            $guild = $this->of($character) ?? throw new InventoryException('guild.error_not_member');
            if ($guild['founder_id'] === $character->id) {
                throw new InventoryException('guild.error_founder_quit'); // el fundador sólo puede disolver
            }
            $this->guilds->setMembership($character->id, null, null);
        });
    }

    /** Expulsa a un miembro o retira una invitación. @throws InventoryException */
    public function kick(Account $account, Character $founder, int $characterId): void
    {
        $this->transaction->run($account->id, function () use ($founder, $characterId): void {
            $guild = $this->foundedBy($founder);
            if ($characterId === $founder->id) {
                throw new InventoryException('guild.error_founder_quit');
            }
            $target = $this->characters->findById($characterId);
            if ($target === null || ($target->guildId !== $guild['id'] && $target->guildInviteId !== $guild['id'])) {
                throw new InventoryException('guild.error_no_character');
            }
            $this->guilds->setMembership($target->id, null, null);
            if ($target->guildId === $guild['id']) {
                $this->notifications?->notify($target->accountId, 'notify.guild_kicked', ['guild' => $guild['name'], 'target' => $target->name], '/guild');
            }
        });
    }

    /** @throws InventoryException */
    public function disband(Account $account, Character $founder): void
    {
        $this->transaction->run($account->id, fn () => $this->guilds->delete($this->foundedBy($founder)['id']));
    }

    /** @return array{id: int, name: string, founder_id: int} */
    private function foundedBy(Character $character): array
    {
        $guild = $this->of($character);
        if ($guild === null || $guild['founder_id'] !== $character->id) {
            throw new InventoryException('guild.error_not_founder');
        }

        return $guild;
    }
}
