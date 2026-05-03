<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    /**
     * Permission already enforced by the route middleware
     * (`permission:customers.create`); this just authorises the
     * authenticated user to actually perform the action.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'primary_phone' => ['required', 'string', 'max:32'],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'default_address' => ['required', 'string'],
            'customer_type' => ['nullable', 'in:Normal,VIP,Watchlist,Blacklist'],
            'notes' => ['nullable', 'string'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
        ];
    }
}
