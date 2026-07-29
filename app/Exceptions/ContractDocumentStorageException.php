<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ContractDocumentStorageException extends RuntimeException
{
    public static function writeFailed(?Throwable $previous = null): self
    {
        return new self('Не удалось сохранить документ.', 0, $previous);
    }

    public static function databaseFailed(Throwable $previous): self
    {
        return new self('Не удалось сохранить документ.', 0, $previous);
    }
}
