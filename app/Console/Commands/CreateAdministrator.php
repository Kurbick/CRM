<?php

namespace App\Console\Commands;

use App\Actions\Admin\Users\AssignRoleToUser;
use App\Models\Role;
use App\Models\User;
use App\Services\AccessControlSynchronizer;
use App\Support\Access\SystemRole;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdministrator extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Создать первого администратора или назначить роль существующему пользователю';

    public function handle(AccessControlSynchronizer $synchronizer, AssignRoleToUser $assignRole): int
    {
        $email = mb_strtolower(trim((string) $this->ask('Email')));
        $existing = User::query()->where('email', $email)->first();

        if ($existing) {
            $this->components->twoColumnDetail('Имя', $existing->name);
            $this->components->twoColumnDetail('Email', $existing->email);
            $this->components->twoColumnDetail('Активен', $existing->isActive() ? 'Да' : 'Нет');

            if (! $this->confirm('Назначить пользователю группу Administrator?')) {
                return self::SUCCESS;
            }

            $activate = false;
            if (! $existing->isActive()) {
                $activate = $this->confirm('Пользователь неактивен. Активировать его?');
                if (! $activate) {
                    $this->error('Неактивному пользователю Administrator не назначен.');

                    return self::FAILURE;
                }
            }

            DB::transaction(function () use ($synchronizer, $assignRole, $existing, $activate): void {
                $synchronizer->sync();
                if ($activate) {
                    $existing->forceFill(['is_active' => true])->save();
                }
                $assignRole->handle($existing, $this->administratorRole());
            });
        } else {
            $name = trim((string) $this->ask('Имя'));
            $password = (string) $this->secret('Пароль');
            $confirmation = (string) $this->secret('Подтверждение пароля');
            $validator = Validator::make(
                ['name' => $name, 'email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
                ['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'unique:users,email'], 'password' => ['required', 'confirmed', PasswordPolicy::rule()]],
            );

            if ($validator->fails()) {
                foreach ($validator->errors()->all() as $message) {
                    $this->error($message);
                }

                return self::FAILURE;
            }

            DB::transaction(function () use ($synchronizer, $assignRole, $name, $email, $password): void {
                $synchronizer->sync();
                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
                $user->forceFill([
                    'is_active' => true,
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                    'created_by' => null,
                    'last_login_at' => null,
                ])->save();
                $assignRole->handle($user, $this->administratorRole());
            });
        }

        $this->info('Administrator назначен.');

        return self::SUCCESS;
    }

    private function administratorRole(): Role
    {
        return Role::query()->where('name', SystemRole::Administrator->value)->where('guard_name', 'web')->firstOrFail();
    }
}
