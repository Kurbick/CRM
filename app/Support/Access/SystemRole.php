<?php

namespace App\Support\Access;

enum SystemRole: string
{
    case Administrator = 'administrator';
    case Accountant = 'accountant';
    case Viewer = 'viewer';
}
