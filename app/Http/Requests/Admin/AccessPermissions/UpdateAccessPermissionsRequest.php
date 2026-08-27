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
            'permissions.present' => __('admin.access.validation.permissions_invalid'),
            'permissions.array' => __('admin.access.validation.permissions_invalid'),
            'permissions.*.string' => __('admin.access.validation.permission_unknown'),
            'permissions.*.in' => __('admin.access.validation.permission_unknown'),
            'permissions.*.distinct' => __('admin.access.validation.permission_duplicate'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return route('admin.access-permissions.index', ['role' => $this->route('role')->getKey()]);
    }
}
