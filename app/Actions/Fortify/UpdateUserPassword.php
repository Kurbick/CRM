<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Support\Auth\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the user's password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', PasswordPolicy::rule()],
            'password_confirmation' => ['required', 'string', 'same:password'],
        ], [
            'current_password.required' => 'Введите текущий пароль.',
            'current_password.current_password' => 'Текущий пароль указан неверно.',
            'password.required' => 'Введите новый пароль.',
            'password.min' => 'Пароль должен содержать не менее 12 символов.',
            'password.password.mixed' => 'Пароль должен содержать хотя бы одну заглавную и одну строчную букву.',
            'password.password.numbers' => 'Пароль должен содержать хотя бы одну цифру.',
            'password.password.symbols' => 'Пароль должен содержать хотя бы один специальный символ.',
            'password_confirmation.required' => 'Пароли не совпадают.',
            'password_confirmation.same' => 'Пароли не совпадают.',
        ])->validateWithBag('updatePassword');

        if (Hash::check($input['password'], $user->password)) {
            $exception = ValidationException::withMessages([
                'password' => 'Новый пароль должен отличаться от текущего.',
            ]);
            $exception->errorBag = 'updatePassword';

            throw $exception;
        }

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => Hash::make($input['password']),
                'must_change_password' => false,
                'password_changed_at' => now(),
            ])->save();
        });
    }
}
