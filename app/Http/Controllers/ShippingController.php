<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Services\OrderService;
use App\Services\ShippingChecklistService;
use App\Services\ShippingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Aggregates the shipping workflow: dashboard, ready-to-pack, ready-to-ship,
 * shipments list, shipment detail, delayed list, and the action endpoints
 * (assign, mark status, run checklist).
 */
class ShippingController extends Controller
{
    public function __construct(
        private readonly ShippingService $shipping,
        private readonly ShippingChecklistService $checklist,
        private readonly OrderService $orders,
    ) {}

    /* ─────────────── Dashboard ─────────────── */

    public function dashboard(): Response
    {
        $kpis = [
            'ready_to_pack' => Order::where('status', 'Confirmed')->count(),
            'ready_to_ship' => Order::whereIn('status', ['Packed', 'Ready to Ship'])->count(),
            'in_transit' => Shipment::active()->count(),
            'delayed' => Shipment::where('shipping_status', 'Delayed')->count(),
        ];

        return Inertia::render('Shipping/Dashboard', [
            'kpis' => $kpis,
        ]);
    }

    /* ─────────────── Worklists ─────────────── */

    public function readyToPack(Request $request): Response
    {
        $orders = Order::query()
            ->where('status', 'Confirmed')
            ->with(['customer:id,name,primary_phone', 'items'])
            ->latest('confirmed_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Shipping/ReadyToPack', [
            'orders' => $orders,
        ]);
    }

    public function readyToShip(Request $request): Response
    {
        $orders = Order::query()
            ->whereIn('status', ['Packed', 'Ready to Ship'])
            ->with(['customer:id,name,primary_phone', 'items', 'activeShipment.shippingCompany:id,name'])
            ->latest('packed_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Shipping/ReadyToShip', [
            'orders' => $orders,
            'shipping_companies' => ShippingCompany::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /* ─────────────── Shipments list ─────────────── */

    public function shipments(Request $request): Response
    {
        $filters = $request->only(['status', 'shipping_company_id', 'q']);

        $shipments = Shipment::query()
            ->with(['order:id,order_number,customer_name,total_amount,city', 'shippingCompany:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('shipping_status', $v))
            ->when($filters['shipping_company_id'] ?? null, fn ($q, $v) => $q->where('shipping_company_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('tracking_number', 'like', "%{$term}%")
                        ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")
                            ->orWhere('customer_name', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Shipping/Shipments/Index', [
            'shipments' => $shipments,
            'filters' => $filters,
            'companies' => ShippingCompany::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function showShipment(Shipment $shipment): Response
    {
        $shipment->load([
            'order.customer:id,name,primary_phone',
            'order.items',
            'shippingCompany:id,name',
            'labels.printedBy:id,name',
        ]);

        return Inertia::render('Shipping/Shipments/Show', [
            'shipment' => $shipment,
        ]);
    }

    public function delayed(): Response
    {
        // "Delayed" shipments = explicitly marked Delayed OR active for too
        // long. We surface both via two queries to stay readable.
        $threshold = now()->subDays((int) (config('app.delay_threshold_days', 7)));

        $explicit = Shipment::query()
            ->where('shipping_status', 'Delayed')
            ->with(['order:id,order_number,customer_name', 'shippingCompany:id,name'])
            ->latest('id')
            ->limit(50)
            ->get();

        $stale = Shipment::query()
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->where('shipping_status', '!=', 'Delayed')
            ->where('assigned_at', '<', $threshold)
            ->with(['order:id,order_number,customer_name', 'shippingCompany:id,name'])
            ->latest('id')
            ->limit(50)
            ->get();

        return Inertia::render('Shipping/Delayed', [
            'explicit' => $explicit,
            'stale' => $stale,
            'threshold_days' => (int) (config('app.delay_threshold_days', 7)),
        ]);
    }

    /* ─────────────── Actions ─────────────── */

    /**
     * Assign an order to a shipping company. Optionally moves the order
     * status to "Ready to Ship" so it falls off the ready-to-pack list.
     */
    public function assign(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate([
            'shipping_company_id' => ['required', 'exists:shipping_companies,id'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            'mark_ready_to_ship' => ['nullable', 'boolean'],
        ]);

        try {
            $this->shipping->assignToCompany(
                $order,
                (int) $data['shipping_company_id'],
                $data['tracking_number'] ?? null,
            );

            if (! empty($data['mark_ready_to_ship']) && $order->status === 'Confirmed') {
                $this->orders->changeStatus($order->refresh(), 'Ready to Ship', 'Auto-set after carrier assignment');
            }
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Shipment assigned.');
    }

    /**
     * Run the checklist on demand (for the UI panel) — does not change state.
     */
    public function checklist(Order $order): Response
    {
        $order->loadMissing(['items', 'activeShipment.shippingCompany']);

        return Inertia::render('Shipping/Checklist', [
            'order' => $order,
            'result' => $this->checklist->evaluate($order),
        ]);
    }

    /**
     * Manually mark an order as Packed (from ready-to-pack).
     */
    public function markPacked(Order $order): RedirectResponse
    {
        try {
            $this->orders->changeStatus($order, 'Packed', 'Marked Packed from worklist');
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order {$order->order_number} marked Packed.");
    }

    /**
     * Confirm shipping — runs the checklist gate and, if it passes,
     * transitions the order to Shipped (which fires the inventory ship
     * hook in Phase 3).
     */
    public function confirmShipped(Order $order): RedirectResponse
    {
        try {
            $this->orders->changeStatus($order, 'Shipped', 'Confirmed via Ready-to-Ship');
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Order {$order->order_number} shipped.");
    }

    public function markShipmentStatus(Request $request, Shipment $shipment): RedirectResponse
    {
        $data = $request->validate([
            'shipping_status' => ['required', 'in:'.implode(',', Shipment::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->shipping->markStatus($shipment, $data['shipping_status'], $data['note'] ?? null);
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Shipment status updated.');
    }
}
