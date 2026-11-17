<?php

declare(strict_types=1);

namespace Mu\Support;

use RuntimeException;

/** Configuración de la aplicación, leída únicamente de variables de entorno. */
final readonly class Config
{
    public function __construct(
        public string $dbHost,
        public int $dbPort,
        public string $dbUser,
        public string $dbPass,
        public string $dbName,
        public string $sessionSecret,
        public bool $trustProxy,
        public string $timezone,
    ) {
    }

    public static function fromEnv(): self
    {
        $secret = self::required('SESSION_SECRET');
        if (strlen($secret) < 32) {
            throw new RuntimeException('SESSION_SECRET debe tener al menos 32 caracteres (openssl rand -hex 32).');
        }

        return new self(
            dbHost: self::required('DB_HOST'),
            dbPort: (int) (self::optional('DB_PORT') ?? '3306'),
            dbUser: self::required('DB_USER'),
            dbPass: self::required('DB_PASS'),
            dbName: self::required('DB_NAME'),
            sessionSecret: $secret,
            trustProxy: self::optional('TRUST_PROXY') === '1',
            timezone: self::optional('TIMEZONE') ?? 'UTC',
        );
    }

    private static function required(string $name): string
    {
        $value = self::optional($name);
        if ($value === null) {
            throw new RuntimeException("Falta la variable de entorno $name.");
        }

        return $value;
    }

    private static function optional(string $name): ?string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? null : $value;
    }
}
