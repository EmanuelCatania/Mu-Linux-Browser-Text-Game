<?php

declare(strict_types=1);

// Da o quita permisos de administrador a una cuenta.
// Uso (desde Mu-Browser):  docker compose exec mu-web php bin/admin.php <usuario> [--revoke]

use Mu\Support\Config;
use Mu\Support\Database;

require dirname(__DIR__) . '/vendor/autoload.php';

$username = $argv[1] ?? '';
if ($username === '' || str_starts_with($username, '-')) {
    fwrite(STDERR, "Uso: php bin/admin.php <usuario> [--revoke]\n");
    exit(1);
}
$grant = !in_array('--revoke', $argv, true);

$pdo = Database::connect(Config::fromEnv());
$stmt = $pdo->prepare('UPDATE accounts SET is_admin = ? WHERE LOWER(username) = LOWER(?)');
$stmt->execute([$grant ? 1 : 0, $username]);
if ($stmt->rowCount() === 0) {
    $exists = $pdo->prepare('SELECT 1 FROM accounts WHERE LOWER(username) = LOWER(?)');
    $exists->execute([$username]);
    if ($exists->fetchColumn() === false) {
        fwrite(STDERR, "No existe la cuenta $username.\n");
        exit(1);
    }
}
echo ($grant ? 'Ahora es administrador: ' : 'Ya no es administrador: ') . $username . PHP_EOL;
