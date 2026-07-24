<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

final class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        return $request->expectsJson()
            ? response()->noContent()
            : redirect()->route('login');
    }
}
