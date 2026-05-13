<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Returns/Refunds UX Fix — orchestrates an order status change that
 * may require a return record to be created atomically.
 *
 * Background: prior to this service, `POST /orders/{order}/status` set
 * `orders.status='Returned'` without creating a row in the `returns`
 * table. Operators then had to navigate to `/returns/create` to add
 * the return — and often forgot, leaving "Returned" orders with no
 * return record (no reason, no condition, no refund eligibility per
 * Phase 5C).
 *
 * This service wraps `ReturnService::open` + `OrderService::changeStatus`
 * in a single `DB::transaction` so they succeed or fail together.
 *
 * Design rules:
 *   - DO NOT duplicate business logic from OrderService / ReturnService.
 *     This service is pure orchestration.
 *   - The return is created BEFORE the status change so that downstream
 *     hooks (inventory return-to-stock, marketer profit reversal) see a
 *     consistent state where the return exists.
 *   - Non-`Returned` status changes pass through to `OrderService::changeStatus`
 *     unchanged — the wrapper has zero behavioural effect on those paths.
 *   - Existing "one return per order" rule is enforced before insert.
 *   - The new return starts in the standard `Pending` status. Inspection
 *     remains a separate explicit action (see ReturnService::inspect).
 *
 * @phpstan-type ReturnPayload array{
 *   return_reason_id?: int|string,
 *   product_condition?: string,
 *   refund_amount?: numeric,
 *   shipping_loss_amount?: numeric,
 *   notes?: ?string,
 * }
 * @phpstan-type StatusChangePayload array{
 *   status: string,
 *   note?: ?string,
 *   return?: ReturnPayload,
 * }
 */
class OrderStatusFlowService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly ReturnService $returns,
    ) {}

    /**
     * Change an order's status, creating a linked return when the target
     * is `Returned`.
     *
     * @param  StatusChangePayload  $data
     * @return array{order: Order, return: ?OrderReturn}
     */
    public function changeStatus(Order $order, array $data): array
    {
        $newStatus = (string) ($data['status'] ?? '');
        $note = $data['note'] ?? null;

        if ($newStatus !== 'Returned') {
            // Pass-through: no return creation, just delegate.
            $updated = $this->orders->changeStatus($order, $newStatus, $note);
            return ['order' => $updated, 'return' => null];
        }

        // Returned path — return creation must accompany the status change.
        if ($order->returns()->exists()) {
            throw new RuntimeException(
                "Order {$order->order_number} already has a return record and cannot be returned again."
            );
        }

        $returnPayload = $data['return'] ?? [];
        if (! isset($returnPayload['return_reason_id'])) {
            throw new RuntimeException(
                'A return reason is required when setting the order to Returned.'
            );
        }

        return DB::transaction(function () use ($order, $newStatus, $note, $returnPayload) {
            // 1. Create the return row first so any downstream hook
            //    triggered by the status change (inventory return-to-stock,
            //    marketer profit sync) observes a consistent state.
            $return = $this->returns->open([
                'order_id' => $order->id,
                'return_reason_id' => (int) $returnPayload['return_reason_id'],
                'product_condition' => $returnPayload['product_condition'] ?? 'Unknown',
                'refund_amount' => isset($returnPayload['refund_amount']) ? (float) $returnPayload['refund_amount'] : 0.0,
                'shipping_loss_amount' => isset($returnPayload['shipping_loss_amount']) ? (float) $returnPayload['shipping_loss_amount'] : 0.0,
                'notes' => $returnPayload['notes'] ?? null,
            ]);

            // 2. Change the order status. Existing OrderService:
            //    - stamps returned_at
            //    - writes order_status_history
            //    - audit logs status_change
            //    - calls InventoryService::returnToStock for post-ship transitions
            //    - calls MarketerWalletService::syncFromOrder
            // Any throw here rolls back the return created above.
            $updated = $this->orders->changeStatus($order, $newStatus, $note);

            return ['order' => $updated, 'return' => $return->refresh()];
        });
    }
}
