<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\DuplicateDetectionService;
use App\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OrdersController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly DuplicateDetectionService $duplicateService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status', 'risk_level', 'shipping_status']);

        $orders = Order::query()
            ->forCurrentMarketer()
            ->with('customer:id,name,primary_phone')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = trim($term);
                // Phase 5.4: support display-number search like
                // "ORD-2026-000123-AH" by splitting at the entry-code
                // suffix and matching order_number + entry_code together.
                $orderNumberPart = $term;
                $entryCodePart = null;
                if (preg_match('/^(.+-\d{4,}-\d+)-(.+)$/', $term, $matches)) {
                    $orderNumberPart = $matches[1];
                    $entryCodePart = $matches[2];
                }
                $q->where(function ($w) use ($term, $orderNumberPart, $entryCodePart) {
                    $w->where('order_number', 'like', "%{$term}%")
                        ->orWhere('external_order_reference', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%");
                    if ($entryCodePart !== null) {
                        $w->orWhere(function ($d) use ($orderNumberPart, $entryCodePart) {
                            $d->where('order_number', $orderNumberPart)
                                ->where('entry_code', $entryCodePart);
                        });
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->where('customer_risk_level', $v))
            ->when($filters['shipping_status'] ?? null, fn ($q, $v) => $q->where('shipping_status', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => Order::STATUSES,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Orders/Create', [
            'products' => $this->productsForOrderEntry(),
            'categories' => Category::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
            'locations' => CustomersController::locationTree(),
            'default_country_code' => \App\Services\SettingsService::get('default_country_code', 'EG'),
            // Phase 5.4: server-computed preview of the entry_code the
            // OrderService will stamp on the new order. Only valid for the
            // staff-creates-order path (no marketer_id selected); marketer-
            // created orders override with marketers.code at save time.
            'entry_code_preview' => $this->previewEntryCode($request->user()),
            // Phase 5.9: marketer list for the optional "On behalf of"
            // picker — drives the marketer-profit preview.
            'marketers' => \App\Models\Marketer::query()
                ->where('status', 'Active')
                ->with(['user:id,name', 'priceTier:id,code,name'])
                ->orderBy('code')
                ->get(['id', 'code', 'user_id', 'marketer_price_tier_id'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'code' => $m->code,
                    'name' => $m->user?->name,
                    'tier_name' => $m->priceTier?->name,
                ])
                ->all(),
        ]);
    }

    /**
     * Frontend preview of the entry_code that the OrderService would assign
     * for a staff-created order. Mirrors OrderService::resolveEntryCode for
     * the "no marketer" case so the read-only field on the Create Order
     * page shows the right value before submit.
     */
    private function previewEntryCode($user): ?string
    {
        if (! $user) return null;
        if ($user->entry_code) {
            return mb_substr((string) $user->entry_code, 0, 16);
        }
        if ($user->name) {
            $words = preg_split('/\s+/u', trim($user->name));
            $initials = '';
            foreach ($words as $word) {
                $clean = preg_replace('/[^A-Za-z0-9]/u', '', $word);
                if ($clean !== '') {
                    $initials .= mb_strtoupper(mb_substr($clean, 0, 1));
                }
            }
            $initials = mb_substr($initials, 0, 16);
            return $initials !== '' ? $initials : null;
        }
        return null;
    }

    /**
     * Active products with derived available_stock (on-hand minus
     * outstanding reservations). Single grouped query — same SUM-CASE
     * pattern used by InventoryController and DashboardController.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function productsForOrderEntry()
    {
        $stockTypes = "'Purchase','Return To Stock','Opening Balance','Transfer In','Adjustment','Stock Count Correction','Ship','Return Damaged','Transfer Out'";

        return DB::table('products')
            ->leftJoin('inventory_movements', 'products.id', '=', 'inventory_movements.product_id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'Active')
            ->selectRaw("
                products.id,
                products.sku,
                products.barcode,
                products.name,
                products.selling_price,
                products.minimum_selling_price,
                products.tax_enabled,
                products.tax_rate,
                products.category_id,
                categories.name AS category_name,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($stockTypes) THEN inventory_movements.quantity ELSE 0 END), 0) AS on_hand,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Reserve' THEN inventory_movements.quantity ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Release Reservation' THEN inventory_movements.quantity ELSE 0 END), 0) AS reserved
            ")
            ->groupBy([
                'products.id', 'products.sku', 'products.barcode', 'products.name',
                'products.selling_price', 'products.minimum_selling_price',
                'products.tax_enabled', 'products.tax_rate',
                'products.category_id', 'categories.name',
            ])
            ->orderBy('products.name')
            ->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'selling_price' => (float) $p->selling_price,
                'minimum_selling_price' => (float) $p->minimum_selling_price,
                'tax_enabled' => (bool) $p->tax_enabled,
                'tax_rate' => (float) $p->tax_rate,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
                'category_name' => $p->category_name,
                'available' => max(0, (int) $p->on_hand - (int) $p->reserved),
            ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $payload['created_by'] = Auth::id();

        $order = $this->orderService->createFromPayload($payload);

        return redirect()
            ->route('orders.show', $order)
            ->with('success', "Order {$order->order_number} created.");
    }

    public function show(Order $order): Response
    {
        $this->authorizeOwnership($order);

        $order->load([
            'items',
            'customer',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'packedBy:id,name',
            'shippedBy:id,name',
        ]);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
        ]);
    }

    public function edit(Order $order): Response
    {
        $this->authorizeOwnership($order);

        return Inertia::render('Orders/Edit', [
            'order' => $order,
            'statuses' => Order::STATUSES,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $data = $request->validated();
        $statusChange = $data['status'] ?? null;
        $statusNote = $data['status_note'] ?? null;
        unset($data['status'], $data['status_note']);

        $order->fill([...$data, 'updated_by' => Auth::id()])->save();

        AuditLogService::logModelChange($order, 'updated', 'orders');

        if ($statusChange && $statusChange !== $order->status) {
            $this->orderService->changeStatus($order->refresh(), $statusChange, $statusNote);
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Order updated.');
    }

    /**
     * Soft-delete an order.
     *
     * Per the operations policy, only super-admins may delete orders —
     * even users with the legacy `orders.delete` permission slug get a
     * hard 403 here. This replaces the prior approval-request workflow
     * (which let any user with `orders.delete` open an approval) with a
     * direct super-admin-only action.
     *
     * Soft-delete is used so the order's history is preserved: items,
     * inventory movements, shipping records, returns, marketer
     * transactions, and audit trails remain intact and can be restored
     * via `Order::withTrashed()` if the deletion was a mistake. The
     * authoritative cleanup (`destroyForce`) stays for emergency hard
     * deletes triggered by other paths and is itself gated by route
     * middleware.
     */
    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();

        // Hard 403 for non-super-admins. Reachable only by direct route
        // hit; the UI's delete control should already be hidden via the
        // `auth.user.is_super_admin` flag.
        if (! $user || ! $user->isSuperAdmin()) {
            abort(403, 'Only super administrators can delete orders.');
        }

        $this->authorizeOwnership($order);

        $reason = trim((string) $request->input('reason', ''));

        // Soft delete preserves all related rows (items, inventory
        // movements, returns, shipments, marketer transactions, audit
        // trail). No orphaning.
        $order->update(['deleted_by' => $user->id]);
        $order->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'orders',
            recordType: Order::class,
            recordId: $order->id,
            oldValues: [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total_amount' => $order->total_amount,
            ],
            newValues: [
                'action' => 'soft_delete',
                'reason' => $reason !== '' ? $reason : 'No reason provided.',
            ],
        );

        return redirect()
            ->route('orders.index')
            ->with('success', "Order {$order->order_number} deleted.");
    }

    /**
     * Approval-gated edit of money fields on a confirmed order. The
     * regular `update()` method blocks these once the order is confirmed
     * — this endpoint creates the approval instead.
     */
    public function requestPriceEdit(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $data = $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_fees' => ['nullable', 'numeric', 'min:0'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $patch = collect($data)
            ->only(['discount_amount', 'shipping_amount', 'extra_fees', 'cod_amount'])
            ->filter(fn ($v) => $v !== null)
            ->all();

        if (empty($patch)) {
            return back()->with('error', 'Nothing to change.');
        }

        $oldValues = collect($order->only(array_keys($patch)))->all();

        app(\App\Services\ApprovalService::class)->request(
            type: 'Edit Confirmed Order Price',
            target: $order,
            oldValues: $oldValues,
            newValues: $patch,
            reason: $data['reason'],
        );

        return back()->with('success', 'Price-edit request submitted for approval.');
    }

    /**
     * Used by the approval system itself when the previous (legacy)
     * delete flow was invoked. Kept for super-admin emergencies.
     */
    public function destroyForce(Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $order->update(['deleted_by' => Auth::id()]);
        $order->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'orders',
            recordType: Order::class,
            recordId: $order->id,
        );

        return redirect()->route('orders.index')->with('success', 'Order deleted.');
    }

    /**
     * Status change endpoint — used by Orders/Show quick-action buttons.
     */
    public function changeStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->orderService->changeStatus($order, $data['status'], $data['note'] ?? null);
        } catch (\RuntimeException $e) {
            // Domain-rule failures (shipping checklist, profit guard, status
            // flow) bubble up as RuntimeException. Surface as a flash error
            // so the modal redirects back instead of 500-ing.
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Status changed to {$data['status']}.");
    }

    public function timeline(Order $order): Response
    {
        $this->authorizeOwnership($order);

        $order->load([
            'statusHistory.changedBy:id,name',
            'notes.createdBy:id,name',
        ]);

        return Inertia::render('Orders/Timeline', [
            'order' => $order,
        ]);
    }

    /**
     * AJAX endpoint hit while creating an order — runs the duplicate
     * detection rules over the in-progress payload and returns the
     * score + reasons so the form can show a warning.
     */
    public function checkDuplicate(Request $request)
    {
        $data = $request->validate([
            'primary_phone' => ['nullable', 'string'],
            'secondary_phone' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'customer_address' => ['nullable', 'string'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->duplicateService->evaluate($data));
    }

    /**
     * Phase 5.9 — AJAX preview of marketer profit for the in-progress
     * Order Create payload. Used by the "Marketer profit preview"
     * widget in resources/js/Pages/Orders/Create.jsx so the operator
     * sees the resolved tier cost / shipping / VAT live as they edit.
     *
     * Returns the per-line breakdown plus a total. NULL when the
     * marketer or items aren't supplied yet.
     */
    public function marketerProfitPreview(
        Request $request,
        \App\Services\MarketerPricingResolver $resolver,
    ) {
        $data = $request->validate([
            'marketer_id' => ['required', 'exists:marketers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $marketer = \App\Models\Marketer::with('priceTier:id,code,name', 'priceGroup:id,name')
            ->findOrFail($data['marketer_id']);

        $lines = [];
        $total = 0.0;
        foreach ($data['items'] as $item) {
            $resolved = $resolver->resolveForItem(
                $marketer,
                (int) $item['product_id'],
                ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
            );
            $profit = $resolver->profitForItem(
                unitPrice: (float) $item['unit_price'],
                quantity: (int) $item['quantity'],
                costPrice: $resolved['cost_price'],
                shippingCost: $resolved['shipping_cost'],
                vatPercent: $resolved['vat_percent'],
            );
            $total += $profit;
            $lines[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'cost_price' => $resolved['cost_price'],
                'shipping_cost' => $resolved['shipping_cost'],
                'vat_percent' => $resolved['vat_percent'],
                'source' => $resolved['source'],
                'profit' => $profit,
            ];
        }

        return response()->json([
            'marketer' => [
                'id' => $marketer->id,
                'code' => $marketer->code,
                'tier' => $marketer->priceTier?->name,
                'group' => $marketer->priceGroup?->name,
            ],
            'lines' => $lines,
            'total' => round($total, 2),
        ]);
    }

    /**
     * Marketer ownership: marketers can only access their own orders.
     * Phase 5 will replace `marketer_id` with the real marketer record id;
     * the gate is in place now so the moment Phase 5 lands, isolation
     * works without touching this controller.
     */
    private function authorizeOwnership(Order $order): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }

        if (! $user->isMarketer()) {
            return;
        }

        $marketerId = $user->marketer_id ?? null;
        if ($marketerId === null || $order->marketer_id !== $marketerId) {
            abort(403, 'You do not have access to this order.');
        }
    }
}
