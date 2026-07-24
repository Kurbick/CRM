<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

final class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->user()->mustChangePassword()) {
            return redirect()->route('password.change');
        }

        return redirect()->intended(route('dashboard'));
    }
}
