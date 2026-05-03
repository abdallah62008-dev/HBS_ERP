<?php

namespace App\Services\Importers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;

/**
 * Stock importer — writes Opening Balance (or Adjustment) inventory
 * movements rather than directly editing a stock column.
 *
 * Each row is a (sku, warehouse, quantity, movement_type, unit_cost?, notes?)
 * tuple. movement_type defaults to "Opening Balance".
 *
 * Undo deletes the movement row. Safe because Opening Balance / Adjustment
 * never cascade to other tables.
 */
class StockImporter extends AbstractImporter
{
    public function label(): string { return 'Stock movements'; }
    public function slug(): string { return 'stock'; }

    public function headers(): array
    {
        return ['sku', 'warehouse', 'quantity', 'movement_type', 'unit_cost', 'notes'];
    }

    public function headerNotes(): array
    {
        return [
            'sku' => 'Must match an existing product SKU.',
            'warehouse' => 'Warehouse name. Defaults to the system default warehouse.',
            'quantity' => 'Signed integer. Positive = stock IN, negative = stock OUT.',
            'movement_type' => 'Opening Balance / Adjustment / Stock Count Correction. Defaults to Opening Balance.',
            'unit_cost' => 'Optional unit cost for valuation.',
        ];
    }

    public function validateRow(array $row): ?string
    {
        if (! $this->pick($row, 'sku')) return 'SKU is required.';
        $qty = $this->pickInt($row, 'quantity');
        if ($qty === 0) return 'Quantity must be non-zero.';

        $type = $this->pick($row, 'movement_type') ?: 'Opening Balance';
        if (! in_array($type, ['Opening Balance', 'Adjustment', 'Stock Count Correction'], true)) {
            return "Invalid movement_type: {$type}. Allowed: Opening Balance, Adjustment, Stock Count Correction.";
        }

        if (! Product::where('sku', $this->pick($row, 'sku'))->exists()) {
            return "Unknown SKU: " . $this->pick($row, 'sku');
        }

        $warehouseName = $this->pick($row, 'warehouse');
        if ($warehouseName && ! Warehouse::where('name', $warehouseName)->exists()) {
            return "Unknown warehouse: {$warehouseName}.";
        }

        return null;
    }

    public function persistRow(array $row): Model
    {
        $product = Product::where('sku', $this->pick($row, 'sku'))->firstOrFail();
        $warehouseName = $this->pick($row, 'warehouse');

        if ($warehouseName) {
            $warehouse = Warehouse::where('name', $warehouseName)->firstOrFail();
        } else {
            $warehouse = App::make(InventoryService::class)->defaultWarehouse();
        }

        $qty = $this->pickInt($row, 'quantity');
        $type = $this->pick($row, 'movement_type') ?: 'Opening Balance';
        $unitCost = $this->pickFloat($row, 'unit_cost') ?: null;

        return App::make(InventoryService::class)->record(
            productId: $product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            movementType: $type,
            signedQuantity: $qty,
            unitCost: $unitCost,
            referenceType: null,
            referenceId: null,
            notes: $this->pick($row, 'notes') ?: 'Imported',
        );
    }

    public function canUndo(): bool
    {
        // Safe: deleting an Opening Balance / Adjustment row does not
        // cascade. (We refuse to import "Ship" / "Reserve" through this
        // importer, so undo can't break a real order.)
        return true;
    }

    public function undoRecord(Model $record): void
    {
        // No soft-deletes on inventory_movements — hard delete the row.
        // Audit trail is preserved via audit_logs.
        if ($record instanceof InventoryMovement) {
            $record->forceDelete();
        }
    }
}
