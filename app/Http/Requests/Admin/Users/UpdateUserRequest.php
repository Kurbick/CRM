<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    protected $errorBag = 'updateUser';

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
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите имя пользователя.',
            'email.required' => 'Введите email пользователя.',
            'email.email' => 'Укажите корректный email.',
            'email.unique' => 'Пользователь с таким email уже существует.',
        ];
    }
}
