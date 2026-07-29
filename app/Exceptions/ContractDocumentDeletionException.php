<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ContractDocumentDeletionException extends RuntimeException
{
    public static function storageFailed(?Throwable $previous = null): self
    {
        return new self('Не удалось удалить документ.', 0, $previous);
    }

    public static function databaseFailed(Throwable $previous): self
    {
        return new self('Не удалось удалить документ.', 0, $previous);
    }
}
