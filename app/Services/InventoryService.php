<?php

namespace App\Services;

use App\Models\InventoryMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Movement-based inventory.
 *
 * Stock-on-hand and available stock are derived by SUM-ing inventory_movements;
 * there is no "current quantity" column on `products`. This is the contract
 * laid out in 04_BUSINESS_WORKFLOWS.md §13.
 *
 * Conventions:
 *   - `quantity` is stored as a signed integer.
 *     Stock-IN movements (Purchase, Return To Stock, Opening Balance,
 *     Transfer In) are positive. Stock-OUT (Ship, Return Damaged,
 *     Transfer Out) are negative. Adjustments can be either.
 *   - Reservations (Reserve / Release Reservation) ARE stored as
 *     positive quantities and tracked separately. The available-stock
 *     calculation subtracts (Reserve sum − Release Reservation sum) so
 *     a Reserve is equivalent to "this stock is spoken for" without
 *     reducing on-hand.
 *
 * Public API
 *   - record(): low-level write — every other helper goes through this.
 *   - reserve(): atomically check + create a Reserve movement.
 *   - releaseReservation(): cancel a previous reservation.
 *   - ship(): convert a reservation to a real OUT movement (Ship).
 *     This is what OrderService calls when an order's status changes
 *     to "Shipped".
 *   - returnToStock(): record a Return To Stock movement.
 *   - returnDamaged(): record a Return Damaged movement (writes off).
 *   - adjust(): record an Adjustment after a stock_adjustment row is
 *     approved — this is the only path that increases or decreases
 *     stock outside the normal order/purchase flow.
 *   - availableStock(): sum movements for product+warehouse minus
 *     outstanding reservations.
 *   - onHandStock(): same as availableStock but ignores reservations.
 *   - reservedQuantity(): outstanding reservations only.
 *
 * All writes go through DB::transaction so a movement and any
 * accompanying mutation (e.g. updating purchase_invoice.status) land
 * atomically.
 */
class InventoryService
{
    /**
     * Low-level: insert a single inventory_movements row. Prefer the
     * higher-level helpers (reserve / ship / adjust / etc.) for the
     * sign and validation rules they enforce.
     */
    public function record(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        string $movementType,
        int $signedQuantity,
        ?float $unitCost = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
    ): InventoryMovement {
        if ($signedQuantity === 0) {
            throw new RuntimeException('Inventory movement quantity must be non-zero.');
        }

        return InventoryMovement::create([
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'warehouse_id' => $warehouseId,
            'movement_type' => $movementType,
            'quantity' => $signedQuantity,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'notes' => $notes,
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);
    }

    /**
     * Reserve stock for an order. Throws if available stock is
     * insufficient and the system is configured to disallow negative
     * stock (default per 04_BUSINESS_WORKFLOWS.md §6).
     */
    public function reserve(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        Model $reference,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new RuntimeException('Reserve quantity must be positive.');
        }

        return DB::transaction(function () use ($productId, $variantId, $warehouseId, $quantity, $reference, $notes) {
            $available = $this->availableStock($productId, $variantId, $warehouseId);

            if ($available < $quantity && ! $this->allowNegativeStock()) {
                throw new RuntimeException(sprintf(
                    'Insufficient stock to reserve %d units (available: %d).',
                    $quantity,
                    $available,
                ));
            }

            return $this->record(
                productId: $productId,
                variantId: $variantId,
                warehouseId: $warehouseId,
                movementType: 'Reserve',
                signedQuantity: $quantity,
                referenceType: $reference::class,
                referenceId: $reference->getKey(),
                notes: $notes,
            );
        });
    }

    /**
     * Release a previously-held reservation (e.g. order cancelled before ship).
     */
    public function releaseReservation(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        Model $reference,
        ?string $notes = null,
    ): InventoryMovement {
        return $this->record(
            productId: $productId,
            variantId: $variantId,
            warehouseId: $warehouseId,
            movementType: 'Release Reservation',
            signedQuantity: $quantity,
            referenceType: $reference::class,
            referenceId: $reference->getKey(),
            notes: $notes,
        );
    }

    /**
     * Ship: deduct on-hand stock AND release the matching reservation
     * (so available stock doesn't double-decrement).
     */
    public function ship(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        Model $reference,
        ?string $notes = null,
    ): InventoryMovement {
        if ($quantity <= 0) {
            throw new RuntimeException('Ship quantity must be positive.');
        }

        return DB::transaction(function () use ($productId, $variantId, $warehouseId, $quantity, $reference, $notes) {
            $onHand = $this->onHandStock($productId, $variantId, $warehouseId);

            if ($onHand < $quantity && ! $this->allowNegativeStock()) {
                throw new RuntimeException(sprintf(
                    'Insufficient on-hand stock to ship %d units (on-hand: %d).',
                    $quantity,
                    $onHand,
                ));
            }

            // Reservations against this reference shouldn't continue tying
            // up stock once the goods have left the warehouse.
            $existingReservation = $this->reservationFor($productId, $variantId, $warehouseId, $reference);
            if ($existingReservation > 0) {
                $this->releaseReservation(
                    productId: $productId,
                    variantId: $variantId,
                    warehouseId: $warehouseId,
                    quantity: $existingReservation,
                    reference: $reference,
                    notes: 'Auto-release on ship',
                );
            }

            return $this->record(
                productId: $productId,
                variantId: $variantId,
                warehouseId: $warehouseId,
                movementType: 'Ship',
                signedQuantity: -$quantity,
                referenceType: $reference::class,
                referenceId: $reference->getKey(),
                notes: $notes,
            );
        });
    }

    public function returnToStock(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        Model $reference,
        ?string $notes = null,
    ): InventoryMovement {
        return $this->record(
            productId: $productId,
            variantId: $variantId,
            warehouseId: $warehouseId,
            movementType: 'Return To Stock',
            signedQuantity: $quantity,
            referenceType: $reference::class,
            referenceId: $reference->getKey(),
            notes: $notes,
        );
    }

    public function returnDamaged(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $quantity,
        Model $reference,
        ?string $notes = null,
    ): InventoryMovement {
        // No on-hand impact (the goods never came back into the saleable
        // pool), but written for the audit trail and so reports can count
        // damage write-offs.
        return $this->record(
            productId: $productId,
            variantId: $variantId,
            warehouseId: $warehouseId,
            movementType: 'Return Damaged',
            signedQuantity: -$quantity,
            referenceType: $reference::class,
            referenceId: $reference->getKey(),
            notes: $notes,
        );
    }

    /**
     * Apply an approved StockAdjustment by writing a single Adjustment
     * movement (positive or negative depending on `difference`).
     */
    public function adjust(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        int $signedDifference,
        Model $reference,
        ?string $notes = null,
    ): ?InventoryMovement {
        if ($signedDifference === 0) {
            return null;
        }

        return $this->record(
            productId: $productId,
            variantId: $variantId,
            warehouseId: $warehouseId,
            movementType: 'Adjustment',
            signedQuantity: $signedDifference,
            referenceType: $reference::class,
            referenceId: $reference->getKey(),
            notes: $notes,
        );
    }

    /**
     * Available stock = on-hand − outstanding reservations.
     * Pass $warehouseId = null to sum across all warehouses.
     */
    public function availableStock(int $productId, ?int $variantId, ?int $warehouseId = null): int
    {
        return $this->onHandStock($productId, $variantId, $warehouseId)
            - $this->reservedQuantity($productId, $variantId, $warehouseId);
    }

    /**
     * Sum of all on-hand-affecting movements (excludes Reserve / Release Reservation).
     */
    public function onHandStock(int $productId, ?int $variantId, ?int $warehouseId = null): int
    {
        return (int) InventoryMovement::query()
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotIn('movement_type', InventoryMovement::RESERVATION_TYPES)
            ->sum('quantity');
    }

    /**
     * Outstanding reservations = SUM(Reserve) − SUM(Release Reservation).
     */
    public function reservedQuantity(int $productId, ?int $variantId, ?int $warehouseId = null): int
    {
        $reserved = (int) InventoryMovement::query()
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('movement_type', 'Reserve')
            ->sum('quantity');

        $released = (int) InventoryMovement::query()
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('movement_type', 'Release Reservation')
            ->sum('quantity');

        return max(0, $reserved - $released);
    }

    /**
     * Outstanding reservation specifically tied to a given reference
     * (e.g. how much is reserved for Order #123).
     */
    public function reservationFor(
        int $productId,
        ?int $variantId,
        int $warehouseId,
        Model $reference,
    ): int {
        $reserved = (int) InventoryMovement::query()
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->where('warehouse_id', $warehouseId)
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('movement_type', 'Reserve')
            ->sum('quantity');

        $released = (int) InventoryMovement::query()
            ->where('product_id', $productId)
            ->when($variantId === null, fn ($q) => $q->whereNull('product_variant_id'))
            ->when($variantId !== null, fn ($q) => $q->where('product_variant_id', $variantId))
            ->where('warehouse_id', $warehouseId)
            ->where('reference_type', $reference::class)
            ->where('reference_id', $reference->getKey())
            ->where('movement_type', 'Release Reservation')
            ->sum('quantity');

        return max(0, $reserved - $released);
    }

    /**
     * Default warehouse picker — used by services that need to pick a
     * destination without forcing the operator to choose every time.
     * Falls back to the first Active warehouse if no `is_default` row.
     */
    public function defaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)
            ->where('status', 'Active')
            ->first()
            ?? Warehouse::where('status', 'Active')->orderBy('id')->first();
    }

    private function allowNegativeStock(): bool
    {
        return (bool) SettingsService::get('allow_negative_stock', false);
    }
}
