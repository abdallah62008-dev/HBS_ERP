<?php

namespace App\Http\Requests;

use App\Models\ProductChannelSku;
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
            // P-1: brand is optional. Allowed on update to retag.
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
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
            'tier_prices.*.collection_cost' => ['nullable', 'numeric', 'min:0'],
            'tier_prices.*.return_cost' => ['nullable', 'numeric', 'min:0'],

            // P-1: channel SKU repeater. Same shape as Store request.
            // `channel_skus` field is optional on update — when absent
            // the controller leaves existing rows untouched (used by
            // older form payloads that never sent the section).
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
     * Catch in-form duplicates on `(variant_id, channel)` so the user
     * sees a friendly 422 instead of the DB unique constraint failure.
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
