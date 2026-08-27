<?php

namespace App\Actions\Admin\Users;

use App\Models\User;
use App\Services\UserSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ResetUserPassword
{
    public function __construct(private readonly UserSessionService $sessions) {}

    public function handle(User $actor, User $user, string $password): void
    {
        if ($actor->is($user)) {
            $exception = ValidationException::withMessages([
                'password' => __('admin.errors.self_password'),
            ]);
            $exception->errorBag = 'resetPassword';
            throw $exception;
        }

        DB::transaction(function () use ($user, $password): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
            $this->sessions->invalidateAllFor($lockedUser);
            $lockedUser->tokens()->delete();
            $lockedUser->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => true,
                'password_changed_at' => null,
                'remember_token' => Str::random(60),
            ])->save();
            $user->refresh();
        });
    }
}
