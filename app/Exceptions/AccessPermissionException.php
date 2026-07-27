<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AccessPermissionException extends HttpException
{
    public static function administratorIsImmutable(): self
    {
        return new self(403, 'Права группы „Администратор“ управляются системой и не могут быть изменены.');
    }
}
