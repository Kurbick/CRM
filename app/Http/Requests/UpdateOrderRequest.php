<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_id' => 'sometimes|exists:service_types,id',
            'order_date'      => 'sometimes|date',
            'price'           => 'sometimes|numeric|min:0',
            'payment_terms'   => 'sometimes|required|integer|min:0|max:3650',
            'status'          => 'nullable|in:in_progress,completed,cancelled',
            'comment'         => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'payment_terms.required' => 'Укажите срок оплаты в днях.',
            'payment_terms.integer' => 'Срок оплаты должен быть целым числом дней.',
        ];
    }
}
