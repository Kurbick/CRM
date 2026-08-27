<?php

namespace App\Exceptions;

use DomainException;

final class RoleDeletionException extends DomainException
{
    public static function systemRole(): self
    {
        return new self(__('admin.errors.system_role_delete'));
    }

    public static function assignedRole(): self
    {
        return new self(__('admin.errors.assigned_role_delete'));
    }
}
