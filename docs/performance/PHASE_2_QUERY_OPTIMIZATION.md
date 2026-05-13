# Performance Phase 2 — Query Optimization

> **Targets:** `FinanceReportsService::overview()`, `FinanceReportsService::cashboxes()`, and `DashboardMetricsService`.
> **Status:** Plan only. No code changes in this document.
> **Expected impact:** `/finance/reports` first render drops from O(N+1) queries to O(1) for the total-balance card. Dashboard cuts ~17 queries to ~5–8.

---

## FinanceReportsService Cashbox Balance Optimization

### Problem

Two call sites use a classic N+1 pattern:

**`FinanceReportsService::overview()` line ~53:**

```php
$totalBalance = (float) Cashbox::active()
    ->get()
    ->sum(fn (Cashbox $c) => $c->balance());
```

Each `$c->balance()` runs `SELECT SUM(amount) FROM cashbox_transactions WHERE cashbox_id = ?` — one query per active cashbox.

**`FinanceReportsService::cashboxes()` line ~129:**

```php
$rows = $cashboxes->map(function (Cashbox $c) use ($stats) {
    return [
        ...
        'balance' => $c->balance(),   // ← per-row balance query
    ];
});
```

Same pattern, repeated inside `map()`.

For a tenant with 10 active cashboxes that's 10 extra queries on every `/finance/reports` and `/finance/reports/cashboxes` page load. Each query is small + indexed (composite `(cashbox_id, occurred_at)` exists), but the round-trip count hurts.

### Proposed replacement

Replace each loop with a single grouped query:

```php
$balances = \App\Models\CashboxTransaction::query()
    ->selectRaw('cashbox_id, COALESCE(SUM(amount), 0) AS balance')
    ->groupBy('cashbox_id')
    ->pluck('balance', 'cashbox_id');

// Now look up by id in PHP:
$totalBalance = (float) Cashbox::active()->get()
    ->sum(fn (Cashbox $c) => (float) ($balances[$c->id] ?? 0));
```

This collapses N queries into 1. The composite index `(cashbox_id, occurred_at)` already covers the GROUP BY.

### Rules

- **Keep the ledger as source of truth.** The `Cashbox::balance()` model method stays — it's the canonical single-cashbox lookup and the right tool when callers handle only one cashbox at a time.
- **Do not cache balances on the `cashboxes` table.** Phase 0 of the Finance docs explicitly prohibits this. Drift risk is unacceptable.
- **Do not mutate historical transactions.** The query is read-only.
- **Add a docblock to `Cashbox::balance()`** explaining "do not call inside a loop over many cashboxes — use a single grouped query (see `FinanceReportsService::overview` for the pattern)."

### Test contract

- `FinanceReportsTest::test_overview_totals_match_ledger` already asserts the total. After the optimization the number must remain identical.
- `FinanceReportsTest::test_cashbox_summary_totals_match_cashbox_transactions` covers the per-row case.
- New test: assert that only ONE `SELECT SUM(amount)` query fires when the page loads (use Laravel's query log assertions).

---

## Dashboard Query Batching

### Problem

`DashboardMetricsService` runs ~17 separate `Order::query()` aggregations plus several `Shipment::query()` / `Ticket::query()` / `Collection::query()` / `FiscalYear::query()` calls. Each is small + indexed but the round-trips compound.

Examples worth merging:

- **Status counts**: today probably 4+ separate `WHERE status='X' COUNT(*)` calls. Replace with one `SELECT status, COUNT(*) FROM orders GROUP BY status` and look up by status in PHP.
- **Per-period inflow/outflow sums**: today potentially separate `SUM` calls for delivered / shipped / cancelled. Replace with one `selectRaw` returning multiple `SUM(CASE WHEN ... THEN amount ELSE 0 END)` columns.
- **Today vs. MTD counts**: two queries that differ only by date range can use one `selectRaw` with two `SUM(CASE WHEN created_at >= today THEN 1 ELSE 0 END)` columns.

### Possible improvements

- Merge tightly-related aggregates into single `selectRaw` queries.
- Keep loosely-related metrics in separate queries — readability matters.
- Preserve the existing Dashboard output shape (same prop names, same numbers) so no JSX changes are required.

### Rules

- **Don't refactor into a single giant query.** Hard to maintain, hard to test, marginal gain.
- **Don't change the public metric set.** No new metrics, no renamed keys.
- **Don't introduce caching with a TTL > 0 in this phase.** That's a separate decision.

### Test contract

- Existing `tests/Feature/DashboardTest.php` must continue to pass with no expected-value changes.
- For each merged-query group, add a test asserting the merged metric output matches the pre-merge per-query result on a fixed seed.

---

## Tests (overall)

After Phase 2:

- Finance report totals — identical to pre-Phase-2 numbers.
- Cashbox summary balances — match `cashbox_transactions` directly.
- Dashboard metrics — identical to pre-Phase-2 numbers.
- Query count on the optimized endpoints — measurably lower (add at least one query-count assertion per optimization).
- **No new migrations.** Phase 2 is pure read-side query refactoring.

---

## Risks

| Risk | Mitigation |
|---|---|
| Subtle arithmetic difference between `Cashbox::balance()` and the grouped query | Both sum `cashbox_transactions.amount`. Identical by construction. Test covers it. |
| Inactive cashboxes accidentally included in the grouped query | The grouped query returns balances for ALL cashbox_ids that have transactions. The caller still filters `Cashbox::active()` and looks up by id, so inactive cashboxes naturally excluded from the result. |
| Dashboard merged query loses a per-status edge case | Run the existing Dashboard tests; add per-metric value assertions on a fixed seed before refactoring. |
| Merging makes a query slower (table-scan instead of indexed COUNT) | `EXPLAIN` the merged query before committing; the `status` column on `orders` has both single and composite indexes. |
| Adding a query log assertion is flaky | Use `DB::enableQueryLog()` + `count(DB::getQueryLog())` in a controlled test environment. |

---

## Constraints (do not break)

- Cashbox ledger principles (read-only optimization).
- Audit log (unchanged).
- Phase 5F closed-period guard (unchanged — only affects writes).
- Phase 5C over-refund / over-return guards (unchanged — only affects refunds + returns, not Finance reports).
- Existing Dashboard prop set + values.
- No new permissions.
