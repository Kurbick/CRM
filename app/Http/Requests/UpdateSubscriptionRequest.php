<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id'   => 'sometimes|exists:service_types,id',
            'start_date'        => 'sometimes|date',
            'next_billing_date' => 'sometimes|date|after_or_equal:start_date',
            'billing_period'    => 'sometimes|in:monthly,quarterly,semiannual,annual',
            'amount'            => 'sometimes|numeric|min:0',
            'payment_terms'     => 'required|integer|min:1|max:365',
            'status'            => 'nullable|in:active,suspended,completed,cancelled',
            'comment'           => 'nullable|string',
        ];
    }
}
