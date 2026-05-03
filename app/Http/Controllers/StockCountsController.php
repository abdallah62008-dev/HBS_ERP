<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockCountRequest;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockCountsController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): Response
    {
        $counts = StockCount::query()
            ->with(['warehouse:id,name', 'createdBy:id,name', 'approvedBy:id,name'])
            ->withCount('items')
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('StockCounts/Index', [
            'counts' => $counts,
            'filters' => $request->only(['status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('StockCounts/Create', [
            'warehouses' => Warehouse::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name']),
        ]);
    }

    public function store(StockCountRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $count = DB::transaction(function () use ($data) {
            $count = StockCount::create([
                'warehouse_id' => $data['warehouse_id'],
                'count_date' => $data['count_date'],
                'notes' => $data['notes'] ?? null,
                'status' => 'Submitted',
                'created_by' => Auth::id(),
            ]);

            foreach ($data['items'] as $row) {
                $sysQty = $this->inventory->onHandStock(
                    $row['product_id'],
                    $row['product_variant_id'] ?? null,
                    $count->warehouse_id,
                );

                StockCountItem::create([
                    'stock_count_id' => $count->id,
                    'product_id' => $row['product_id'],
                    'product_variant_id' => $row['product_variant_id'] ?? null,
                    'system_quantity' => $sysQty,
                    'counted_quantity' => (int) $row['counted_quantity'],
                    'difference' => (int) $row['counted_quantity'] - $sysQty,
                    'notes' => $row['notes'] ?? null,
                ]);
            }

            AuditLogService::logModelChange($count, 'created', 'inventory');

            return $count;
        });

        return redirect()->route('stock-counts.show', $count)
            ->with('success', 'Stock count submitted. Approve to apply differences as inventory corrections.');
    }

    public function show(StockCount $stockCount): Response
    {
        $stockCount->load([
            'warehouse:id,name',
            'items.product:id,sku,name',
            'createdBy:id,name', 'approvedBy:id,name',
        ]);

        return Inertia::render('StockCounts/Show', [
            'count' => $stockCount,
        ]);
    }

    public function approve(StockCount $stockCount): RedirectResponse
    {
        if ($stockCount->status === 'Approved') {
            return back()->with('error', 'Count already approved.');
        }
        if ($stockCount->created_by === Auth::id()) {
            return back()->with('error', 'You cannot approve a stock count you created.');
        }

        DB::transaction(function () use ($stockCount) {
            $stockCount->loadMissing('items');

            foreach ($stockCount->items as $item) {
                if ($item->difference !== 0) {
                    $this->inventory->record(
                        productId: $item->product_id,
                        variantId: $item->product_variant_id,
                        warehouseId: $stockCount->warehouse_id,
                        movementType: 'Stock Count Correction',
                        signedQuantity: (int) $item->difference,
                        referenceType: StockCount::class,
                        referenceId: $stockCount->id,
                        notes: "Stock count {$stockCount->id}",
                    );
                }
            }

            $stockCount->forceFill([
                'status' => 'Approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'approved',
                module: 'inventory',
                recordType: StockCount::class,
                recordId: $stockCount->id,
                newValues: ['items' => $stockCount->items->count()],
            );
        });

        return back()->with('success', 'Stock count approved. Inventory corrections applied.');
    }
}
