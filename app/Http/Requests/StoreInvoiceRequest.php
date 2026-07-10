<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number'    => 'required|string|max:50|unique:invoices,invoice_number',
            'issue_date'        => 'required|date',
            'due_date'          => 'required|date|after_or_equal:issue_date',
            'period_start'      => 'nullable|date',
            'period_end'        => 'nullable|date|after_or_equal:period_start',
            'total_amount'      => 'required|numeric|min:0',
            'status'            => 'nullable|in:draft,issued,partially_paid,paid,cancelled',

            // Реквизиты продавца (наша компания)
            'seller_name'       => 'nullable|string|max:255',
            'seller_voen'       => 'nullable|string|max:20',
            'seller_bank_name'  => 'nullable|string|max:255',
            'seller_iban'       => 'nullable|string|max:50',
            'seller_bank_code'  => 'nullable|string|max:20',
            'seller_bank_voen'  => 'nullable|string|max:20',
            'seller_swift'      => 'nullable|string|max:20',

            // Реквизиты плательщика (клиент)
            'payer_name'          => 'nullable|string|max:255',
            'payer_voen'          => 'nullable|string|max:20',
            'contract_reference'  => 'nullable|string|max:50',
            'comment'             => 'nullable|string',

            // Строки инвойса — массив
            'lines'                         => 'required|array|min:1',
            // lines — обязательный массив, минимум одна строка
            'lines.*.description'           => 'required|string|max:255',
            // lines.* — правило применяется к каждому элементу массива
            'lines.*.amount'                => 'required|numeric|min:0',
            'lines.*.subscription_id'       => 'nullable|exists:subscriptions,id',
            'lines.*.order_id'              => 'nullable|exists:orders,id',
        ];
    }
}