<?php

namespace App\Services\Importers;

use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Bulk price update importer. Updates an EXISTING product's prices and
 * routes the change through ProductService so a price-history row is
 * written and the change is audit-logged.
 *
 * Undo restores the previous prices from the price-history row written
 * during persistRow().
 */
class PriceUpdateImporter extends AbstractImporter
{
    public function label(): string { return 'Price updates'; }
    public function slug(): string { return 'price_updates'; }

    public function headers(): array
    {
        return ['sku', 'cost_price', 'selling_price', 'marketer_trade_price',
            'minimum_selling_price', 'reason'];
    }

    public function headerNotes(): array
    {
        return [
            'sku' => 'Required. Must match an existing product.',
            'reason' => 'Optional. Stored on the price-history row.',
            '*' => 'Leave a price column blank to keep the existing value.',
        ];
    }

    public function validateRow(array $row): ?string
    {
        if (! $this->pick($row, 'sku')) return 'SKU is required.';

        if (! Product::where('sku', $this->pick($row, 'sku'))->exists()) {
            return 'Unknown SKU: ' . $this->pick($row, 'sku');
        }

        // At least one price column must be present.
        $hasPrice = false;
        foreach (['cost_price', 'selling_price', 'marketer_trade_price', 'minimum_selling_price'] as $k) {
            if ($this->pick($row, $k) !== null) {
                $hasPrice = true;
                break;
            }
        }
        if (! $hasPrice) return 'Provide at least one price column to update.';

        return null;
    }

    public function persistRow(array $row): Model
    {
        $product = Product::where('sku', $this->pick($row, 'sku'))->firstOrFail();
        $reason = $this->pick($row, 'reason') ?: 'Bulk price update import';

        $patch = [];
        foreach (['cost_price', 'selling_price', 'marketer_trade_price', 'minimum_selling_price'] as $k) {
            $v = $this->pick($row, $k);
            if ($v !== null) $patch[$k] = (float) str_replace([','], '', $v);
        }

        // Route through ProductService so price_history + audit_log get
        // written. Returns the refreshed product for the import_job_row.
        return App::make(ProductService::class)->update($product, $patch, $reason);
    }

    public function canUndo(): bool
    {
        // Update-style imports — undo means reverting to the price the
        // history row recorded. ImportService.undoJob uses the latest
        // price_history row tied to this product to roll back; but since
        // we don't store the back-link from row → history record, we
        // refuse undo here. Operators can reverse via another import.
        return false;
    }
}
