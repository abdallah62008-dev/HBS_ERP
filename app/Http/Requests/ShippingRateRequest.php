<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShippingRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'shipping_company_id' => ['required', 'exists:shipping_companies,id'],
            'country' => ['required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'base_cost' => ['required', 'numeric', 'min:0'],
            'cod_fee' => ['nullable', 'numeric', 'min:0'],
            'return_fee' => ['nullable', 'numeric', 'min:0'],
            'estimated_days' => ['nullable', 'integer', 'min:0', 'max:60'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ];
    }
}
