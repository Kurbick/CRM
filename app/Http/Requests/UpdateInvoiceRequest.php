<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invoice_number'    => 'sometimes|string|max:50|unique:invoices,invoice_number,'
                                    . $this->route('invoice')->id,
            'issue_date'        => 'sometimes|date',
            'due_date'          => 'sometimes|date|after_or_equal:issue_date',
            'period_start'      => 'nullable|date',
            'period_end'        => 'nullable|date|after_or_equal:period_start',
            'total_amount'      => 'sometimes|numeric|min:0',
            'status'            => 'sometimes|in:draft,issued,partially_paid,paid,cancelled',
            'seller_name'       => 'nullable|string|max:255',
            'seller_voen'       => 'nullable|string|max:20',
            'seller_bank_name'  => 'nullable|string|max:255',
            'seller_iban'       => 'nullable|string|max:50',
            'seller_bank_code'  => 'nullable|string|max:20',
            'seller_bank_voen'  => 'nullable|string|max:20',
            'seller_swift'      => 'nullable|string|max:20',
            'payer_name'        => 'nullable|string|max:255',
            'payer_voen'        => 'nullable|string|max:20',
            'contract_reference'=> 'nullable|string|max:50',
            'comment'           => 'nullable|string',
        ];
    }
}