<?php

namespace App\Exceptions;

use DomainException;
use Throwable;

final class SubscriptionDeletionException extends DomainException
{
    private const MESSAGE = 'Невозможно удалить подписку, поскольку она уже используется в инвойсе.';

    public static function dependencies(): self
    {
        return new self(self::MESSAGE);
    }

    public static function concurrentDependency(Throwable $previous): self
    {
        return new self(self::MESSAGE, 0, $previous);
    }
}
