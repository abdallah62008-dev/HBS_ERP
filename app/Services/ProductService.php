<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * All product writes go through this service so price changes are
 * captured in product_price_history and audited.
 *
 * Why a service instead of a model observer? Updating a price always
 * needs an explicit `reason` from the operator (or "import", "bulk
 * adjust", etc.). An observer would make that impossible without
 * polluting model state. The service interface forces the caller to
 * pass a reason.
 */
class ProductService
{
    /**
     * Apply a partial update to a product. If any of the price fields
     * change, a single price-history row is written capturing the
     * before/after for all changed price fields.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(Product $product, array $attributes, ?string $priceChangeReason = null): Product
    {
        return DB::transaction(function () use ($product, $attributes, $priceChangeReason) {
            $userId = Auth::id();

            $oldPrices = [
                'cost_price' => (float) $product->cost_price,
                'selling_price' => (float) $product->selling_price,
                'marketer_trade_price' => (float) $product->marketer_trade_price,
            ];

            $product->fill([
                ...$attributes,
                'updated_by' => $userId,
            ])->save();

            $newPrices = [
                'cost_price' => (float) $product->cost_price,
                'selling_price' => (float) $product->selling_price,
                'marketer_trade_price' => (float) $product->marketer_trade_price,
            ];

            if ($oldPrices !== $newPrices) {
                ProductPriceHistory::create([
                    'product_id' => $product->id,
                    'old_cost_price' => $oldPrices['cost_price'],
                    'new_cost_price' => $newPrices['cost_price'],
                    'old_selling_price' => $oldPrices['selling_price'],
                    'new_selling_price' => $newPrices['selling_price'],
                    'old_marketer_trade_price' => $oldPrices['marketer_trade_price'],
                    'new_marketer_trade_price' => $newPrices['marketer_trade_price'],
                    'reason' => $priceChangeReason,
                    'changed_by' => $userId,
                ]);

                AuditLogService::log(
                    action: 'price_change',
                    module: 'products',
                    recordType: Product::class,
                    recordId: $product->id,
                    oldValues: $oldPrices,
                    newValues: $newPrices + ['reason' => $priceChangeReason],
                );
            }

            AuditLogService::logModelChange($product, 'updated', 'products');

            return $product->refresh();
        });
    }
}
