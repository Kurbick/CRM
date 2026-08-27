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
            'display_name.required' => __('admin.access.validation.group_name_required'),
            'display_name.unique' => __('admin.access.validation.group_name_unique'),
            'display_name.max' => __('admin.access.validation.group_name_max'),
            'description.max' => __('admin.access.validation.description_max'),
        ];
    }
}
