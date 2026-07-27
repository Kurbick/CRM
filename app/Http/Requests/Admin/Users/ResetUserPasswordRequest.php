<?php

namespace App\Http\Requests\Admin\Users;

use App\Support\Auth\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
{
    protected $errorBag = 'resetPassword';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['password' => ['required', 'confirmed', PasswordPolicy::rule()]];
    }

    public function messages(): array
    {
        return StoreUserRequest::validationMessages();
    }
}
