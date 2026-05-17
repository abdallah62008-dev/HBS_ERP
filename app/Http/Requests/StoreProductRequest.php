<?php

namespace App\Http\Requests;

use App\Models\ProductChannelSku;
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
            // P-1: brand is optional. Existing products carry NULL until
            // the operator tags them.
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
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

            // P-1: channel SKU repeater. Optional, may be empty. Each
            // row attaches to a product_variant that the controller
            // verifies belongs to this product (defence in depth).
            // On create the variant must already exist — that is true
            // when the product was created with variants in a previous
            // step, or this is an edit. Brand-new products without
            // variants ship `channel_skus: []` from the form.
            'channel_skus' => ['nullable', 'array'],
            'channel_skus.*.id' => ['nullable', 'integer', 'exists:product_channel_skus,id'],
            'channel_skus.*.product_variant_id' => ['required_with:channel_skus.*.channel', 'integer', 'exists:product_variants,id'],
            'channel_skus.*.channel' => ['required_with:channel_skus.*.external_sku', 'string', Rule::in(ProductChannelSku::CHANNELS)],
            'channel_skus.*.external_sku' => ['required_with:channel_skus.*.channel', 'string', 'max:128'],
            'channel_skus.*.external_barcode' => ['nullable', 'string', 'max:128'],
            'channel_skus.*.external_url' => ['nullable', 'string', 'max:1024'],
            'channel_skus.*.is_active' => ['nullable', 'boolean'],
            'channel_skus.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Enforce the `(product_variant_id, channel)` unique constraint at
     * the request layer so we surface a friendly 422 instead of a DB
     * integrity violation. The DB index is still the source of truth.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $rows = (array) $this->input('channel_skus', []);
            $seen = [];
            foreach ($rows as $i => $row) {
                $variantId = $row['product_variant_id'] ?? null;
                $channel = $row['channel'] ?? null;
                if (! $variantId || ! $channel) continue;
                $key = $variantId . '|' . $channel;
                if (isset($seen[$key])) {
                    $v->errors()->add("channel_skus.$i.channel", "Duplicate channel for this variant in this form.");
                    continue;
                }
                $seen[$key] = true;
            }
        });
    }
}
