<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id'   => 'required|exists:service_types,id',
            'start_date'        => 'required|date',
            'next_billing_date' => 'required|date|after_or_equal:start_date',
            'billing_period'    => 'required|in:monthly,quarterly,semiannual,annual',
            'amount'            => 'required|numeric|min:0',
            'payment_terms'     => 'nullable|integer|min:1|max:365',
            'status'            => 'nullable|in:active,suspended,completed,cancelled',
            'comment'           => 'nullable|string',
        ];
    }
}