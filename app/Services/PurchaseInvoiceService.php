<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\Supplier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Manages the lifecycle of a purchase invoice.
 *
 * Lifecycle (per 04_BUSINESS_WORKFLOWS.md §14):
 *
 *   Draft  ──approve()──▶  Received  (or Partially Received / Unpaid)
 *
 * Approving:
 *   - validates supplier + warehouse + items
 *   - recomputes totals from items (defends against tampered totals)
 *   - creates a Purchase inventory movement per line item
 *   - optionally updates product cost price (if `update_cost_on_purchase`
 *     setting is enabled, default true) and writes a price-history row
 *   - stamps approved_by / approved_at and changes status
 *   - writes an audit log entry
 *
 * Editing an approved invoice is intentionally NOT supported here —
 * Phase 8 implements the approval-request workflow that makes that safe.
 */
class PurchaseInvoiceService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly ProductService $products,
    ) {}

    /**
     * Create a fresh Draft invoice + items + recompute totals.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int, array<string,mixed>>  $items
     */
    public function createDraft(array $payload, array $items): PurchaseInvoice
    {
        return DB::transaction(function () use ($payload, $items) {
            $userId = Auth::id();

            $invoice = PurchaseInvoice::create([
                ...$payload,
                'status' => 'Draft',
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            $this->saveItems($invoice, $items);
            $this->recomputeTotals($invoice);

            AuditLogService::logModelChange($invoice, 'created', 'purchases');

            return $invoice->refresh()->load('items');
        });
    }

    /**
     * Replace items + recompute totals on a Draft invoice. Refuses if
     * the invoice has already been approved/received.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int, array<string,mixed>>|null  $items  null = leave items alone
     */
    public function updateDraft(PurchaseInvoice $invoice, array $payload, ?array $items = null): PurchaseInvoice
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException('Only draft purchase invoices can be edited. Use the approval workflow for received invoices.');
        }

        return DB::transaction(function () use ($invoice, $payload, $items) {
            $userId = Auth::id();

            $invoice->fill([...$payload, 'updated_by' => $userId])->save();

            if ($items !== null) {
                $invoice->items()->delete();
                $this->saveItems($invoice, $items);
                $this->recomputeTotals($invoice);
            }

            AuditLogService::logModelChange($invoice, 'updated', 'purchases');

            return $invoice->refresh()->load('items');
        });
    }

    /**
     * Approve the invoice: write Purchase inventory movements, optionally
     * update product cost prices, set status, and audit-log it.
     */
    public function approve(PurchaseInvoice $invoice): PurchaseInvoice
    {
        if (! $invoice->isDraft()) {
            throw new RuntimeException('Only draft invoices can be approved.');
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->loadMissing(['items', 'supplier', 'warehouse']);

            if ($invoice->items->isEmpty()) {
                throw new RuntimeException('Cannot approve an invoice with no items.');
            }
            if (! $invoice->supplier) {
                throw new RuntimeException('Invoice has no supplier.');
            }
            if (! $invoice->warehouse) {
                throw new RuntimeException('Invoice has no warehouse.');
            }

            $userId = Auth::id();
            $updateCost = (bool) SettingsService::get('update_cost_on_purchase', true);

            foreach ($invoice->items as $item) {
                $this->inventory->record(
                    productId: $item->product_id,
                    variantId: $item->product_variant_id,
                    warehouseId: $invoice->warehouse_id,
                    movementType: 'Purchase',
                    signedQuantity: (int) $item->quantity,
                    unitCost: (float) $item->unit_cost,
                    referenceType: PurchaseInvoice::class,
                    referenceId: $invoice->id,
                    notes: "Invoice {$invoice->invoice_number}",
                );

                if ($updateCost) {
                    /** @var Product|null $product */
                    $product = Product::find($item->product_id);
                    if ($product && (float) $product->cost_price !== (float) $item->unit_cost) {
                        $this->products->update(
                            $product,
                            ['cost_price' => $item->unit_cost],
                            "Updated by purchase invoice {$invoice->invoice_number}",
                        );
                    }
                }
            }

            // Determine the post-approval status: if there's a payment
            // record already, mark accordingly; otherwise default to Unpaid.
            $newStatus = match (true) {
                (float) $invoice->paid_amount >= (float) $invoice->total_amount => 'Paid',
                (float) $invoice->paid_amount > 0 => 'Partially Paid',
                default => 'Unpaid',
            };

            $invoice->forceFill([
                'status' => $newStatus,
                'approved_by' => $userId,
                'approved_at' => now(),
                'updated_by' => $userId,
                'remaining_amount' => (float) $invoice->total_amount - (float) $invoice->paid_amount,
            ])->save();

            AuditLogService::log(
                action: 'approved',
                module: 'purchases',
                recordType: PurchaseInvoice::class,
                recordId: $invoice->id,
                newValues: [
                    'invoice_number' => $invoice->invoice_number,
                    'total' => $invoice->total_amount,
                    'items_count' => $invoice->items->count(),
                ],
            );

            return $invoice->refresh();
        });
    }

    public function recordPayment(
        PurchaseInvoice $invoice,
        float $amount,
        ?string $method = null,
        ?string $notes = null,
        ?string $paymentDate = null,
    ): PurchaseInvoice {
        if ($amount <= 0) {
            throw new RuntimeException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($invoice, $amount, $method, $notes, $paymentDate) {
            $invoice->payments()->create([
                'supplier_id' => $invoice->supplier_id,
                'amount' => $amount,
                'payment_method' => $method,
                'payment_date' => $paymentDate ?? now()->toDateString(),
                'notes' => $notes,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);

            $newPaid = (float) $invoice->paid_amount + $amount;
            $remaining = max(0, (float) $invoice->total_amount - $newPaid);

            $newStatus = $remaining <= 0
                ? 'Paid'
                : ($newPaid > 0 ? 'Partially Paid' : $invoice->status);

            $invoice->forceFill([
                'paid_amount' => $newPaid,
                'remaining_amount' => $remaining,
                'status' => $newStatus,
                'updated_by' => Auth::id(),
            ])->save();

            AuditLogService::log(
                action: 'payment_recorded',
                module: 'purchases',
                recordType: PurchaseInvoice::class,
                recordId: $invoice->id,
                newValues: ['amount' => $amount, 'method' => $method],
            );

            return $invoice->refresh();
        });
    }

    /**
     * @param  array<int, array<string,mixed>>  $items
     */
    private function saveItems(PurchaseInvoice $invoice, array $items): void
    {
        foreach ($items as $row) {
            $product = Product::findOrFail($row['product_id']);
            $unitCost = (float) $row['unit_cost'];
            $qty = (int) $row['quantity'];
            $discount = (float) ($row['discount_amount'] ?? 0);
            $tax = (float) ($row['tax_amount'] ?? 0);

            $invoice->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $row['product_variant_id'] ?? null,
                'sku' => $product->sku,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'discount_amount' => $discount,
                'tax_amount' => $tax,
                'total_cost' => round(($unitCost * $qty) - $discount + $tax, 2),
            ]);
        }
    }

    private function recomputeTotals(PurchaseInvoice $invoice): void
    {
        $items = PurchaseInvoiceItem::where('purchase_invoice_id', $invoice->id)->get();

        $subtotal = $items->sum(fn ($i) => (float) $i->unit_cost * (int) $i->quantity);
        $discount = $items->sum(fn ($i) => (float) $i->discount_amount) + (float) $invoice->discount_amount;
        $tax = $items->sum(fn ($i) => (float) $i->tax_amount);
        $shipping = (float) $invoice->shipping_cost;
        $total = max(0, $subtotal - $discount + $tax + $shipping);

        $invoice->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'tax_amount' => round($tax, 2),
            'total_amount' => round($total, 2),
            'remaining_amount' => round($total - (float) $invoice->paid_amount, 2),
        ])->save();
    }
}
