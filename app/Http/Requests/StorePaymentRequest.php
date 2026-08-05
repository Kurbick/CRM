<?php

namespace App\Http\Requests;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        if (! $invoice instanceof Invoice) {
            return false;
        }

        Gate::authorize('create', [Payment::class, $invoice]);

        return true;
    }

    public function rules(): array
    {
        return [
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:/^(?:0\.(?:0[1-9]|[1-9]\d?)|[1-9]\d{0,7}(?:\.\d{1,2})?)$/'],
            'payment_method' => ['required', 'in:cash,transfer'],
            'comment' => ['nullable', 'string', 'max:2000'],

            'id' => ['prohibited'],
            'invoice_id' => ['prohibited'],
            'company_id' => ['prohibited'],
            'status' => ['prohibited'],
            'cancelled_at' => ['prohibited'],
            'cancel_reason' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
            'reference' => ['prohibited'],
            'allocations' => ['prohibited'],
            'payment_allocations' => ['prohibited'],
            'credit_balance' => ['prohibited'],
            'credit_balance_id' => ['prohibited'],
            'credit_balance_entry_id' => ['prohibited'],
            'source_payment_id' => ['prohibited'],
            'source_invoice_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
