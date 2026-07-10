<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'contract_number'  => 'sometimes|string|max:50|unique:contracts,contract_number,'
                                   . $this->route('contract')->id,
            'start_date'       => 'sometimes|date',
            'end_date'         => 'nullable|date|after:start_date',
            'status'           => 'nullable|in:active,expired,terminated',
            'signed_document'  => 'nullable|string|max:255',
            'comment'          => 'nullable|string',
        ];
    }
}