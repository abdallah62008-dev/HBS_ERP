# Performance Roadmap

> Companion to [`PERFORMANCE_AUDIT_SUMMARY.md`](PERFORMANCE_AUDIT_SUMMARY.md).
> Phases are ordered by expected user-facing impact. Each phase is independently committable and reversible.

---

## Phase 0 — Documentation

This phase (current). Establishes the audit findings + four-phase plan **before** writing any optimization code so each subsequent commit is small, scoped, and reviewable.

**Status:** in progress (this commit).

---

## Phase 1 — Safe Payload Reduction

> **Main target:** `/orders/create`.

### Recommended scope

- Replace the full product catalogue prop (currently shipped from `OrdersController::productsForOrderEntry()`) with a **server-side search endpoint** that returns up to 25 results matching a query string.
- Move product search to the new endpoint via debounced fetch in `resources/js/Pages/Orders/Create.jsx`.
- Preserve the existing 10-field product result shape so the rest of the Create form works unchanged.
- Gate the `marketers` prop by `orders.view_profit` (or load lazily) — non-privileged users don't see the marketer profit preview, so they don't need the full marketer list at page load.
- Optionally lazy-load `locations` tree if production data confirms the tree is large.

### Out of scope for Phase 1

- Pagination changes on Orders Index (already paginated 20/page).
- Database index changes (none needed).
- Caching of any kind.
- Any change to order creation logic or order pricing formulas.

### Expected impact

- `/orders/create` Inertia JSON payload drops from ~750 KB to <20 KB for catalogues >1000 SKUs.
- Time-to-interactive on slow connections drops by 5–10×.
- Browser JSON parsing cost drops proportionally.
- Better UX for tenants with large catalogues.

### Detailed plan

See [`PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md`](PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md).

---

## Phase 2 — Query Optimization

> **Main targets:** `FinanceReportsService::overview()`, `FinanceReportsService::cashboxes()`, and `DashboardMetricsService`.

### Recommended scope

- Replace the cashbox-balance N+1 loop with one grouped `SUM(amount) GROUP BY cashbox_id` query. Look up balances by id in PHP.
- Apply the same fix to the `Cashboxes` summary report which calls `$c->balance()` inside `map()`.
- Document `Cashbox::balance()` as "do not call inside a loop over many cashboxes — use a single grouped query upstream."
- Batch Dashboard aggregate queries where merging doesn't hurt readability: status-counts (`SELECT status, COUNT(*) FROM orders GROUP BY status`), per-period inflow/outflow sums.
- Preserve the existing Dashboard metric output exactly. No new metrics, no changed semantics.

### Out of scope for Phase 2

- Caching report results. Operators expect Finance numbers to be live.
- Caching Dashboard metrics with a TTL longer than 0 seconds. (Could be added later as a separate small commit if production telemetry shows benefit.)
- Denormalized balance storage on `cashboxes`. Phase 0 of the Finance docs prohibits this.

### Expected impact

- `/finance/reports` first render: from O(N+1) queries to O(1) for the total-balance card.
- `/finance/reports/cashboxes`: from O(N+1) queries to O(1) for the per-row balance.
- Dashboard: ~17 queries reduced to ~5–8 (the rest are unavoidable — they query different tables).

### Detailed plan

See [`PHASE_2_QUERY_OPTIMIZATION.md`](PHASE_2_QUERY_OPTIMIZATION.md).

---

## Phase 3 — Index Review

> **Status:** deferred. No evidence of missing indexes.

### Recommended scope

**Do not add indexes immediately.** The audit verified that every hot column referenced in this list is already indexed:

```
orders.status            (single + composite with created_at)
orders.created_at        (composite)
orders.marketer_id       (single + composite with status)
orders.customer_id       (FK)
returns.order_id         (composite with return_status)
returns.return_status    (single + composite)
refunds.order_return_id  (single, refunds_order_return_idx)
refunds.status           (single + composite with collection_id)
cashbox_transactions.cashbox_id     (composite with occurred_at)
cashbox_transactions.source_type    (composite with source_id)
cashbox_transactions.occurred_at    (single + composite)
marketer_payouts.status            (single + composite with marketer_id)
finance_periods.start_date         (single + composite)
finance_periods.end_date           (single + composite, fp_range_idx)
expenses.expense_date              (single + composite with category)
marketer_transactions.source_*     (Phase 5D composite)
```

### When to act

Add an index only when **all four** of these are true:

1. A new query pattern was introduced by a feature commit.
2. Production slow-query log identifies a query exceeding the agreed SLO.
3. `EXPLAIN` shows a table scan / full-index scan that a new index would convert to a range/equality lookup.
4. Insert / update overhead is acceptable for the new index (check the migration's write-heavy tables — `cashbox_transactions` is the highest-write table in the system).

### Detailed plan

See [`PHASE_3_INDEX_REVIEW.md`](PHASE_3_INDEX_REVIEW.md).

---

## Phase 4 — Frontend UX Optimization

> **Status:** optional. Visible improvements regardless of backend performance.

### Recommended scope

- Loading skeletons on Dashboard cards (perceived performance improves immediately).
- Typeahead dropdown component for product search (pairs with Phase 1 backend).
- Lazy-load optional panels: e.g. the refunds list on `Returns/Show` after first paint.
- Avoid huge native `<select>` dropdowns — replace marketer / customer pickers with searchable comboboxes if either list exceeds ~50 items.
- Defer virtualization unless a paginated table grows past ~200 rows on screen.
- Keep forms simple — no animations on every keystroke.
- Audit `useMemo` / `useCallback` dependencies once per page to ensure they aren't recreating arrays on every render.

### Out of scope for Phase 4

- Replacing pagination with infinite scroll on Orders Index / Refunds Index / etc. — pagination is the right pattern for the current scale.
- Service Worker / offline support.
- Bundle splitting beyond what Vite already provides.

### Detailed plan

See [`PHASE_4_FRONTEND_UX_OPTIMIZATION.md`](PHASE_4_FRONTEND_UX_OPTIMIZATION.md).

---

## Phase dependency graph

```
Phase 0 (this commit)
   │
   ▼
Phase 1  Payload reduction  ───────► Phase 4  Frontend UX (optional)
   │
   ▼
Phase 2  Query optimization
   │
   ▼
Phase 3  Index review  (deferred)
```

Phase 1 is the highest user-facing impact. Phase 2 is a backend-only quality fix that affects Finance/Dashboard. Phase 4 is independently shippable. Phase 3 should not be touched until production evidence appears.

---

## Status board

| Phase | Status | Notes |
|---|---|---|
| Phase 0 — Docs | 🟡 In progress | This commit |
| Phase 1 — `Orders/Create` payload | ⬜ Not started | Recommended next |
| Phase 2 — Query optimization | ⬜ Not started | Cashbox balance N+1 + Dashboard batching |
| Phase 3 — Index review | ⬜ Deferred | No evidence of need |
| Phase 4 — Frontend UX | ⬜ Optional | Skeletons, typeahead, lazy panels |
