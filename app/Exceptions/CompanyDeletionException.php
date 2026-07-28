<?php

namespace App\Exceptions;

use DomainException;
use Throwable;

final class CompanyDeletionException extends DomainException
{
    public static function dependencies(): self
    {
        return new self('Невозможно удалить компанию, пока с ней связаны контакты, договоры или финансовые данные.');
    }

    public static function concurrentDependency(Throwable $previous): self
    {
        return new self(
            'Невозможно удалить компанию: во время операции появились связанные данные.',
            0,
            $previous
        );
    }
}
