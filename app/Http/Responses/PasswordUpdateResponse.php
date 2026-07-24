<?php

namespace App\Http\Responses;

use App\Services\UserSessionService;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

final class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function __construct(private readonly UserSessionService $sessions)
    {
    }

    public function toResponse($request)
    {
        $this->sessions->deleteOtherSessions($request->user(), $request->session()->getId());
        $request->session()->migrate(true);

        return redirect()->route('dashboard')->with('success', 'Пароль успешно изменён.');
    }
}
