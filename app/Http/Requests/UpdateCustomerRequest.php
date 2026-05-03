<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'primary_phone' => ['sometimes', 'required', 'string', 'max:32'],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'required', 'string', 'max:255'],
            'default_address' => ['sometimes', 'required', 'string'],
            'customer_type' => ['nullable', 'in:Normal,VIP,Watchlist,Blacklist'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }
}
