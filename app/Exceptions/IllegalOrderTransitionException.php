<?php

namespace App\Exceptions;

use App\Models\Order;
use RuntimeException;

/**
 * R11 — thrown when OrderService::changeStatus is asked to move an order
 * to a status that is not reachable from its current status per the
 * Order::ALLOWED_TRANSITIONS DAG.
 *
 * Carries enough context (order id, order number, from/to statuses) for
 * controller error responses and audit logging without leaking internals.
 *
 * Extends RuntimeException so existing callers that only catch
 * RuntimeException (or let it bubble to the framework handler) keep
 * working — the gate is additive.
 */
class IllegalOrderTransitionException extends RuntimeException
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly string $fromStatus,
        public readonly string $toStatus,
    ) {
        parent::__construct(sprintf(
            'Illegal order transition: order %s (#%d) cannot move from "%s" to "%s".',
            $orderNumber,
            $orderId,
            $fromStatus,
            $toStatus,
        ));
    }

    /**
     * Build the exception straight from an Order and a target status.
     */
    public static function from(Order $order, string $toStatus): self
    {
        return new self(
            orderId: $order->id,
            orderNumber: (string) $order->order_number,
            fromStatus: (string) $order->status,
            toStatus: $toStatus,
        );
    }

    /**
     * The statuses the order COULD legally have moved to — useful for
     * 422 API responses and "did you mean…" error UI.
     *
     * @return array<int, string>
     */
    public function allowedTargets(): array
    {
        return Order::ALLOWED_TRANSITIONS[$this->fromStatus] ?? [];
    }
}
