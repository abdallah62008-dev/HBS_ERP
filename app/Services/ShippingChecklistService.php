<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Order;

/**
 * Implements the shipping checklist defined in 04_BUSINESS_WORKFLOWS.md §8.
 *
 * Before an order can transition to `Shipped`, every relevant rule must
 * pass. Some rules are conditional on settings (e.g.
 * `shipping_photo_required`). Some rules can be overridden by managers
 * (high-risk override comes later via the approval system in Phase 8).
 *
 * Output is structured so the UI can render a checklist with green
 * ticks / red crosses + reasons, instead of just throwing a single
 * generic error.
 */
class ShippingChecklistService
{
    public function __construct(
        private readonly InventoryService $inventory,
    ) {}

    /**
     * @return array{passed:bool, checks:array<int, array{key:string, label:string, ok:bool, message:?string, blocking:bool}>}
     */
    public function evaluate(Order $order): array
    {
        $checks = [];

        // 1. Customer phone present
        $checks[] = $this->check(
            'phone',
            'Customer phone present',
            ! empty(trim((string) $order->customer_phone)),
            'Order has no customer phone — required for shipping.',
        );

        // 2. Full address
        $checks[] = $this->check(
            'address',
            'Delivery address complete',
            ! empty(trim((string) $order->customer_address))
            && ! empty(trim((string) $order->city))
            && ! empty(trim((string) $order->country)),
            'Address, city, or country is missing.',
        );

        // 3. At least one item
        $itemsCount = $order->items()->count();
        $checks[] = $this->check(
            'items',
            'Order has at least one item',
            $itemsCount > 0,
            'Order has no items.',
        );

        // 4. Shipping company assigned
        $shipment = $order->activeShipment;
        $checks[] = $this->check(
            'company',
            'Shipping company assigned',
            $shipment !== null,
            'No active shipment — assign a shipping company first.',
        );

        // 5. Tracking number present
        $checks[] = $this->check(
            'tracking',
            'Tracking number set',
            $shipment !== null && ! empty(trim((string) $shipment->tracking_number)),
            'Tracking number not yet generated.',
        );

        // 6. Pre-shipping photo (if setting requires it)
        $photoRequired = (bool) SettingsService::get('shipping_photo_required', true);
        if ($photoRequired) {
            $hasPhoto = Attachment::query()
                ->where('related_type', Order::class)
                ->where('related_id', $order->id)
                ->where('attachment_type', Attachment::TYPE_PRE_SHIPPING_PHOTO)
                ->exists();
            $checks[] = $this->check(
                'photo',
                'Pre-shipping photo uploaded',
                $hasPhoto,
                'Pre-shipping photo missing. Upload a photo of the packed goods.',
            );
        }

        // 7. 4x6 label printed (if setting requires it)
        $labelRequired = (bool) SettingsService::get('label_required_before_ship', true);
        if ($labelRequired && $shipment) {
            $hasLabel = $shipment->labels()->exists();
            $checks[] = $this->check(
                'label',
                '4×6 label printed',
                $hasLabel,
                'Print the 4×6 shipping label before shipping.',
            );
        }

        // 8. Inventory available for every item
        $stockOk = true;
        $stockReason = null;
        foreach ($order->items as $item) {
            $available = $this->inventory->onHandStock($item->product_id, $item->product_variant_id);
            if ($available < (int) $item->quantity) {
                $stockOk = false;
                $stockReason = "Not enough stock for SKU {$item->sku} (need {$item->quantity}, have {$available}).";
                break;
            }
        }
        $checks[] = $this->check(
            'inventory',
            'Inventory available',
            $stockOk,
            $stockReason ?? 'OK',
        );

        // 9. Not high-risk (or risk has been overridden — Phase 8 approval)
        $riskOverridden = (bool) ($order->internal_notes && str_contains($order->internal_notes, '[risk-override]'));
        $checks[] = $this->check(
            'risk',
            'Not flagged as high risk',
            $order->customer_risk_level !== 'High' || $riskOverridden,
            'Customer is flagged High risk. A manager must override before shipping.',
            blocking: true,
        );

        // 10. COD amount sane
        $codOk = (float) $order->cod_amount >= 0
            && (float) $order->cod_amount <= ((float) $order->total_amount + 0.01);
        $checks[] = $this->check(
            'cod',
            'COD amount sane',
            $codOk,
            'COD amount is invalid (negative or greater than total).',
        );

        $passed = collect($checks)->every(fn ($c) => $c['ok'] || ! $c['blocking']);

        return [
            'passed' => (bool) $passed,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key:string, label:string, ok:bool, message:?string, blocking:bool}
     */
    private function check(string $key, string $label, bool $ok, string $message, bool $blocking = true): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'message' => $ok ? null : $message,
            'blocking' => $blocking,
        ];
    }
}
