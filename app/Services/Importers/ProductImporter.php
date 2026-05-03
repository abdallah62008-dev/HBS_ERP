<?php

namespace App\Services\Importers;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ProductImporter extends AbstractImporter
{
    public function label(): string { return 'Products'; }
    public function slug(): string { return 'products'; }

    public function headers(): array
    {
        return ['sku', 'name', 'category', 'cost_price', 'selling_price',
            'marketer_trade_price', 'minimum_selling_price', 'tax_rate', 'reorder_level', 'status'];
    }

    public function headerNotes(): array
    {
        return [
            'sku' => 'Required. Must be unique across products and variants.',
            'name' => 'Required.',
            'category' => 'Optional. Matched by name; created if missing.',
            'cost_price' => 'Numeric. Defaults to 0.',
            'selling_price' => 'Numeric. Defaults to 0.',
            'tax_rate' => 'Percentage. Optional.',
            'status' => 'Active / Inactive / Out of Stock / Discontinued. Defaults to Active.',
        ];
    }

    public function validateRow(array $row): ?string
    {
        if (! $this->pick($row, 'sku')) return 'SKU is required.';
        if (! $this->pick($row, 'name')) return 'Name is required.';

        $costPrice = $this->pickFloat($row, 'cost_price');
        $sellingPrice = $this->pickFloat($row, 'selling_price');
        if ($costPrice < 0 || $sellingPrice < 0) {
            return 'Prices cannot be negative.';
        }
        return null;
    }

    public function findDuplicate(array $row): ?Model
    {
        $sku = $this->pick($row, 'sku');
        return $sku ? Product::withTrashed()->where('sku', $sku)->first() : null;
    }

    public function persistRow(array $row): Model
    {
        $userId = Auth::id();
        $categoryId = null;

        $catName = $this->pick($row, 'category');
        if ($catName) {
            $categoryId = Category::firstOrCreate(
                ['name' => $catName, 'parent_id' => null],
                ['status' => 'Active', 'created_by' => $userId, 'updated_by' => $userId],
            )->id;
        }

        return Product::create([
            'sku' => $this->pick($row, 'sku'),
            'name' => $this->pick($row, 'name'),
            'category_id' => $categoryId,
            'cost_price' => $this->pickFloat($row, 'cost_price'),
            'selling_price' => $this->pickFloat($row, 'selling_price'),
            'marketer_trade_price' => $this->pickFloat($row, 'marketer_trade_price'),
            'minimum_selling_price' => $this->pickFloat($row, 'minimum_selling_price'),
            'tax_enabled' => $this->pickFloat($row, 'tax_rate') > 0,
            'tax_rate' => $this->pickFloat($row, 'tax_rate'),
            'reorder_level' => $this->pickInt($row, 'reorder_level'),
            'status' => $this->pick($row, 'status') ?: 'Active',
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    public function canUndo(): bool
    {
        // Safe — orders snapshot product info; deleting a product won't break them.
        return true;
    }
}
