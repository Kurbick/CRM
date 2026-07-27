<?php

namespace App\Actions\Admin\Users;

use App\Models\User;
use App\Services\AdministratorProtectionService;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SetUserActiveStatus
{
    public function __construct(
        private readonly AdministratorProtectionService $protection,
        private readonly UserSessionService $sessions,
    ) {}

    public function handle(User $actor, User $user, bool $active): void
    {
        if (! $active && $actor->is($user)) {
            throw ValidationException::withMessages([
                'status' => 'Нельзя отключить собственную учётную запись.',
            ]);
        }

        DB::transaction(function () use ($user, $active): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $active) {
                $this->protection->assertCanDeactivate($lockedUser);
                $this->sessions->invalidateAllFor($lockedUser);
                $lockedUser->tokens()->delete();
                $lockedUser->forceFill([
                    'is_active' => false,
                    'remember_token' => Str::random(60),
                ])->save();
            } else {
                $lockedUser->forceFill(['is_active' => true])->save();
            }

            $user->refresh();
        });
    }
}
