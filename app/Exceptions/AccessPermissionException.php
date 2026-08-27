<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AccessPermissionException extends HttpException
{
    public static function administratorIsImmutable(): self
    {
        return new self(403, __('admin.errors.system_role_permissions'));
    }
}
