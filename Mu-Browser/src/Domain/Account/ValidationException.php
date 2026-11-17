<?php

declare(strict_types=1);

namespace Mu\Domain\Account;

use RuntimeException;

/** Datos rechazados. Lleva claves de traducción (lang/*.php), no textos. */
final class ValidationException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(', ', $errors));
    }
}
