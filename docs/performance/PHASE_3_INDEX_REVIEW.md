# Performance Phase 3 — Index Review

> **Status:** **Deferred.** The audit found no urgent missing indexes. This phase exists to document the current index inventory and the rule for when to act.
> **Recommendation:** Do not start Phase 3 work until production slow-query evidence supports a specific index.

---

## Current Position

The Phase 5A → 5G commits added every index the new query patterns require. The audit verified:

- Every hot column on every modern table is indexed.
- Composite indexes match the dominant query pattern (`(status, created_at)`, `(cashbox_id, occurred_at)`, `(source_type, source_id)`, `(start_date, end_date)`).
- Foreign keys are indexed (Laravel's `foreignId()` adds the index automatically).
- High-cardinality string columns used in `WHERE name LIKE` are NOT indexed — this is correct; an index on `LIKE '%term%'` is unused anyway.

**No urgent index work is needed.** Adding indexes blindly slows writes without helping reads.

---

## Avoid adding indexes blindly

Costs of an unjustified index:

1. **Slower inserts and updates.** Every index adds to the per-write overhead. `cashbox_transactions` is a write-heavy table (every collection / expense / refund / payout / transfer writes at least one row). Doubling its index count would meaningfully slow `OrderService::changeStatus`, `RefundService::pay`, etc.
2. **Slower bulk operations.** Backups, migrations, and reindex jobs scale with index count.
3. **More disk + RAM.** Each index has its own B-tree.
4. **False confidence.** Adding an index "in case it helps" without measuring can hide a deeper query-plan problem.

---

## Candidate Areas To Monitor

The list below documents the existing index coverage. **No action required today**; this is a reference for future engineers wondering "is column X indexed?"

| Table | Columns | Existing / Recommended | Notes |
|---|---|---|---|
| `orders` | `status` | ✓ Existing (single + composite with `created_at`) | Primary lookup pattern |
| `orders` | `created_at` | ✓ Existing (composite with `status`, `shipping_status`) | Date-range filters |
| `orders` | `marketer_id` | ✓ Existing (single + composite with `status`) | Marketer scope |
| `orders` | `customer_id` | ✓ Existing (FK auto-index) | Customer lookup |
| `orders` | `customer_phone` | ✓ Existing (single) | Search filter |
| `orders` | `shipping_status` | ✓ Existing (composite with `created_at`) | Shipping dashboards |
| `orders` | `customer_risk_level` | ✓ Existing (single) | Risk filter |
| `returns` | `order_id` | ✓ Existing (composite with `return_status`, `ret_order_status_idx`) | Order → return lookup |
| `returns` | `return_status` | ✓ Existing (single + composite) | Lifecycle filter |
| `refunds` | `status` | ✓ Existing (single + composite with `collection_id`, `refunds_collection_status_idx`) | Over-refund guard reads |
| `refunds` | `order_return_id` | ✓ Existing (`refunds_order_return_idx`) | Phase 5C return-linked refund lookup |
| `refunds` | `order_id` | ✓ Existing (`refunds_order_idx`) | Phase 5D reversal lookup |
| `refunds` | `collection_id` | ✓ Existing (`refunds_collection_idx`) | Phase 5A over-refund guard |
| `refunds` | `customer_id`, `approved_at`, `rejected_at`, `paid_at` | ✓ Existing | Reports filters |
| `cashbox_transactions` | `cashbox_id` | ✓ Existing (composite with `occurred_at`, `cashbox_tx_cashbox_occurred_idx`) | Statement + balance queries |
| `cashbox_transactions` | `source_type` | ✓ Existing (composite with `source_id`, `cashbox_tx_source_idx`) | Reports + cash-flow grouping |
| `cashbox_transactions` | `occurred_at` | ✓ Existing (single + composite) | Date-range reports + Phase 5F guard reads |
| `cashbox_transactions` | `transfer_id` | ✓ Existing (`cashbox_tx_transfer_idx`) | Transfer pair lookup |
| `marketer_payouts` | `status` | ✓ Existing (single + composite with `marketer_id`) | Lifecycle filter |
| `marketer_payouts` | `paid_at` | ✓ Existing (single) | Reports + audit |
| `finance_periods` | `status` | ✓ Existing (single) | Open/closed filter |
| `finance_periods` | `start_date`, `end_date` | ✓ Existing (single each + composite `fp_range_idx`) | Closed-period guard `WHERE start <= ? AND end >= ?` |
| `expenses` | `expense_date` | ✓ Existing (single + composite with `expense_category_id`, `exp_date_cat_idx`) | Reports + Phase 5F guard |
| `marketer_transactions` | `(marketer_id, transaction_type)`, `(marketer_id, status)`, `(source_type, source_id)` | ✓ Existing (Phase 5D added the source composite) | Wallet recompute + Phase 5D reversal idempotency |

If a column you're filtering on isn't in this table, check the relevant migration file directly — most "hidden" indexes come from Laravel's automatic `foreignId().constrained()` chain.

---

## The Rule

Add a new index only when **all four** of these are true:

1. **A new query pattern is introduced** by a feature commit (typically a new `WHERE`, `ORDER BY`, or `GROUP BY` on a column that wasn't filtered/sorted before).
2. **Production slow-query log identifies** a slow query — measured, not guessed. The slow-query threshold should be agreed up-front (e.g., >100 ms on the production hardware).
3. **`EXPLAIN` shows** a table scan or full-index scan that a new index would convert to a range/equality lookup.
4. **The write-side overhead is acceptable** — check the migration's write-heavy tables (`cashbox_transactions` is the highest-write table; adding indexes there has the most cost).

Skip any of the four → don't add the index.

---

## What Phase 3 work would look like

If the slow-query log later identifies a need:

1. Open a new migration `database/migrations/YYYY_MM_DD_HHMMSS_add_X_index_to_Y_table.php`.
2. Use `$table->index([...], 'descriptive_name_idx')` with an explicit name (matches the project convention).
3. Add a test asserting the index name exists (helps catch accidental removals in future migrations).
4. Run `php artisan migrate` against a staging copy of production data, then `EXPLAIN` the target query to confirm the new index is used.
5. Commit with a message like `"Add (status, paid_at) composite to refunds for date-range reports"` — be specific about WHY.

Do not bundle multiple unrelated indexes into one commit.

---

## Constraints (do not break)

- No destructive schema changes.
- No `migrate:fresh`.
- No dropping of existing indexes without evidence they're unused.
- No re-ordering of existing composite index columns — that's effectively a different index.
- No `unique` constraints added retroactively on tables with existing duplicates (the audit found no such case but the rule is general).
