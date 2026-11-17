<?php

declare(strict_types=1);

// Al arrancar el contenedor: si todavía no hay ninguna cuenta administradora, muestra en el log el código
// para crearla desde el navegador (pantalla /setup). Si ya hay, no hace nada.

use Mu\Data\AccountRepository;
use Mu\Data\LoginAttemptRepository;
use Mu\Domain\Account\AccountService;
use Mu\Domain\Admin\FirstRun;
use Mu\Support\Config;
use Mu\Support\Database;
use Mu\Support\SystemClock;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = Database::connect(Config::fromEnv());
$clock = new SystemClock();
$accounts = new AccountRepository($pdo);
$attempts = new LoginAttemptRepository($pdo);
$code = new FirstRun($pdo, $accounts, new AccountService($accounts, $attempts, $clock), $attempts, $clock)->code();
if ($code === null) {
    exit(0);
}

$line = str_repeat('=', 64);
echo PHP_EOL . $line . PHP_EOL
    . '  PRIMER INICIO: todavía no hay administrador.' . PHP_EOL
    . '  Abrí el sitio en el navegador y usá este código para crearlo:' . PHP_EOL . PHP_EOL
    . '      ' . implode('-', str_split($code, 5)) . PHP_EOL . PHP_EOL
    . '  (FIRST RUN: open the site and use this code to create the admin account)' . PHP_EOL
    . $line . PHP_EOL . PHP_EOL;
