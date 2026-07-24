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
            'comment'           => 'nullable|string',
        ];
    }
}
