<?php

declare(strict_types=1);

namespace Mu\Tests\Support;

use PDO;

final class TestDatabase
{
    /** Base SQLite en memoria con el esquema de tests/schema.sqlite.sql. */
    public static function create(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec((string) file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql'));

        return $pdo;
    }
}
