<?php

namespace App\Http\Requests\Admin\Roles;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoleRequest extends FormRequest
{
    protected $errorBag = 'createRole';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $description = trim((string) $this->input('description'));
        $this->merge([
            'display_name' => trim((string) $this->input('display_name')),
            'description' => $description === '' ? null : $description,
            '_section' => 'create',
        ]);
    }

    public function rules(): array
    {
        return [
            'display_name' => ['required', 'string', 'max:255', Rule::unique('roles', 'display_name')->where('guard_name', 'web')],
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
