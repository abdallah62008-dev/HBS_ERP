<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Customer C-5B Must-Fix (M2) — broadcast event fired after a merge
 * bulk-reassigns customer-scoped records (orders / returns / refunds /
 * notes / addresses / tags) from the source to the target customer.
 *
 * Why an event:
 *   - `CustomerMergeService::merge()` uses query-builder mass updates
 *     for the reassignment (one indexed UPDATE per table). That path
 *     intentionally avoids the per-row overhead of Eloquent `->save()`
 *     but it ALSO bypasses Eloquent model events (`updating` /
 *     `updated`).
 *   - Today no module subscribes to those events, but as soon as one
 *     does (e.g. marketer-wallet recompute when an order changes
 *     customer, search-index invalidation, n8n webhook), the bulk
 *     update would silently skip the listener.
 *   - This event is the canonical hook for such future subscribers.
 *     It fires AFTER the database transaction commits — listeners can
 *     safely re-read the new state.
 *
 * Listeners receive the IDs that moved per table, not the rows
 * themselves — they re-query if they need full models, keeping the
 * event payload tiny + immutable.
 */
class CustomerRecordsReassigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $sourceCustomerId,
        public readonly int $targetCustomerId,
        public readonly ?int $mergeId,
        /** @var array<int, int> */ public readonly array $orderIds,
        /** @var array<int, int> */ public readonly array $returnIds,
        /** @var array<int, int> */ public readonly array $refundIds,
        /** @var array<int, int> */ public readonly array $noteIds,
        /** @var array<int, int> */ public readonly array $addressIds,
        /** @var array<int, int> */ public readonly array $tagIds,
        public readonly ?int $actorId,
    ) {}
}
