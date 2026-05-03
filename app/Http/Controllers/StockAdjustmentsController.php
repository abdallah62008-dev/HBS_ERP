<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockAdjustmentRequest;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock adjustments use a two-step workflow: any user with
 * `inventory.adjust` can create a Pending request, but a different user
 * with `inventory.adjust` (or `approvals.manage` later in Phase 8) must
 * approve before the inventory_movements row is written.
 *
 * The same user cannot approve their own request — a guard documented
 * in 03_RBAC_SECURITY_AUDIT.md (segregation of duties).
 */
class StockAdjustmentsController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status']);

        $adjustments = StockAdjustment::query()
            ->with([
                'product:id,sku,name', 'warehouse:id,name',
                'createdBy:id,name', 'approvedBy:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('StockAdjustments/Index', [
            'adjustments' => $adjustments,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('StockAdjustments/Create', [
            'warehouses' => Warehouse::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name']),
        ]);
    }

    public function store(StockAdjustmentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $oldQuantity = $this->inventory->onHandStock(
            $data['product_id'],
            $data['product_variant_id'] ?? null,
            $data['warehouse_id'],
        );

        $adjustment = StockAdjustment::create([
            ...$data,
            'old_quantity' => $oldQuantity,
            'difference' => (int) $data['new_quantity'] - $oldQuantity,
            'status' => 'Pending',
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($adjustment, 'requested', 'inventory');

        return redirect()->route('stock-adjustments.index')
            ->with('success', 'Adjustment request submitted. It will be applied once approved.');
    }

    public function approve(StockAdjustment $stockAdjustment): RedirectResponse
    {
        if (! $stockAdjustment->isPending()) {
            return back()->with('error', 'This adjustment is not pending approval.');
        }

        if ($stockAdjustment->created_by === Auth::id()) {
            return back()->with('error', 'You cannot approve a stock adjustment you created. Ask another team member.');
        }

        DB::transaction(function () use ($stockAdjustment) {
            $stockAdjustment->forceFill([
                'status' => 'Approved',
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'updated_by' => Auth::id(),
            ])->save();

            $this->inventory->adjust(
                productId: $stockAdjustment->product_id,
                variantId: $stockAdjustment->product_variant_id,
                warehouseId: $stockAdjustment->warehouse_id,
                signedDifference: (int) $stockAdjustment->difference,
                reference: $stockAdjustment,
                notes: $stockAdjustment->reason,
            );

            AuditLogService::log(
                action: 'approved',
                module: 'inventory',
                recordType: StockAdjustment::class,
                recordId: $stockAdjustment->id,
                newValues: [
                    'difference' => $stockAdjustment->difference,
                    'reason' => $stockAdjustment->reason,
                ],
            );
        });

        return back()->with('success', 'Adjustment approved and applied to inventory.');
    }

    public function reject(Request $request, StockAdjustment $stockAdjustment): RedirectResponse
    {
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $stockAdjustment->isPending()) {
            return back()->with('error', 'This adjustment is not pending.');
        }

        $stockAdjustment->forceFill([
            'status' => 'Rejected',
            'rejection_reason' => $data['rejection_reason'],
            'approved_by' => Auth::id(),
            'approved_at' => now(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::log(
            action: 'rejected',
            module: 'inventory',
            recordType: StockAdjustment::class,
            recordId: $stockAdjustment->id,
            newValues: ['rejection_reason' => $data['rejection_reason']],
        );

        return back()->with('success', 'Adjustment rejected.');
    }
}
