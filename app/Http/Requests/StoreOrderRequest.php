<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Either pick an existing customer OR provide enough info to
            // create a new one inline. Controller handles which path.
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer.name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer.primary_phone' => ['required_without:customer_id', 'nullable', 'string', 'max:32'],
            'customer.city' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer.country' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer.governorate' => ['nullable', 'string', 'max:255'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'customer.secondary_phone' => ['nullable', 'string', 'max:32'],

            // Shipping snapshot — required so the order is shippable.
            'customer_address' => ['required', 'string'],
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],

            'source' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],

            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_fees' => ['nullable', 'numeric', 'min:0'],

            // Items — at least one product required.
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Override duplicate warning — required if duplicate score is high.
            'duplicate_acknowledged' => ['nullable', 'boolean'],
        ];
    }
}
