<?php

namespace App\Http\Requests\Admin\AccessPermissions;

use App\Support\Access\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccessPermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->request->has('permissions')) {
            $this->merge(['permissions' => []]);
        }
    }

    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::in(PermissionRegistry::names())],
        ];
    }

    public function messages(): array
    {
        return [
            'permissions.present' => 'Передан некорректный список прав доступа.',
            'permissions.array' => 'Передан некорректный список прав доступа.',
            'permissions.*.string' => 'Выбрано неизвестное право доступа.',
            'permissions.*.in' => 'Выбрано неизвестное право доступа.',
            'permissions.*.distinct' => 'Одно право доступа передано несколько раз.',
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.access-permissions.index', ['role' => $this->route('role')->getKey()]);
    }
}
