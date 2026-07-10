<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date'   => 'sometimes|date',
            'amount'         => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|in:cash,card,transfer',
            'status'         => 'sometimes|in:pending,confirmed',
            'comment'        => 'nullable|string',
        ];
    }
}