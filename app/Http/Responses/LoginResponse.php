<?php

namespace App\Http\Responses;

use App\Support\Navigation\AuthorizedLandingPage;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

final class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly AuthorizedLandingPage $landingPage) {}

    public function toResponse($request)
    {
        $request->session()->forget('url.intended');

        if ($request->user()->mustChangePassword()) {
            return redirect()->route('password.change');
        }

        return redirect()->to($this->landingPage->url($request->user()));
    }
}
