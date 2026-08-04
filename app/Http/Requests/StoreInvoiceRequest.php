<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('company') instanceof Company
            && ($this->user()?->can('create', Invoice::class) ?? false);
    }

    public function rules(): array
    {
        return [
            'contract_id' => [
                'required',
                Rule::exists('contracts', 'id')->where(
                    'company_id',
                    $this->route('company')->id
                ),
            ],
            'invoice_number' => 'required|string|max:50|unique:invoices,invoice_number',
            'issue_date' => 'required|date',
            'due_date' => 'required|date|after_or_equal:issue_date',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'total_amount' => 'required|numeric|min:0',
            'status' => 'prohibited',

            // Реквизиты продавца принадлежат deployment configuration.
            'seller_name' => 'prohibited',
            'seller_voen' => 'prohibited',
            'seller_bank_name' => 'prohibited',
            'seller_iban' => 'prohibited',
            'seller_bank_code' => 'prohibited',
            'seller_bank_voen' => 'prohibited',
            'seller_swift' => 'prohibited',

            // Реквизиты плательщика (клиент)
            'payer_name' => 'nullable|string|max:255',
            'payer_voen' => 'nullable|string|max:20',
            'contract_reference' => 'nullable|string|max:50',
            'comment' => 'nullable|string',

            // Строки инвойса — массив
            'lines' => 'required|array|min:1',
            'lines.*' => 'array:description,amount,subscription_id,order_id,period_start,period_end,billing_occurrence_key',
            // lines — обязательный массив, минимум одна строка
            'lines.*.description' => 'required|string|max:255',
            // lines.* — правило применяется к каждому элементу массива
            'lines.*.amount' => 'required|numeric|decimal:0,2|min:0.01',
            'lines.*.subscription_id' => 'nullable|integer',
            'lines.*.order_id' => 'nullable|integer',
            'lines.*.period_start' => 'prohibited',
            'lines.*.period_end' => 'prohibited',
            'lines.*.billing_occurrence_key' => 'prohibited',
        ];
    }
}
