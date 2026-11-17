<?php

declare(strict_types=1);

namespace Mu\Support;

use DateTimeImmutable;

/** Fuente de la hora actual (UTC). Inyectable para poder fijarla en los tests. */
interface Clock
{
    public const string SQL_FORMAT = 'Y-m-d H:i:s';

    public function now(): DateTimeImmutable;
}
