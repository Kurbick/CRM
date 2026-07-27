<?php

namespace App\Actions\Admin\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpdateUser
{
    /** @param array{name: string, email: string} $data */
    public function handle(User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            User::query()->whereKey($user->getKey())->update([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
            ]);
            $user->refresh();
        });
    }
}
