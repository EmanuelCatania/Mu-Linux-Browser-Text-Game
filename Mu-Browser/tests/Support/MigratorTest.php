<?php

declare(strict_types=1);

namespace Mu\Tests\Support;

use Mu\Support\Migrator;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    public function testSplitsStatementsOnTrailingSemicolons(): void
    {
        $sql = "CREATE TABLE a (x INT);\r\n\r\nINSERT INTO a VALUES (1);\nSELECT ';' FROM a";

        self::assertSame(
            ['CREATE TABLE a (x INT)', 'INSERT INTO a VALUES (1)', "SELECT ';' FROM a"],
            Migrator::statements($sql),
        );
    }

    public function testAppliesEachMigrationOnce(): void
    {
        $dir = sys_get_temp_dir() . '/mu-migrations-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0001_a.sql', "CREATE TABLE a (x INTEGER);\nINSERT INTO a VALUES (1);\n");
        file_put_contents($dir . '/0002_b.sql', "CREATE TABLE b (y INTEGER);\n");

        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $migrator = new Migrator($pdo, $dir);

        self::assertSame(['0001_a', '0002_b'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM a')->fetchColumn());

        file_put_contents($dir . '/0003_c.sql', "CREATE TABLE c (z INTEGER);\n");
        self::assertSame(['0003_c'], $migrator->migrate());

        array_map(unlink(...), glob($dir . '/*.sql') ?: []);
        rmdir($dir);
    }
}
