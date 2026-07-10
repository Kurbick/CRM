<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => 'required|exists:service_types,id',
            'order_date'      => 'required|date',
            'deadline'        => 'nullable|date|after:order_date',
            'price'           => 'required|numeric|min:0',
            'payment_terms'   => 'nullable|integer|min:1|max:365',
            'status'          => 'nullable|in:in_progress,completed,cancelled',
            'comment'         => 'nullable|string',
        ];
    }
}