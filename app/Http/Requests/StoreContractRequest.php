<?php

namespace App\Http\Requests;

use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Contract::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'contract_number' => 'required|string|max:50|unique:contracts,contract_number',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'nullable|in:active,terminated',
            'signed_document' => 'prohibited',
            'comment' => 'nullable|string',
        ];
    }
}
