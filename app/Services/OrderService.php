<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Marketer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates order creation and status transitions.
 *
 * Responsibilities:
 *   - Generates the order number
 *   - Snapshots customer + product data onto the order/order_items rows
 *   - Computes subtotal/tax/total/profit at create-time
 *   - Writes order_status_history rows on every status change
 *   - Writes audit_logs entries via AuditLogService
 *   - Refreshes the customer's risk score on creation
 *   - (Phase 3) Inventory hooks: Reserve on Confirmed, Release on Cancel,
 *     Ship on Shipped, Return To Stock / Return Damaged on Returned
 *
 * What it does NOT do yet (later phases):
 *   - Marketer wallet / profit accrual (Phase 5)
 *   - Shipping checklist / pre-ship photo (Phase 4)
 *
 * Each later-phase concern gets a hook here so controllers don't grow
 * branching logic.
 */
class OrderService
{
    public function __construct(
        private readonly DuplicateDetectionService $duplicateService,
        private readonly CustomerRiskService $riskService,
        private readonly InventoryService $inventory,
        private readonly ShippingChecklistService $shippingChecklist,
        private readonly MarketerWalletService $marketerWallet,
        private readonly ProfitGuardService $profitGuard,
        private readonly MarketerPricingResolver $marketerPricing,
    ) {}

    /**
     * Create a brand-new order from a validated payload. The payload may
     * supply either an existing customer_id OR a `customer.*` block to
     * create the customer inline.
     *
     * @param  array<string,mixed>  $payload
     */
    public function createFromPayload(array $payload): Order
    {
        // Profit Guard runs BEFORE the transaction so a block-action raises
        // a clean error to the controller without poisoning the DB session.
        $this->profitGuard->evaluateOrThrow($payload['items'] ?? []);

        return DB::transaction(function () use ($payload) {
            $userId = Auth::id();
            $user = Auth::user();

            $customer = $this->resolveCustomer($payload, $userId);

            $fiscalYear = FiscalYear::where('status', 'Open')
                ->latest('start_date')
                ->firstOrFail();

            // Phase 5.4: derive entry_code at create time from
            //   1) marketer.code if order has a marketer_id
            //   2) user.entry_code on the creator
            //   3) initials from the creator's name (uppercase, alphanum-only, max 16)
            $entryCode = $this->resolveEntryCode($payload['marketer_id'] ?? null, $user);

            // Compute profit math from items BEFORE persisting so the row
            // has correct totals at INSERT time (no follow-up UPDATE).
            // Phase 5.9: when the order has a marketer, the per-item
            // marketer cost/shipping/VAT are resolved through the tier
            // chain (specific → tier → product default).
            $marketer = ! empty($payload['marketer_id'])
                ? Marketer::find($payload['marketer_id'])
                : null;
            $itemsData = $this->buildItemRows($payload['items'], $marketer);
            $totals = $this->computeTotals(
                $itemsData,
                discount: (float) ($payload['discount_amount'] ?? 0),
                shipping: (float) ($payload['shipping_amount'] ?? 0),
                extra: (float) ($payload['extra_fees'] ?? 0),
                marketer: $marketer,
            );

            // Duplicate detection snapshot.
            $dup = $this->duplicateService->evaluate([
                'primary_phone' => $customer->primary_phone,
                'secondary_phone' => $customer->secondary_phone,
                'customer_name' => $customer->name,
                'city' => $payload['city'] ?? $customer->city,
                'customer_address' => $payload['customer_address'],
                'product_ids' => array_column($itemsData, 'product_id'),
                'customer_id' => $customer->id,
            ]);

            // Customer risk snapshot (uses persisted score).
            $risk = $this->riskService->calculate($customer);

            $order = Order::create([
                'order_number' => OrderNumberService::generate($fiscalYear),
                'fiscal_year_id' => $fiscalYear->id,
                'customer_id' => $customer->id,
                'marketer_id' => $payload['marketer_id'] ?? null,
                'source' => $payload['source'] ?? null,
                'external_order_reference' => $payload['external_order_reference'] ?? null,
                'entry_code' => $entryCode,
                'status' => 'New',
                'collection_status' => 'Not Collected',
                'shipping_status' => 'Not Shipped',

                'customer_name' => $customer->name,
                'customer_phone' => $customer->primary_phone,
                // Phase 5.8: snapshot the secondary phone and WhatsApp
                // reachability flag onto the order. Falls back to the
                // customer's stored values when the form omits them.
                'customer_phone_secondary' => $payload['customer_phone_secondary']
                    ?? $customer->secondary_phone,
                'customer_phone_whatsapp' => array_key_exists('customer_phone_whatsapp', $payload)
                    ? (bool) $payload['customer_phone_whatsapp']
                    : (bool) ($customer->primary_phone_whatsapp ?? true),
                'customer_address' => $payload['customer_address'],
                'city' => $payload['city'] ?? $customer->city,
                'governorate' => $payload['governorate'] ?? $customer->governorate,
                'country' => $payload['country'] ?? $customer->country,

                'currency_code' => SettingsService::get('currency_code', 'EGP'),
                ...$totals,

                'customer_risk_score' => $risk['score'],
                'customer_risk_level' => $risk['level'],
                'duplicate_score' => $dup['score'],

                'notes' => $payload['notes'] ?? null,
                'internal_notes' => $payload['internal_notes'] ?? null,

                'created_by' => $userId,
            ]);

            foreach ($itemsData as $row) {
                $order->items()->create($row);
            }

            $this->writeStatusHistory($order, oldStatus: null, newStatus: 'New', userId: $userId);

            AuditLogService::logModelChange(
                $order,
                action: 'created',
                module: 'orders',
            );

            // Phase 5: open the marketer's expected-profit accrual.
            if ($order->marketer_id) {
                $this->marketerWallet->syncFromOrder($order->fresh());
            }

            return $order->load('items');
        });
    }

    /**
     * Move an order to a new status, persist history, audit-log it, and
     * fire any inventory hooks the transition implies.
     *
     * Inventory hooks (per 04_BUSINESS_WORKFLOWS.md §6, §7, §10, §12):
     *   any → Confirmed                  : Reserve stock
     *   Confirmed → Cancelled            : Release Reservation
     *   Confirmed/* → Shipped            : Ship (auto-releases reservation)
     *   Shipped → Returned (good cond.)  : Return To Stock + reverse the Ship
     *
     * Phase 4: any transition into "Shipped" must pass the shipping
     * checklist (ShippingChecklistService). Phase 5 wires marketer
     * wallet accrual into the Delivered transition.
     */
    public function changeStatus(Order $order, string $newStatus, ?string $note = null): Order
    {
        if (! in_array($newStatus, Order::STATUSES, true)) {
            throw new RuntimeException("Unknown order status: {$newStatus}");
        }

        if ($order->status === $newStatus) {
            return $order;
        }

        // Shipping checklist gate. Throws if any blocking rule fails;
        // surfaces a single line summarising the first failure so the UI
        // controller can show it as a flash message. (The checklist
        // page itself enumerates all failures.)
        if ($newStatus === 'Shipped') {
            $result = $this->shippingChecklist->evaluate($order);
            if (! $result['passed']) {
                $firstFail = collect($result['checks'])->firstWhere('ok', false);
                throw new RuntimeException(
                    'Shipping checklist failed: ' . ($firstFail['message'] ?? 'unknown reason')
                );
            }
        }

        $oldStatus = $order->status;

        return DB::transaction(function () use ($order, $oldStatus, $newStatus, $note) {
            $userId = Auth::id();
            $now = now();

            $patch = ['status' => $newStatus];

            // Stamp the appropriate transition timestamp.
            match ($newStatus) {
                'Confirmed' => $patch += ['confirmed_by' => $userId, 'confirmed_at' => $now],
                'Packed' => $patch += ['packed_by' => $userId, 'packed_at' => $now],
                'Shipped' => $patch += ['shipped_by' => $userId, 'shipped_at' => $now],
                'Delivered' => $patch += ['delivered_at' => $now],
                'Returned' => $patch += ['returned_at' => $now],
                default => null,
            };

            $order->forceFill($patch + ['updated_by' => $userId])->save();

            $this->applyInventoryForTransition($order, $oldStatus, $newStatus);

            $this->writeStatusHistory($order, $oldStatus, $newStatus, $userId, $note);

            AuditLogService::log(
                action: 'status_change',
                module: 'orders',
                recordType: Order::class,
                recordId: $order->id,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus, 'note' => $note],
            );

            // Phase 5: keep the marketer's profit lifecycle in sync with
            // the order's status (Expected → Pending → Earned → Cancelled).
            if ($order->marketer_id) {
                $this->marketerWallet->syncFromOrder($order->fresh());
            }

            return $order->refresh();
        });
    }

    /**
     * Apply inventory side-effects implied by a status transition.
     *
     * Idempotency note: this method writes movements blindly — calling
     * it twice for the same transition would double-count. The caller
     * (changeStatus) guards against that via the early-return on
     * "status === newStatus".
     */
    private function applyInventoryForTransition(Order $order, string $oldStatus, string $newStatus): void
    {
        $warehouse = $this->inventory->defaultWarehouse();
        if (! $warehouse) {
            // No warehouse configured yet — silently skip inventory side-effects
            // so a brand-new install can still process orders. The dashboard
            // surfaces this as a setup nudge.
            return;
        }

        $items = $order->items()->get();

        foreach ($items as $item) {
            $args = [
                'productId' => $item->product_id,
                'variantId' => $item->product_variant_id,
                'warehouseId' => $warehouse->id,
                'quantity' => (int) $item->quantity,
                'reference' => $order,
            ];

            // any → Confirmed: reserve
            if ($newStatus === 'Confirmed' && $oldStatus !== 'Confirmed') {
                $this->inventory->reserve(...$args, notes: "Order {$order->order_number}");
                continue;
            }

            // Confirmed → Cancelled (or any → Cancelled): release reservation
            if ($newStatus === 'Cancelled') {
                $reserved = $this->inventory->reservationFor(
                    $args['productId'], $args['variantId'], $args['warehouseId'], $order
                );
                if ($reserved > 0) {
                    $this->inventory->releaseReservation(
                        ...['quantity' => $reserved] + $args,
                        notes: "Order {$order->order_number} cancelled",
                    );
                }
                continue;
            }

            // any → Shipped: ship (auto-releases existing reservation)
            if ($newStatus === 'Shipped' && $oldStatus !== 'Shipped') {
                $this->inventory->ship(...$args, notes: "Order {$order->order_number}");
                continue;
            }

            // (Shipped|Out for Delivery|Delivered) → Returned: write the
            // optimistic Return To Stock movement so on-hand reflects goods
            // back in the warehouse. ReturnService::inspect later either
            // keeps this (Good+restockable) or reverses it (Damaged).
            // Returned from any pre-ship status has no on-hand to restore —
            // skip silently rather than write a phantom +qty.
            $postShipStatuses = ['Shipped', 'Out for Delivery', 'Delivered'];
            if ($newStatus === 'Returned' && in_array($oldStatus, $postShipStatuses, true)) {
                $this->inventory->returnToStock(...$args, notes: "Order {$order->order_number} returned");
                continue;
            }
        }
    }

    /* ───────── helpers ───────── */

    /**
     * @param  array<int, array<string,mixed>>  $items
     * @return array<int, array<string,mixed>>
     */
    private function buildItemRows(array $items, ?Marketer $marketer = null): array
    {
        $rows = [];

        foreach ($items as $item) {
            /** @var Product $product */
            $product = Product::findOrFail($item['product_id']);
            $variant = ! empty($item['product_variant_id'])
                ? ProductVariant::find($item['product_variant_id'])
                : null;

            $unitPrice = (float) $item['unit_price'];
            $unitCost = (float) ($variant->cost_price ?? $product->cost_price);
            $quantity = (int) $item['quantity'];
            $discount = (float) ($item['discount_amount'] ?? 0);

            // Phase 5.9: resolve cost/shipping/VAT through the tier chain
            // when this order is for a marketer. Without a marketer the
            // legacy product-default cost still applies and shipping/VAT
            // stay null on the order item snapshot (no marketer profit).
            if ($marketer) {
                $resolved = $this->marketerPricing->resolveForItem(
                    $marketer,
                    $product->id,
                    $variant?->id,
                );
                $tradePrice = $resolved['cost_price'];
                $marketerShipping = $resolved['shipping_cost'];
                $marketerVat = $resolved['vat_percent'];
            } else {
                $tradePrice = (float) ($variant->marketer_trade_price ?? $product->marketer_trade_price);
                $marketerShipping = null;
                $marketerVat = null;
            }

            $taxRate = (float) ($product->tax_enabled ? $product->tax_rate : 0);
            $lineSubtotal = max(0, ($unitPrice * $quantity) - $discount);
            $taxAmount = round($lineSubtotal * ($taxRate / 100), 2);
            $totalPrice = round($lineSubtotal + $taxAmount, 2);

            $rows[] = [
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'sku' => $variant->sku ?? $product->sku,
                'product_name' => $product->name . ($variant ? " — {$variant->variant_name}" : ''),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_amount' => $taxAmount,
                'total_price' => $totalPrice,
                'unit_cost' => $unitCost,
                'marketer_trade_price' => $tradePrice,
                'marketer_shipping_cost' => $marketerShipping,
                'marketer_vat_percent' => $marketerVat,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, array<string,mixed>>  $items
     * @return array<string, float>
     */
    private function computeTotals(
        array $items,
        float $discount,
        float $shipping,
        float $extra,
        ?Marketer $marketer = null,
    ): array {
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $costTotal = 0.0;
        $tradeTotal = 0.0;
        $marketerProfit = 0.0;

        foreach ($items as $row) {
            $subtotal += ($row['unit_price'] * $row['quantity']) - $row['discount_amount'];
            $taxTotal += $row['tax_amount'];
            $costTotal += $row['unit_cost'] * $row['quantity'];
            $tradeTotal += $row['marketer_trade_price'] * $row['quantity'];

            // Phase 5.9: per-line marketer profit when a marketer is
            // attached. Uses the resolved tier shipping + VAT (or legacy
            // group / product-default fallbacks captured in buildItemRows).
            if ($marketer && $row['marketer_vat_percent'] !== null) {
                $marketerProfit += $this->marketerPricing->profitForItem(
                    unitPrice: (float) $row['unit_price'],
                    quantity: (int) $row['quantity'],
                    costPrice: (float) $row['marketer_trade_price'],
                    shippingCost: (float) ($row['marketer_shipping_cost'] ?? 0),
                    vatPercent: (float) $row['marketer_vat_percent'],
                );
            }
        }

        $total = max(0, $subtotal + $taxTotal + $shipping + $extra - $discount);

        // Phase 2 profit = revenue - product cost - shipping. Marketer
        // profit math uses trade price and lives in Phase 5.
        $gross = $total - $costTotal;
        $net = $gross - $shipping;

        return [
            'subtotal' => round($subtotal, 2),
            'discount_amount' => round($discount, 2),
            'shipping_amount' => round($shipping, 2),
            'tax_amount' => round($taxTotal, 2),
            'extra_fees' => round($extra, 2),
            'total_amount' => round($total, 2),
            'cod_amount' => round($total, 2),
            'product_cost_total' => round($costTotal, 2),
            'marketer_trade_total' => round($tradeTotal, 2),
            'gross_profit' => round($gross, 2),
            'net_profit' => round($net, 2),
            // Phase 5.9: order-level marketer profit. NULL when there is
            // no marketer attached so historic non-marketer orders aren't
            // misread as zero-profit marketer orders.
            'marketer_profit' => $marketer ? round($marketerProfit, 2) : null,
        ];
    }

    private function writeStatusHistory(
        Order $order,
        ?string $oldStatus,
        string $newStatus,
        ?int $userId,
        ?string $note = null,
    ): OrderStatusHistory {
        return OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'changed_by' => $userId,
            'notes' => $note,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    /**
     * Phase 5.4: derive the entry code stored on the order at create time.
     *
     * Precedence:
     *   1) If the order is linked to a marketer, use marketers.code.
     *   2) Else if the creator has users.entry_code set, use it.
     *   3) Else fall back to initials from the creator's name (uppercase,
     *      alphanumeric only, max 16 chars).
     *   4) If everything is blank (e.g. no auth user), return null — the
     *      order saves cleanly and `display_order_number` falls back to
     *      the bare `order_number`.
     */
    private function resolveEntryCode(?int $marketerId, ?\App\Models\User $user): ?string
    {
        if ($marketerId) {
            $code = \App\Models\Marketer::query()
                ->where('id', $marketerId)
                ->value('code');
            if ($code) {
                return mb_substr((string) $code, 0, 16);
            }
        }

        if ($user?->entry_code) {
            return mb_substr((string) $user->entry_code, 0, 16);
        }

        if ($user?->name) {
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

    private function resolveCustomer(array $payload, ?int $userId): Customer
    {
        if (! empty($payload['customer_id'])) {
            return Customer::findOrFail($payload['customer_id']);
        }

        $data = $payload['customer'] ?? [];

        return Customer::create([
            'name' => $data['name'],
            'primary_phone' => $data['primary_phone'],
            'secondary_phone' => $data['secondary_phone'] ?? null,
            // Phase 5.8: WhatsApp reachability defaults to true.
            'primary_phone_whatsapp' => array_key_exists('primary_phone_whatsapp', $data)
                ? (bool) $data['primary_phone_whatsapp']
                : true,
            'email' => $data['email'] ?? null,
            'city' => $data['city'],
            'governorate' => $data['governorate'] ?? null,
            'country' => $data['country'],
            'default_address' => $payload['customer_address'] ?? '',
            'created_by' => $userId,
        ]);
    }
}
