<?php

namespace App\Http\Requests;

use App\Models\CompanyContact;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCompanyContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');

        return $contact instanceof CompanyContact
            && ($this->user()?->can('update', $contact) ?? false);
    }

    public function rules(): array
    {
        return [
            'first_name' => 'sometimes|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'position' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'role' => 'nullable|in:director,accountant,manager,technical,other',
            'comment' => 'nullable|string',
        ];
    }
}
