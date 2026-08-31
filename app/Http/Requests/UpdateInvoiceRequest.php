<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            && ($this->user()?->can('update', $invoice) ?? false);
    }

    public function rules(): array
    {
        $invoice = $this->route('invoice');

        return [
            'invoice_number' => [
                'sometimes',
                'string',
                'max:50',
                Rule::unique('invoices', 'invoice_number')->ignore(
                    $invoice instanceof Invoice ? $invoice->getKey() : null
                ),
            ],
            'invoice_number_sequence' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'invoice_number_manual' => ['sometimes', 'nullable', 'boolean'],
            'issuer_organization_id' => ['sometimes', 'nullable', 'integer', 'exists:organizations,id'],
            'issue_date' => ['sometimes', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'comment' => ['sometimes', 'nullable', 'string'],

            'company_id' => 'prohibited',
            'contract_id' => 'prohibited',
            'status' => 'prohibited',
            'total_amount' => 'prohibited',
            'subtotal_amount' => 'prohibited',
            'vat_enabled' => 'prohibited',
            'vat_rate' => 'prohibited',
            'vat_amount' => 'prohibited',
            'period_start' => 'prohibited',
            'period_end' => 'prohibited',

            'seller_name' => 'prohibited',
            'seller_voen' => 'prohibited',
            'seller_bank_name' => 'prohibited',
            'seller_iban' => 'prohibited',
            'seller_bank_code' => 'prohibited',
            'seller_bank_voen' => 'prohibited',
            'seller_swift' => 'prohibited',

            'payer_name' => 'prohibited',
            'payer_voen' => 'prohibited',
            'contract_reference' => 'prohibited',

            'lines' => 'prohibited',
            'invoice_id' => 'prohibited',
            'order_id' => 'prohibited',
            'subscription_id' => 'prohibited',
            'billing_occurrence_key' => 'prohibited',

            'id' => 'prohibited',
            'created_at' => 'prohibited',
            'updated_at' => 'prohibited',
            'paid_amount' => 'prohibited',
            'remaining_amount' => 'prohibited',
            'is_overdue' => 'prohibited',
        ];
    }
}
