<?php

namespace App\Http\Requests\Admin\Users;

use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['name' => trim((string) $this->input('name')), 'email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role_id' => ['required', Rule::exists('roles', 'id')->where('guard_name', 'web')],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    public function messages(): array
    {
        return self::validationMessages();
    }

    public static function validationMessages(): array
    {
        return [
            'name.required' => 'Введите имя пользователя.',
            'email.required' => 'Введите email пользователя.',
            'email.email' => 'Укажите корректный email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
            'role_id.required' => 'Выберите группу пользователя.',
            'role_id.exists' => 'Выбранная группа недоступна.',
            'password.required' => 'Введите временный пароль.',
            'password.confirmed' => 'Пароли не совпадают.',
            'password.min' => 'Пароль должен содержать не менее 12 символов.',
            'password.password.mixed' => 'Пароль должен содержать хотя бы одну заглавную и одну строчную букву.',
            'password.password.numbers' => 'Пароль должен содержать хотя бы одну цифру.',
            'password.password.symbols' => 'Пароль должен содержать хотя бы один специальный символ.',
        ];
    }
}
