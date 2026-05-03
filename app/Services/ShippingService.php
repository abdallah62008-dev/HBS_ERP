<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Collection;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingLabel;
use App\Models\ShippingRate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Manages shipment + label lifecycle for an order.
 *
 * Responsibilities:
 *   - assignToCompany(): create the shipment row, generate a tracking
 *     number if the company doesn't have an API integration, also
 *     creates a Collection row when the order is COD
 *   - generateLabelPdf(): render the 4×6 label, persist a PDF to disk,
 *     and write a shipping_labels row
 *   - markPickedUp / markInTransit / markOutForDelivery / markDelivered
 *     / markReturned / markDelayed: simple status transitions on the
 *     active shipment, with audit log entries
 *
 * The order itself is updated via OrderService::changeStatus, which is
 * gated by ShippingChecklistService for the actual `Shipped` move.
 */
class ShippingService
{
    public function assignToCompany(Order $order, int $shippingCompanyId, ?string $trackingNumber = null): Shipment
    {
        return DB::transaction(function () use ($order, $shippingCompanyId, $trackingNumber) {
            // Refuse if there's already an active shipment.
            $existing = $order->activeShipment;
            if ($existing) {
                throw new RuntimeException("Order {$order->order_number} already has an active shipment.");
            }

            $company = ShippingCompany::findOrFail($shippingCompanyId);

            // Auto-generate tracking number if not provided.
            $tracking = $trackingNumber ?: self::generateTrackingNumber($order);

            $shipment = Shipment::create([
                'order_id' => $order->id,
                'shipping_company_id' => $company->id,
                'tracking_number' => $tracking,
                'shipping_status' => 'Assigned',
                'assigned_at' => now(),
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            // Mirror onto the order row (snapshot).
            $order->forceFill([
                'shipping_status' => 'Assigned',
                'updated_by' => Auth::id(),
            ])->save();

            // Create a Collection row for COD orders so the receivable is
            // tracked from day one. Skip if one already exists (e.g.
            // re-assignment).
            if ((float) $order->cod_amount > 0 && ! $order->collection) {
                Collection::create([
                    'order_id' => $order->id,
                    'shipping_company_id' => $company->id,
                    'amount_due' => $order->cod_amount,
                    'amount_collected' => 0,
                    'collection_status' => 'Not Collected',
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
            }

            AuditLogService::log(
                action: 'assigned',
                module: 'shipping',
                recordType: Shipment::class,
                recordId: $shipment->id,
                newValues: [
                    'order_number' => $order->order_number,
                    'company' => $company->name,
                    'tracking_number' => $tracking,
                ],
            );

            return $shipment;
        });
    }

    /**
     * Render the 4×6 PDF label, write it to storage, persist a row.
     *
     * Idempotent in the loose sense: each call writes a new label row
     * (so re-prints leave a paper trail) but uses the same tracking
     * number on the active shipment.
     */
    public function generateLabelPdf(Order $order): ShippingLabel
    {
        $shipment = $order->activeShipment ?: $order->shipments()->first();
        if (! $shipment) {
            throw new RuntimeException('Cannot print label: no shipment exists for this order.');
        }

        $shipment->loadMissing('shippingCompany');
        $order->loadMissing('items');

        $size = (string) SettingsService::get('label_size', '4x6');
        $tracking = $shipment->tracking_number;
        $barcodeValue = $tracking;
        $qrValue = url("/orders/{$order->id}");

        $pdf = Pdf::loadView('pdf.shipping_label', [
            'order' => $order,
            'shipment' => $shipment,
            'tracking' => $tracking,
            'barcode_value' => $barcodeValue,
            'qr_value' => $qrValue,
            'currency_symbol' => SettingsService::get('currency_symbol', ''),
        ])->setPaper([0, 0, 288, 432]); // 4×6 inches @72dpi = 288×432 pt

        $relativePath = "shipping-labels/{$order->id}/" . now()->format('Ymd-His') . "-{$tracking}.pdf";
        Storage::disk('public')->put($relativePath, $pdf->output());

        $label = ShippingLabel::create([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'label_size' => $size,
            'tracking_number' => $tracking,
            'barcode_value' => $barcodeValue,
            'qr_value' => $qrValue,
            'label_pdf_url' => Storage::url($relativePath),
            'printed_by' => Auth::id(),
            'printed_at' => now(),
            'created_at' => now(),
        ]);

        AuditLogService::log(
            action: 'label_printed',
            module: 'shipping',
            recordType: ShippingLabel::class,
            recordId: $label->id,
            newValues: ['order_number' => $order->order_number, 'tracking' => $tracking],
        );

        return $label;
    }

    public function markStatus(Shipment $shipment, string $newStatus, ?string $note = null): Shipment
    {
        if (! in_array($newStatus, Shipment::STATUSES, true)) {
            throw new RuntimeException("Unknown shipment status: {$newStatus}");
        }

        return DB::transaction(function () use ($shipment, $newStatus, $note) {
            $patch = ['shipping_status' => $newStatus, 'updated_by' => Auth::id()];

            match ($newStatus) {
                'Picked Up' => $patch['picked_up_at'] = now(),
                'Delivered' => $patch['delivered_at'] = now(),
                'Returned' => $patch['returned_at'] = now(),
                'Delayed' => $patch['delayed_reason'] = $note ?? 'No reason provided',
                default => null,
            };

            $shipment->forceFill($patch)->save();
            $shipment->order?->forceFill(['shipping_status' => $newStatus])->save();

            AuditLogService::log(
                action: 'status_change',
                module: 'shipping',
                recordType: Shipment::class,
                recordId: $shipment->id,
                newValues: ['status' => $newStatus, 'note' => $note],
            );

            return $shipment->refresh();
        });
    }

    /**
     * Look up the cost of a shipping rate (best-effort) for a given
     * order destination. Returns null if no matching rate row.
     */
    public function rateForOrder(Order $order, int $shippingCompanyId): ?ShippingRate
    {
        return ShippingRate::query()
            ->where('shipping_company_id', $shippingCompanyId)
            ->where('country', $order->country)
            ->where(function ($q) use ($order) {
                $q->where('city', $order->city)->orWhereNull('city');
            })
            ->where('status', 'Active')
            ->orderByDesc('city') // prefer specific match over null
            ->first();
    }

    /**
     * Phase-4 default: prefix + order id + 6 random digits. When a
     * company has API integration enabled (Phase 6+), the integration
     * supplies the canonical tracking number instead.
     */
    public static function generateTrackingNumber(Order $order): string
    {
        return sprintf(
            'HBS-%s-%s',
            $order->id,
            Str::upper(Str::random(8)),
        );
    }
}
