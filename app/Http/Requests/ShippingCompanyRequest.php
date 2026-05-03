<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShippingCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $company = $this->route('shipping_company');
        $id = is_object($company) ? $company->id : $company;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('shipping_companies', 'name')->ignore($id)],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'api_enabled' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ];
    }
}
