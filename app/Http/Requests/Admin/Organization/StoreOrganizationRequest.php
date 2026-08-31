<?php

namespace App\Http\Requests\Admin\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            ...$this->normalized(),
            'is_vat_payer' => $this->boolean('is_vat_payer'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'voen' => ['nullable', 'string', 'max:20'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bank_correspondent_account' => ['nullable', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'bank_voen' => ['nullable', 'string', 'max:20'],
            'swift' => ['nullable', 'string', 'max:20'],
            'invoice_number_code' => ['required', 'string', 'max:12', 'regex:/^[A-Z0-9]+$/', 'unique:organizations,invoice_number_code'],
            'is_vat_payer' => ['required', 'boolean'],
            'vat_rate' => [
                'nullable', 'numeric', 'decimal:0,2', 'gt:0', 'lte:100',
                Rule::requiredIf(fn (): bool => $this->boolean('is_vat_payer')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('admin.organization.validation.name_required'),
            'legal_name.max' => __('admin.organization.validation.legal_name_max'),
            'bank_correspondent_account.max' => __('admin.organization.validation.bank_correspondent_account_max'),
            'invoice_number_code.required' => __('admin.organization.validation.invoice_number_code_required'),
            'invoice_number_code.unique' => __('organizations.errors.code_unique'),
            'invoice_number_code.max' => __('admin.organization.validation.invoice_number_code_max'),
            'invoice_number_code.regex' => __('admin.organization.validation.invoice_number_code_format'),
            'vat_rate.required' => __('admin.organization.validation.vat_rate_required'),
            'vat_rate.numeric' => __('admin.organization.validation.vat_rate_numeric'),
            'vat_rate.decimal' => __('admin.organization.validation.vat_rate_decimal'),
            'vat_rate.gt' => __('admin.organization.validation.vat_rate_positive'),
            'vat_rate.lte' => __('admin.organization.validation.vat_rate_max'),
        ];
    }

    /** @return array<string, mixed> */
    private function normalized(): array
    {
        $fields = ['name', 'legal_name', 'voen', 'bank_name', 'iban', 'bank_correspondent_account', 'bank_code', 'bank_voen', 'swift', 'invoice_number_code', 'vat_rate'];
        $normalized = [];
        foreach ($fields as $field) {
            $value = trim((string) $this->input($field));
            $normalized[$field] = $value === '' ? null : ($field === 'invoice_number_code' ? strtoupper($value) : $value);
        }

        return $normalized;
    }
}
