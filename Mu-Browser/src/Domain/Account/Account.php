<?php

declare(strict_types=1);

namespace Mu\Domain\Account;

final readonly class Account
{
    public function __construct(
        public int $id,
        public string $username,
        public string $email,
        public string $passwordHash,
        public string $locale,
        public int $zen = 5000,
        public ?int $activeCharacterId = null,
        public bool $storeOpen = false,
        public bool $isAdmin = false,
        public bool $banned = false,
    ) {
    }
}
