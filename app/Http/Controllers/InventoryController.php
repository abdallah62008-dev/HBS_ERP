<?php

namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Stock overview: per-product, per-warehouse on-hand + reserved + available.
     * Computed by SUM-ing inventory_movements grouped by product/warehouse.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'warehouse_id', 'low_stock_only']);

        // Build a base query: every active product × every active warehouse
        // needs a row. Doing this in SQL would mean a CROSS JOIN; instead
        // we pull active products + warehouses and stitch movement totals.
        $warehouses = Warehouse::where('status', 'Active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name']);

        $productsQuery = Product::query()
            ->where('status', 'Active')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%");
                });
            });

        $products = $productsQuery
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        $productIds = collect($products->items())->pluck('id')->all();

        // One aggregate query for the visible page only.
        $aggregates = InventoryMovement::query()
            ->whereIn('product_id', $productIds)
            ->when($filters['warehouse_id'] ?? null, fn ($q, $w) => $q->where('warehouse_id', $w))
            ->selectRaw("
                product_id,
                warehouse_id,
                SUM(CASE WHEN movement_type IN ('Purchase', 'Return To Stock', 'Opening Balance', 'Transfer In', 'Adjustment', 'Stock Count Correction', 'Ship', 'Return Damaged', 'Transfer Out')
                         THEN quantity ELSE 0 END) AS on_hand,
                SUM(CASE WHEN movement_type = 'Reserve' THEN quantity ELSE 0 END) AS reserved_in,
                SUM(CASE WHEN movement_type = 'Release Reservation' THEN quantity ELSE 0 END) AS reserved_out
            ")
            ->groupBy('product_id', 'warehouse_id')
            ->get();

        $stockMap = [];
        foreach ($aggregates as $a) {
            $reserved = max(0, (int) $a->reserved_in - (int) $a->reserved_out);
            $onHand = (int) $a->on_hand;
            $stockMap["{$a->product_id}:{$a->warehouse_id}"] = [
                'on_hand' => $onHand,
                'reserved' => $reserved,
                'available' => $onHand - $reserved,
            ];
        }

        // Decorate paginated products with per-warehouse rollups.
        $rows = collect($products->items())->map(function (Product $p) use ($warehouses, $stockMap) {
            $warehousesData = [];
            $totalAvailable = 0;
            foreach ($warehouses as $w) {
                $key = "{$p->id}:{$w->id}";
                $entry = $stockMap[$key] ?? ['on_hand' => 0, 'reserved' => 0, 'available' => 0];
                $warehousesData[$w->id] = $entry;
                $totalAvailable += $entry['available'];
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'reorder_level' => (int) $p->reorder_level,
                'warehouses' => $warehousesData,
                'total_available' => $totalAvailable,
                'is_low' => $totalAvailable <= (int) $p->reorder_level,
            ];
        });

        if (! empty($filters['low_stock_only'])) {
            $rows = $rows->filter(fn ($r) => $r['is_low'])->values();
        }

        return Inertia::render('Inventory/Index', [
            'rows' => $rows,
            'paginator' => [
                'links' => $products->linkCollection()->toArray(),
                'total' => $products->total(),
                'per_page' => $products->perPage(),
                'current_page' => $products->currentPage(),
            ],
            'warehouses' => $warehouses,
            'filters' => $filters,
        ]);
    }

    /**
     * Movements log — every change to stock with full traceability.
     */
    public function movements(Request $request): Response
    {
        $filters = $request->only(['q', 'warehouse_id', 'movement_type', 'product_id']);

        $movements = InventoryMovement::query()
            ->with(['product:id,sku,name', 'warehouse:id,name', 'createdBy:id,name'])
            ->when($filters['warehouse_id'] ?? null, fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($filters['movement_type'] ?? null, fn ($q, $v) => $q->where('movement_type', $v))
            ->when($filters['product_id'] ?? null, fn ($q, $v) => $q->where('product_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereHas('product', function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%");
                });
            })
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Inventory/Movements', [
            'movements' => $movements,
            'filters' => $filters,
            'warehouses' => Warehouse::orderBy('name')->get(['id', 'name']),
            'movement_types' => array_merge(
                InventoryMovement::STOCK_IN_TYPES,
                InventoryMovement::STOCK_OUT_TYPES,
                InventoryMovement::RESERVATION_TYPES,
                ['Adjustment', 'Stock Count Correction'],
            ),
        ]);
    }

    /**
     * Low stock filter shortcut. Reuses the index page with a forced filter.
     */
    public function lowStock(Request $request): Response
    {
        return $this->index($request->merge(['low_stock_only' => '1']));
    }
}
