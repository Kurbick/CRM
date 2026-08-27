<?php

namespace App\Actions\Admin\Users;

use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class UpdateUserRole
{
    public function __construct(private readonly AssignRoleToUser $assignRole) {}

    public function handle(User $actor, User $user, Role $role): void
    {
        if ($actor->is($user)) {
            $exception = ValidationException::withMessages([
                'role_id' => __('admin.errors.self_role'),
            ]);
            $exception->errorBag = 'updateRole';
            throw $exception;
        }

        $this->assignRole->handle($user, $role);
    }
}
