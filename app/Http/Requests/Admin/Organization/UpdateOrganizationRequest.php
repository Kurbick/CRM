<?php

namespace App\Http\Requests\Admin\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $fields = ['name', 'voen', 'bank_name', 'iban', 'bank_code', 'bank_voen', 'swift'];
        $normalized = [];

        foreach ($fields as $field) {
            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : $value;
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'voen' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'bank_voen' => ['nullable', 'string', 'max:20'],
            'swift' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите название нашей организации.',
            'name.max' => 'Название организации не должно превышать 255 символов.',
            'voen.max' => 'VÖEN не должен превышать 20 символов.',
            'bank_name.max' => 'Название банка не должно превышать 255 символов.',
            'iban.max' => 'IBAN не должен превышать 50 символов.',
            'bank_code.max' => 'Код банка не должен превышать 20 символов.',
            'bank_voen.max' => 'VÖEN банка не должен превышать 20 символов.',
            'swift.max' => 'SWIFT не должен превышать 20 символов.',
        ];
    }
}
