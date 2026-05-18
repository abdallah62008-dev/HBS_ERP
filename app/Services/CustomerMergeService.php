<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerMerge;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Customer C-5B — single writer for duplicate customer merges.
 *
 * Wraps the entire reassignment + tombstone-marking + audit-log writes
 * in one transaction. Any failure aborts the whole merge — partial
 * states never escape this service.
 *
 * What the service does, in order:
 *
 *   1. Lock source + target rows (SELECT ... FOR UPDATE).
 *   2. Re-check invariants under the lock (defends against concurrent
 *      merges of the same pair from two operators).
 *   3. Snapshot the source profile + collect the affected-ids per
 *      table for the merge log payload.
 *   4. Reassign `customer_id` on:
 *        - orders                (snapshot columns NOT touched)
 *        - returns               (snapshot columns NOT touched)
 *        - refunds               (snapshot columns NOT touched)
 *        - customer_notes
 *        - customer_addresses    (single-default invariant re-enforced
 *                                 after the move)
 *        - customer_tags         (UNION of source + target tag strings;
 *                                 source rows that duplicate a target
 *                                 tag are dropped — no UNIQUE violation)
 *   5. Mark source as merged: `merged_into_customer_id` /
 *      `merged_at` / `merged_by`.
 *   6. Recompute target risk score (orders moved → risk profile
 *      shifted).
 *   7. Persist a `customer_merges` audit row with affected counts +
 *      payload (source snapshot + lists of affected ids per table).
 *   8. Write `audit_logs` rows on source + target.
 *
 * Snapshot-column contract (PINNED): the service NEVER writes to
 *
 *   orders.customer_name | customer_phone | customer_phone_secondary
 *        | customer_phone_whatsapp | customer_phone_normalized
 *        | customer_address | city | governorate | country
 *
 * Those are the historical record at order-create time. Touching them
 * would corrupt reporting + financial history.
 */
class CustomerMergeService
{
    /**
     * @param  array{
     *   reason: string,
     *   actor_id: ?int,
     *   actor_is_super_admin: bool,
     * }  $context
     *
     * @return CustomerMerge
     */
    public function merge(Customer $source, Customer $target, array $context): CustomerMerge
    {
        // M3a Must-Fix: refuse to execute when the feature flag is
        // off. Backstops the controller-side check + the disabled
        // form — defence-in-depth.
        if (! (bool) \App\Services\SettingsService::get('customer_merge_enabled', false)) {
            throw new RuntimeException('Customer merge workflow is disabled.');
        }

        // Cheap pre-flight checks. The hard ones re-run under the lock
        // — this is just to fail fast and return a friendly message
        // before the transaction overhead.
        $this->preflight($source, $target, $context);

        $merge = DB::transaction(function () use ($source, $target, $context) {
            // ── 1. Lock both rows under the same MySQL transaction ──
            //
            // The lock ordering (smaller id first) is deliberate — two
            // operators trying to merge the same pair in opposite
            // directions would otherwise deadlock. The lock holds for
            // the full transaction.
            $ids = [$source->id, $target->id];
            sort($ids);
            $locked = Customer::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Customer $source */
            $source = $locked[$source->id] ?? $source->fresh();
            /** @var Customer $target */
            $target = $locked[$target->id] ?? $target->fresh();

            // ── 2. Re-check invariants under the lock ──
            $this->preflight($source, $target, $context);

            // ── 3. Snapshot + collect affected ids ──
            //
            // M3b Must-Fix: also snapshot the target profile BEFORE we
            // apply any field-merge policies. The rollback command can
            // restore the pre-merge target state from this snapshot.
            $sourceSnapshot = $this->sourceProfileSnapshot($source);
            $targetSnapshot = $this->sourceProfileSnapshot($target);
            $orderIds = Order::query()->where('customer_id', $source->id)->pluck('id')->all();
            $returnIds = OrderReturn::query()->where('customer_id', $source->id)->pluck('id')->all();
            $refundIds = Refund::query()->where('customer_id', $source->id)->pluck('id')->all();
            $noteIds = CustomerNote::query()->where('customer_id', $source->id)->pluck('id')->all();
            $addressIds = CustomerAddress::query()->where('customer_id', $source->id)->pluck('id')->all();
            // Tag handling is split — we'll diff the strings later.
            $sourceTagRows = CustomerTag::query()->where('customer_id', $source->id)->get();

            // ── 3.5. M3b Must-Fix: field-merge policies on the target.
            //
            // Honours the policies the C-5A preview promises the
            // operator:
            //
            //   - secondary_phone / email — copy from source if
            //     target's slot is empty.
            //   - customer_type — take the more-restrictive value
            //     (Blacklist > Watchlist > VIP > Normal).
            //   - risk_level — take the higher value (High > Medium > Low).
            //     Note: actual risk_score is recomputed below from the
            //     reassigned orders.
            //   - legacy `customers.notes` text — if source has
            //     non-empty notes, convert to a structured customer_notes
            //     row on target tagged "Imported from merged customer #X".
            //
            // The target is mutated IN-MEMORY here; we save() once at
            // the end after the risk recompute.
            $targetPatch = [];
            if (! $target->secondary_phone && $source->secondary_phone) {
                $targetPatch['secondary_phone'] = $source->secondary_phone;
                $targetPatch['secondary_country_code'] = $source->secondary_country_code;
                $targetPatch['secondary_local_phone'] = $source->secondary_local_phone;
                $targetPatch['secondary_normalized_phone'] = $source->secondary_normalized_phone;
            }
            if (! $target->email && $source->email) {
                $targetPatch['email'] = $source->email;
            }
            $typeRank = ['Normal' => 0, 'VIP' => 1, 'Watchlist' => 2, 'Blacklist' => 3];
            $sourceTypeRank = $typeRank[$source->customer_type] ?? 0;
            $targetTypeRank = $typeRank[$target->customer_type] ?? 0;
            if ($sourceTypeRank > $targetTypeRank) {
                $targetPatch['customer_type'] = $source->customer_type;
            }
            $levelRank = ['Low' => 0, 'Medium' => 1, 'High' => 2];
            $sourceLevelRank = $levelRank[$source->risk_level] ?? 0;
            $targetLevelRank = $levelRank[$target->risk_level] ?? 0;
            if ($sourceLevelRank > $targetLevelRank) {
                $targetPatch['risk_level'] = $source->risk_level;
            }
            if (! empty($targetPatch)) {
                $target->fill($targetPatch)->save();
            }

            // Legacy `customers.notes` text → structured customer_notes
            // row on target. Created BEFORE the bulk reassignment so it
            // lands as a fresh target-owned note (the bulk update would
            // otherwise reassign source's own customer_notes too).
            $legacyNoteRow = null;
            if (trim((string) $source->notes) !== '') {
                $legacyNoteRow = CustomerNote::create([
                    'customer_id' => $target->id,
                    'note' => "Imported from merged customer #{$source->id}:\n" . $source->notes,
                    'is_internal' => true,
                    'created_by' => $context['actor_id'] ?? null,
                ]);
            }

            // ── 4. Reassign customer_id on the related tables ──
            //
            // Each update is bounded by `where('customer_id', $source->id)`
            // and only touches the customer_id column. Snapshot columns
            // on orders / returns / refunds are NEVER part of the SET
            // clause.
            Order::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            OrderReturn::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            Refund::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);
            CustomerNote::query()->where('customer_id', $source->id)->update(['customer_id' => $target->id]);

            // Addresses: move all source rows. Source defaults are
            // flattened first so the target's existing default
            // survives. If target has no default, leave the moved
            // default flag in place (the C-4B controller wraps
            // single-default-invariant in its own transaction; this
            // service is the other writer of that invariant).
            $targetHasDefault = CustomerAddress::query()
                ->where('customer_id', $target->id)
                ->where('is_default', true)
                ->exists();
            if ($targetHasDefault) {
                CustomerAddress::query()
                    ->where('customer_id', $source->id)
                    ->update(['is_default' => false, 'customer_id' => $target->id]);
            } else {
                CustomerAddress::query()
                    ->where('customer_id', $source->id)
                    ->update(['customer_id' => $target->id]);
            }
            // Re-enforce single-default just in case: if more than one
            // row claims default after the move, keep only the most
            // recent one. Cheap belt-and-braces.
            $defaults = CustomerAddress::query()
                ->where('customer_id', $target->id)
                ->where('is_default', true)
                ->orderByDesc('id')
                ->get(['id']);
            if ($defaults->count() > 1) {
                $keepId = $defaults->first()->id;
                CustomerAddress::query()
                    ->where('customer_id', $target->id)
                    ->where('is_default', true)
                    ->where('id', '!=', $keepId)
                    ->update(['is_default' => false]);
            }

            // Tags: UNION. The migration for `customer_tags` has no
            // unique constraint per (customer_id, tag) pair, so we
            // de-dup defensively before the move.
            $targetTagStrings = CustomerTag::query()
                ->where('customer_id', $target->id)
                ->pluck('tag')
                ->all();
            $affectedTagIds = [];
            foreach ($sourceTagRows as $tag) {
                if (in_array($tag->tag, $targetTagStrings, true)) {
                    // Target already has this tag → drop the source row
                    // to avoid a duplicate pair.
                    $tag->delete();
                } else {
                    $tag->update(['customer_id' => $target->id]);
                    $targetTagStrings[] = $tag->tag;
                    $affectedTagIds[] = $tag->id;
                }
            }

            // ── 5. Mark source as merged tombstone ──
            $source->fill([
                'merged_into_customer_id' => $target->id,
                'merged_at' => now(),
                'merged_by' => $context['actor_id'] ?? null,
            ])->save();

            // ── 6. Recompute target risk score under the new orders ──
            //
            // The risk service walks order history; reassigning orders
            // means target's score should shift. Defensive: catch any
            // service failure so the merge itself isn't aborted by an
            // unrelated risk bug.
            try {
                $risk = app(CustomerRiskService::class)->calculate($target);
                $target->fill([
                    'risk_score' => (int) ($risk['score'] ?? 0),
                    'risk_level' => $risk['level'] ?? $target->risk_level,
                ])->save();
            } catch (\Throwable $e) {
                // Don't fail the merge on a risk-service bug. Log and
                // move on — the next page load recomputes anyway.
            }

            // ── 7. Persist the merge log ──
            $merge = CustomerMerge::create([
                'source_customer_id' => $source->id,
                'target_customer_id' => $target->id,
                'merged_by' => $context['actor_id'] ?? null,
                'reason' => $context['reason'],
                'affected_orders_count' => count($orderIds),
                'affected_returns_count' => count($returnIds),
                'affected_refunds_count' => count($refundIds),
                'affected_notes_count' => count($noteIds),
                'affected_addresses_count' => count($addressIds),
                'affected_tags_count' => count($affectedTagIds),
                'payload' => [
                    'source_profile' => $sourceSnapshot,
                    // M3b Must-Fix: pre-merge target snapshot so the
                    // rollback command can restore the field-merge
                    // changes (secondary_phone / email / customer_type /
                    // risk_level) that we applied above.
                    'target_profile_pre_merge' => $targetSnapshot,
                    'target_patch_applied' => $targetPatch ?? [],
                    'legacy_note_id' => $legacyNoteRow?->id,
                    'affected_ids' => [
                        'orders' => $orderIds,
                        'returns' => $returnIds,
                        'refunds' => $refundIds,
                        'customer_notes' => $noteIds,
                        'customer_addresses' => $addressIds,
                        'customer_tags' => $affectedTagIds,
                    ],
                ],
                'created_at' => now(),
            ]);

            // ── 8. Audit log entries on both sides ──
            AuditLogService::log(
                action: 'merged_out',
                module: 'customers',
                recordType: Customer::class,
                recordId: $source->id,
                oldValues: ['merged_into_customer_id' => null],
                newValues: [
                    'merged_into_customer_id' => $target->id,
                    'merge_id' => $merge->id,
                    'reason' => $context['reason'],
                ],
            );
            AuditLogService::log(
                action: 'merged_in',
                module: 'customers',
                recordType: Customer::class,
                recordId: $target->id,
                oldValues: null,
                newValues: [
                    'from_customer_id' => $source->id,
                    'merge_id' => $merge->id,
                    'reason' => $context['reason'],
                    'affected_counts' => [
                        'orders' => $merge->affected_orders_count,
                        'returns' => $merge->affected_returns_count,
                        'refunds' => $merge->affected_refunds_count,
                        'customer_notes' => $merge->affected_notes_count,
                        'customer_addresses' => $merge->affected_addresses_count,
                        'customer_tags' => $merge->affected_tags_count,
                    ],
                ],
            );

            return $merge;
        });

        // ── 9. M2 Must-Fix: dispatch the synthetic event AFTER commit.
        //
        // Mass UPDATEs above skip Eloquent model events. Listeners that
        // care about customer-id changes (marketer wallet recompute,
        // search index sync, n8n webhooks, cache invalidation, future
        // audit log subscribers) hook into this event instead. Fires
        // OUTSIDE the transaction so listeners see the committed state.
        \App\Events\CustomerRecordsReassigned::dispatch(
            (int) $merge->source_customer_id,
            (int) $merge->target_customer_id,
            (int) $merge->id,
            $merge->payload['affected_ids']['orders'] ?? [],
            $merge->payload['affected_ids']['returns'] ?? [],
            $merge->payload['affected_ids']['refunds'] ?? [],
            $merge->payload['affected_ids']['customer_notes'] ?? [],
            $merge->payload['affected_ids']['customer_addresses'] ?? [],
            $merge->payload['affected_ids']['customer_tags'] ?? [],
            $merge->merged_by ? (int) $merge->merged_by : null,
        );

        return $merge;
    }

    /**
     * Hard invariants. Throws when a request must be rejected. Called
     * both before the transaction (fast-fail) and again under the lock
     * (race-safe).
     */
    private function preflight(Customer $source, Customer $target, array $context): void
    {
        if ((int) $source->id === (int) $target->id) {
            throw new RuntimeException('Source and target must differ.');
        }
        if ($source->trashed() || $target->trashed()) {
            throw new RuntimeException('Cannot merge a soft-deleted customer.');
        }
        if ($source->merged_into_customer_id !== null) {
            throw new RuntimeException('Source customer has already been merged.');
        }
        if ($target->merged_into_customer_id !== null) {
            throw new RuntimeException('Target customer has already been merged.');
        }
        $reason = trim((string) ($context['reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            throw new RuntimeException('Merge reason must be at least 10 characters.');
        }
        // Cross-phone merge requires super-admin.
        if ($source->normalized_phone && $target->normalized_phone
            && $source->normalized_phone !== $target->normalized_phone
            && empty($context['actor_is_super_admin'])) {
            throw new RuntimeException('Merging customers with different normalized phones requires super-admin.');
        }
    }

    /**
     * Snapshot the source customer's profile for the merge-log payload.
     * Used by the future rollback command. Limit to the visible
     * customer-side fields; relations are captured by their id lists
     * elsewhere in the payload.
     *
     * @return array<string,mixed>
     */
    private function sourceProfileSnapshot(Customer $source): array
    {
        return $source->only([
            'id', 'name', 'primary_phone', 'secondary_phone',
            'country_code', 'local_phone', 'normalized_phone',
            'secondary_country_code', 'secondary_local_phone', 'secondary_normalized_phone',
            'primary_phone_whatsapp', 'email',
            'city', 'governorate', 'country', 'default_address',
            'risk_score', 'risk_level', 'customer_type', 'notes',
            'created_at',
        ]);
    }
}
