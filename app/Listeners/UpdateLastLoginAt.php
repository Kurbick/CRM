<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

final class UpdateLastLoginAt
{
    public function handle(Login $event): void
    {
        if (!$event->user instanceof User) {
            return;
        }

        User::withoutTimestamps(function () use ($event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
