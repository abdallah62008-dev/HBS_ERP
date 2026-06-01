<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Shipment;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R3 — Public, signed-URL order tracking.
 *
 * Customer-facing page accessed without authentication via a signed
 * URL such as `/track/ORD-2026-000042?signature=...`. The signature is
 * verified by Laravel's `signed` middleware on the route; this
 * controller assumes the URL has already been validated.
 *
 * Strictly read-only and emits ONLY customer-safe fields:
 *   - the order number, customer name (snapshot), total + currency
 *   - lifecycle status + per-stage timestamps
 *   - carrier name + carrier-side tracking number (if assigned)
 *   - status history rows: old/new status + when only — `notes` field
 *     is deliberately stripped because it may contain operator
 *     internals
 *
 * Deliberately excluded: items + pricing breakdown, cost / profit,
 * marketer info, customer phone / address, internal_notes.
 */
class PublicTrackingController extends Controller
{
    public function __invoke(string $orderNumber): Response
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with(['statusHistory' => fn ($q) => $q->orderBy('id')])
            ->firstOrFail();

        // Latest shipment row — pulled via the model directly so this
        // controller does not depend on an `activeShipment` relation
        // being defined on Order.
        $shipment = Shipment::query()
            ->where('order_id', $order->id)
            ->with('shippingCompany:id,name')
            ->latest('id')
            ->first();

        return Inertia::render('PublicTracking', [
            'order' => [
                'order_number' => $order->order_number,
                'customer_name' => $order->customer_name,
                'status' => $order->status,
                'shipping_status' => $order->shipping_status,
                'collection_status' => $order->collection_status,
                'currency_code' => $order->currency_code,
                'total_amount' => (float) $order->total_amount,
                'created_at' => $order->created_at?->toIso8601String(),
                'confirmed_at' => $order->confirmed_at?->toIso8601String(),
                'packed_at' => $order->packed_at?->toIso8601String(),
                'shipped_at' => $order->shipped_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'returned_at' => $order->returned_at?->toIso8601String(),
            ],
            'shipment' => $shipment ? [
                'shipping_status' => $shipment->shipping_status,
                'carrier_name' => $shipment->shippingCompany?->name,
                'tracking_number' => $shipment->tracking_number,
            ] : null,
            'timeline' => $order->statusHistory->map(fn ($row) => [
                'old_status' => $row->old_status,
                'new_status' => $row->new_status,
                'at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }
}
