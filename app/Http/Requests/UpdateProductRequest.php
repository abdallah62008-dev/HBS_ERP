<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $product = $this->route('product');
        $productId = is_object($product) ? $product->id : $product;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sku' => [
                'sometimes', 'required', 'string', 'max:64',
                Rule::unique('products', 'sku')->ignore($productId)->whereNull('deleted_at'),
                Rule::unique('product_variants', 'sku'),
            ],
            'barcode' => ['nullable', 'string', 'max:64'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'image_url' => ['nullable', 'string', 'max:1024'],
            'description' => ['nullable', 'string'],

            // Price fields are optional on update; the controller routes any
            // change through ProductService so it lands in price_history with
            // an audit log entry instead of being silently overwritten.
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'selling_price' => ['nullable', 'numeric', 'min:0'],
            'marketer_trade_price' => ['nullable', 'numeric', 'min:0'],
            'minimum_selling_price' => ['nullable', 'numeric', 'min:0'],
            'price_change_reason' => ['nullable', 'string', 'max:500'],

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
        ];
    }
}
