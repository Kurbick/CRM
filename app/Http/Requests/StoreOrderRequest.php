<?php

namespace App\Http\Requests;

use App\Models\Contract;
use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            && ($this->user()?->can('create', [Order::class, $contract]) ?? false);
    }

    public function rules(): array
    {
        return [
            'service_type_id' => [
                'required',
                Rule::exists('service_types', 'id')->where('type', 'one_time'),
            ],
            'order_date' => 'required|date',
            'price' => 'required|numeric|min:0',
            'payment_terms' => 'required|integer|min:0|max:3650',
            'status' => 'nullable|in:in_progress,completed,cancelled',
            'comment' => 'nullable|string',
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
