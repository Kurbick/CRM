<?php

namespace App\Exceptions;

use DomainException;

class LastAdministratorException extends DomainException
{
    public function __construct()
    {
        parent::__construct('Нельзя отключить или лишить группы последнего активного администратора.');
    }
}
