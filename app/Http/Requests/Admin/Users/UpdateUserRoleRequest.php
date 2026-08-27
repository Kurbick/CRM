<?php

namespace App\Http\Requests\Admin\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    protected $errorBag = 'updateRole';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role_id' => ['required', Rule::exists('roles', 'id')->where('guard_name', 'web')]];
    }

    public function messages(): array
    {
        return ['role_id.required' => __('admin.users.validation.role_required'), 'role_id.exists' => __('admin.users.validation.role_exists')];
    }
}
