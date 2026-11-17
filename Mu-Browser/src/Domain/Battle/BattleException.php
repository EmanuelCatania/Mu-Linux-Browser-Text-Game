<?php

declare(strict_types=1);

namespace Mu\Domain\Battle;

use RuntimeException;

/** Acción de batalla que no se puede hacer. El mensaje es una clave de traducción. */
final class BattleException extends RuntimeException
{
}
