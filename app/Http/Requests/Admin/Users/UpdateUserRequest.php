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
            'name.required' => __('admin.users.validation.name_required'),
            'email.required' => __('admin.users.validation.email_required'),
            'email.email' => __('admin.users.validation.email_email'),
            'email.unique' => __('admin.users.validation.email_unique'),
        ];
    }
}
