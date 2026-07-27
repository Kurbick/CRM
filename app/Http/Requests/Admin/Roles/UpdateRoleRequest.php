<?php

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $role = $this->route('role');
        $description = trim((string) $this->input('description'));
        $this->errorBag = 'updateRole-'.$role->getKey();
        $this->merge([
            'display_name' => trim((string) $this->input('display_name')),
            'description' => $description === '' ? null : $description,
            '_section' => 'role-'.$role->getKey(),
        ]);
    }

    public function rules(): array
    {
        $role = $this->route('role');

        return [
            'display_name' => ['required', 'string', 'max:255', Rule::unique('roles', 'display_name')->where('guard_name', 'web')->ignore($role->getKey())],
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.required' => 'Введите название группы.',
            'display_name.unique' => 'Группа с таким названием уже существует.',
            'display_name.max' => 'Название группы не должно превышать 255 символов.',
            'description.max' => 'Описание не должно превышать 1000 символов.',
        ];
    }
}
