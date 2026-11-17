<?php

declare(strict_types=1);

namespace Mu\Support;

use PDO;

/**
 * Aplica en orden los archivos migrations/*.sql que todavía no se registraron en schema_migrations.
 * Las migraciones son sólo hacia adelante: para cambiar el esquema se agrega un archivo nuevo.
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo, private readonly string $dir)
    {
    }

    /** @return list<string> versiones aplicadas en esta corrida */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            )'
        );

        $done = $this->pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }

            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $this->pdo->exec($sql);
            }

            $this->pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')
                ->execute([$version, gmdate(Clock::SQL_FORMAT)]);
            $applied[] = $version;
        }

        return $applied;
    }

    /**
     * Separa un archivo en sentencias por ";" al final de línea.
     * Alcanza mientras las migraciones no definan procedimientos ni triggers.
     *
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $parts = preg_split('/;[ \t]*(\r?\n|$)/', $sql) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
