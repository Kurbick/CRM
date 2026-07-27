<?php

namespace App\Exceptions;

use DomainException;

final class RoleDeletionException extends DomainException
{
    public static function systemRole(): self
    {
        return new self('Нельзя удалить системную группу.');
    }

    public static function assignedRole(): self
    {
        return new self('Нельзя удалить группу, пока она назначена пользователям.');
    }
}
