<?php

declare(strict_types=1);

use Mu\App;
use Mu\Support\Config;
use Mu\Support\Database;
use Mu\Support\SystemClock;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$config = Config::fromEnv();

App::create($config, Database::connect($config), new SystemClock(), $root)->run();
