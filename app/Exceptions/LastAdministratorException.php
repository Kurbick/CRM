<?php

namespace App\Exceptions;

use DomainException;

class LastAdministratorException extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('admin.errors.last_administrator'));
    }
}
