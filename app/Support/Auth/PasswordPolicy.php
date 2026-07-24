<?php

namespace App\Support\Auth;

use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(12)->mixedCase()->numbers()->symbols();
    }
}
