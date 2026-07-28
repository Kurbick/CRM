<?php

namespace App\Exceptions;

use DomainException;
use Throwable;

final class ContractDeletionException extends DomainException
{
    public static function dependencies(): self
    {
        return new self('Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.');
    }

    public static function concurrentDependency(Throwable $previous): self
    {
        return new self(
            'Невозможно удалить договор, пока с ним связаны предметы, документы или инвойсы.',
            0,
            $previous
        );
    }
}
