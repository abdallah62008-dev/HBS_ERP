<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * R6 — thrown by OrderService::changeStatus when an order would have
 * confirmed but the configured high-value / high-discount thresholds
 * require human approval first. An ApprovalRequest row is already
 * created (or referenced if one was already pending) when this is thrown.
 *
 * Extends RuntimeException so existing controller `catch
 * (InvalidArgumentException|RuntimeException)` blocks absorb it cleanly
 * — the operator sees a flash message indicating an approval request
 * has been created, not a generic error.
 */
class ApprovalRequiredException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $approvalRequestId,
    ) {
        parent::__construct($message);
    }
}
