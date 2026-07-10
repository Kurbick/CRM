<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    
    public function rules(): array
    {
        return [
            'type'           => 'sometimes|in:company,individual',
            'name'           => 'sometimes|string|max:255',
            'short_name'     => 'nullable|string|max:100',
            'voen'           => 'nullable|string|max:20',
            'bank_name'      => 'nullable|string|max:255',
            'iban'           => 'nullable|string|max:50',
            'bank_code'      => 'nullable|string|max:20',
            'bank_voen'      => 'nullable|string|max:20',
            'swift'          => 'nullable|string|max:20',
            'legal_address'  => 'nullable|string|max:255',
            'actual_address' => 'nullable|string|max:255',
            'email'          => 'nullable|email|max:255',
            'phone'          => 'nullable|string|max:30',
            'website'        => 'nullable|url|max:255',
            'status'         => 'nullable|in:active,suspended,archived',
            'invoice_mode'   => 'nullable|in:separate,consolidated',
            'comment'        => 'nullable|string',
        ];
    }
}