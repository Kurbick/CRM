<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class UserSessionService
{
    public function deleteOtherSessions(User $user, ?string $currentSessionId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $query = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey());

        if ($currentSessionId !== null && $currentSessionId !== '') {
            $query->where('id', '!=', $currentSessionId);
        }

        $query->delete();
    }

    public function deleteAllSessions(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }
}
