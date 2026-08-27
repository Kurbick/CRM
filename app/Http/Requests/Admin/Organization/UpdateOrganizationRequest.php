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
            'name.required' => __('admin.organization.validation.name_required'),
            'name.max' => __('admin.organization.validation.name_max'),
            'voen.max' => __('admin.organization.validation.voen_max'),
            'bank_name.max' => __('admin.organization.validation.bank_name_max'),
            'iban.max' => __('admin.organization.validation.iban_max'),
            'bank_code.max' => __('admin.organization.validation.bank_code_max'),
            'bank_voen.max' => __('admin.organization.validation.bank_voen_max'),
            'swift.max' => __('admin.organization.validation.swift_max'),
        ];
    }
}
