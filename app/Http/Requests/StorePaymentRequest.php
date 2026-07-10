<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date'   => 'required|date',
            'amount'         => 'required|numeric|min:0.01',
            // min:0.01 — платёж не может быть нулевым
            'payment_method' => 'required|in:cash,card,transfer',
            'status'         => 'nullable|in:pending,confirmed',
            'comment'        => 'nullable|string',
        ];
    }
}