<?php

namespace App\Actions\Admin\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateUser
{
    public function __construct(private readonly AssignRoleToUser $assignRole) {}

    /** @param array{name: string, email: string, password: string} $data */
    public function handle(User $actor, array $data, Role $role): User
    {
        return DB::transaction(function () use ($actor, $data, $role): User {
            $user = User::query()->create([
                'name' => trim($data['name']),
                'email' => Str::lower(trim($data['email'])),
                'password' => $data['password'],
            ]);
            $user->forceFill([
                'is_active' => true,
                'must_change_password' => true,
                'password_changed_at' => null,
                'last_login_at' => null,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->assignRole->handle($user, $role);

            return $user->fresh();
        });
    }
}
