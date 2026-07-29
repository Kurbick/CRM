<?php

namespace App\Http\Responses;

use App\Services\UserSessionService;
use App\Support\Navigation\AuthorizedLandingPage;
use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;

final class PasswordUpdateResponse implements PasswordUpdateResponseContract
{
    public function __construct(
        private readonly UserSessionService $sessions,
        private readonly AuthorizedLandingPage $landingPage
    ) {}

    public function toResponse($request)
    {
        $this->sessions->deleteOtherSessions($request->user(), $request->session()->getId());
        $request->session()->migrate(true);
        $request->session()->forget('url.intended');

        return redirect()->to($this->landingPage->url($request->user()))
            ->with('success', 'Пароль успешно изменён.');
    }
}
