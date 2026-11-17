<?php

declare(strict_types=1);

use Mu\Support\Config;
use Mu\Support\Database;
use Mu\Support\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnv();

// MySQL puede tardar unos segundos más que su healthcheck en aceptar al usuario de la app.
$pdo = null;
for ($attempt = 1; $attempt <= 30; $attempt++) {
    try {
        $pdo = Database::connect($config);
        break;
    } catch (PDOException $e) {
        if ($attempt === 30) {
            fwrite(STDERR, 'migrate: no se pudo conectar a la base: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        sleep(2);
    }
}

$applied = new Migrator($pdo, dirname(__DIR__) . '/migrations')->migrate();
echo $applied === []
    ? 'migrate: esquema al día' . PHP_EOL
    : 'migrate: aplicadas ' . implode(', ', $applied) . PHP_EOL;
