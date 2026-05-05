<?php

namespace App\Services;

use App\Models\Marketer;
use App\Models\MarketerProductPrice;
use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Phase 5.9 — resolve a marketer's per-product cost / shipping / VAT.
 *
 * Walks the resolution chain spelled out in
 * 19_MARKETER_PROFIT_CALCULATION_INTEGRATION_ALLOW.md §Task 7 (Phase 5.7
 * spec) and §Task 2 (Phase 5.9 spec):
 *
 *   1. tier price       — marketer.marketer_price_tier_id (Phase 5.7)
 *                          → marketer_product_prices(group=tier_id, product, variant=null)
 *   2. legacy group price — marketer.price_group_id (legacy Bronze/Silver/etc)
 *                          → marketer_product_prices(group=group_id, product, variant=null)
 *   3. product default   — product/variant.marketer_trade_price
 *                          (no shipping or VAT info → defaults 0 / 14)
 *
 * Per-marketer-per-product overrides (a hypothetical level 0) aren't yet
 * a column in marketer_product_prices, so they are out of scope.
 */
class MarketerPricingResolver
{
    private const VAT_DEFAULT = 14.0;

    /**
     * @return array{cost_price: float, shipping_cost: float, vat_percent: float, source: string}
     */
    public function resolveForItem(
        Marketer $marketer,
        int $productId,
        ?int $variantId = null,
    ): array {
        // 1. Tier price — only if the marketer has been assigned a tier.
        if ($marketer->marketer_price_tier_id) {
            $row = MarketerProductPrice::query()
                ->where('marketer_price_group_id', $marketer->marketer_price_tier_id)
                ->where('product_id', $productId)
                ->whereNull('product_variant_id')
                ->first();

            if ($row) {
                return [
                    'cost_price' => (float) $row->trade_price,
                    'shipping_cost' => (float) ($row->shipping_cost ?? 0),
                    'vat_percent' => (float) ($row->vat_percent ?? self::VAT_DEFAULT),
                    'source' => 'tier',
                ];
            }
        }

        // 2. Legacy price-group row (Bronze/Silver/Gold/VIP fallback).
        if ($marketer->price_group_id) {
            $row = MarketerProductPrice::query()
                ->where('marketer_price_group_id', $marketer->price_group_id)
                ->where('product_id', $productId)
                ->whereNull('product_variant_id')
                ->first();

            if ($row) {
                return [
                    'cost_price' => (float) $row->trade_price,
                    'shipping_cost' => (float) ($row->shipping_cost ?? 0),
                    'vat_percent' => (float) ($row->vat_percent ?? self::VAT_DEFAULT),
                    'source' => 'legacy_group',
                ];
            }
        }

        // 3. Product default (the original Phase 2 fallback). No shipping
        //    or VAT data available at the product level — defaults apply.
        $variant = $variantId ? ProductVariant::find($variantId) : null;
        $product = Product::find($productId);

        return [
            'cost_price' => (float) ($variant->marketer_trade_price ?? $product?->marketer_trade_price ?? 0),
            'shipping_cost' => 0.0,
            'vat_percent' => self::VAT_DEFAULT,
            'source' => 'product_default',
        ];
    }

    /**
     * Compute a single line's marketer profit using the confirmed formula:
     *
     *   profit = (unit_price − unit_price × vat%/100 − cost − shipping) × qty
     *
     * Collection cost and return cost are NOT subtracted in this phase
     * (they live on marketer_product_prices for visibility only — see
     * Phase 5.6 spec).
     */
    public function profitForItem(
        float $unitPrice,
        int $quantity,
        float $costPrice,
        float $shippingCost,
        float $vatPercent,
    ): float {
        $perUnit = $unitPrice
            - ($unitPrice * $vatPercent / 100)
            - $costPrice
            - $shippingCost;

        return round($perUnit * $quantity, 2);
    }
}
