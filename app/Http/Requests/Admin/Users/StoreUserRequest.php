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
            'name.required' => __('admin.users.validation.name_required'),
            'email.required' => __('admin.users.validation.email_required'),
            'email.email' => __('admin.users.validation.email_email'),
            'email.unique' => __('admin.users.validation.email_unique'),
            'role_id.required' => __('admin.users.validation.role_required'),
            'role_id.exists' => __('admin.users.validation.role_exists'),
            'password.required' => __('admin.users.validation.password_required'),
            'password.confirmed' => __('admin.users.validation.password_confirmed'),
            'password.min' => __('admin.users.validation.password_min'),
            'password.password.mixed' => __('admin.users.validation.password_mixed'),
            'password.password.numbers' => __('admin.users.validation.password_numbers'),
            'password.password.symbols' => __('admin.users.validation.password_symbols'),
        ];
    }
}
