<?php

namespace App\Http\Requests;

use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;

class UpdateContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contract = $this->route('contract');

        return $contract instanceof Contract
            && ($this->user()?->can('update', $contract) ?? false);
    }

    public function rules(): array
    {
        return [
            'contract_number' => 'sometimes|string|max:50|unique:contracts,contract_number,'
                                   .$this->route('contract')->id,
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date|after:start_date',
            'status' => 'nullable|in:active,terminated',
            'signed_document' => 'prohibited',
            'comment' => 'nullable|string',
        ];
    }
}
