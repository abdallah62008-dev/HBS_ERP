<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // SKU must be unique across BOTH products AND product_variants
            // because the order item can reference either by SKU.
            'sku' => [
                'required', 'string', 'max:64',
                Rule::unique('products', 'sku')->whereNull('deleted_at'),
                Rule::unique('product_variants', 'sku'),
            ],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'image_url' => ['nullable', 'string', 'max:1024'],
            'description' => ['nullable', 'string'],

            'cost_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'marketer_trade_price' => ['nullable', 'numeric', 'min:0'],
            'minimum_selling_price' => ['nullable', 'numeric', 'min:0'],

            'tax_enabled' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'reorder_level' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:Active,Inactive,Out of Stock,Discontinued'],

            // Phase 5.6: marketer pricing tiers (optional; per-tier cells
            // can be empty). Keyed by tier code (A/B/D/E).
            'tier_prices' => ['nullable', 'array'],
            'tier_prices.*.marketer_cost_price' => ['nullable', 'numeric', 'min:0'],
            'tier_prices.*.shipping_cost' => ['nullable', 'numeric', 'min:0'],
            'tier_prices.*.vat_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'tier_prices.*.collection_cost' => ['nullable', 'numeric', 'min:0'],
            'tier_prices.*.return_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
