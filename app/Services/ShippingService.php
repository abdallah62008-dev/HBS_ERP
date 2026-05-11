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
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
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

        // Phase 6.5 — Render the shipping label with mPDF (not DomPDF).
        // DomPDF cannot run the Unicode Bidi Algorithm or do Arabic
        // letter-shaping, so customer names / addresses written in
        // Arabic appeared visually reversed and disconnected (سموحة
        // → هحومس). mPDF has native bidi + shaping and ships a
        // built-in Code 128 barcode renderer, so we use it for this
        // one template. Other PDFs in the app keep using DomPDF.
        $html = view('pdf.shipping_label', [
            'order' => $order,
            'shipment' => $shipment,
            'tracking' => $tracking,
            'barcode_value' => $barcodeValue,
            'qr_value' => $qrValue,
            'currency_symbol' => SettingsService::get('currency_symbol', ''),
        ])->render();

        // mPDF font registration: merge Cairo into the default font
        // catalogue. Files live in storage/fonts/ and are committed to
        // the repo (storage/fonts/.gitignore allows the source TTFs).
        $defaults = (new ConfigVariables())->getDefaults();
        $fontDirs = $defaults['fontDir'];
        $fontData = (new FontVariables())->getDefaults()['fontdata'];

        $mpdf = new Mpdf([
            // 4×6 inches in mm: 4 * 25.4 = 101.6, 6 * 25.4 = 152.4.
            'format' => [101.6, 152.4],
            'orientation' => 'P',
            'margin_top' => 3,
            'margin_bottom' => 3,
            'margin_left' => 5,
            'margin_right' => 5,
            'margin_header' => 0,
            'margin_footer' => 0,
            'fontDir' => array_merge($fontDirs, [storage_path('fonts')]),
            'fontdata' => $fontData + [
                // Cairo Latin subset for Latin / digits.
                'cairolatin' => [
                    'R' => 'Cairo-Latin-Regular.ttf',
                    'B' => 'Cairo-Latin-Bold.ttf',
                ],
                // For Arabic we rely on mPDF's bundled DejaVu Sans
                // (already in $fontData defaults). Tried Amiri and
                // Noto Naskh Arabic first — both ship with GPOS
                // Lookup Type 5 Format 3 (advanced mark-to-ligature
                // positioning) which mPDF 8.3 doesn't parse and
                // throws on. The previous fontsource "Cairo Arabic"
                // subset rendered fine but was missing glyphs (kaf
                // inside الإسكندرية came out wrong). DejaVu Sans
                // ships with mPDF, has full Arabic coverage with
                // proper letter-joining via GSUB, and is the only
                // mPDF-compatible Arabic-capable font we have.
            ],
            'default_font' => 'cairolatin',
            'default_font_size' => 9,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        // Document direction stays LTR; per-element direction comes
        // from the .rtl-text class in the template.
        $mpdf->SetDirectionality('ltr');
        $mpdf->WriteHTML($html);
        $pdfBytes = $mpdf->Output('', 'S');

        $relativePath = "shipping-labels/{$order->id}/" . now()->format('Ymd-His') . "-{$tracking}.pdf";
        Storage::disk('public')->put($relativePath, $pdfBytes);

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
