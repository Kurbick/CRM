<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\CompanyContact;
use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company
            && ($this->user()?->can('create', [CompanyContact::class, $company]) ?? false);
    }

    public function rules(): array
    {
        return [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'role' => 'nullable|in:director,accountant,manager,technical,other',
            'comment' => 'nullable|string',
        ];
    }
}
