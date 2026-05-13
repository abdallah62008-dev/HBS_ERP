# Performance Audit Summary

> **Audit date:** after commit `ea3e6e5 Hide order cost and profit from non-privileged users`.
> **Method:** static code inspection across controllers, services, models, migrations, and Inertia pages. No load testing, no profiling — this is the qualitative pass that decides what's worth instrumenting.
> **Outcome:** the codebase is structurally healthy. Three specific bottlenecks account for most observed slowness. None require schema changes or new indexes.

---

## Executive Summary

- **Codebase is structurally healthy.** No accidental N+1 traps in newly-added Phase 5A–5G code, no missing eager-loads in hot controllers, no over-fetched relations.
- **Most indexes are already present.** Every hot column on `orders`, `returns`, `refunds`, `cashbox_transactions`, `marketer_payouts`, `finance_periods`, `expenses`, and `marketer_transactions` is indexed — often with the right composite for the dominant query pattern.
- **Most list pages are paginated.** Orders Index (20/page), Returns Index (30/page), Refunds Index (30/page), Marketer Payouts Index (30/page), all Finance Reports (30 or 50/page), Cashbox Statement (50/page).
- **Main bottlenecks are payload size and a small number of aggregate query patterns.** Not query plans, not React rendering, not the Finance ledger architecture, not the Return Management flow.

---

## Most Likely Causes of Slowness

| Rank | Area | Cause | Severity | Confidence |
|---:|---|---|---|---|
| 1 | `/orders/create` page payload | Full active product catalogue (`productsForOrderEntry()`) shipped in one Inertia prop. For a 5,000-SKU catalogue ≈ 750 KB JSON parsed before page is interactive. | **HIGH** | HIGH |
| 2 | `/orders/create` marketers prop | Full active-marketer list shipped at page load even for users who can't see the marketer profit preview. Typically <200 marketers but unbounded. | Medium | HIGH |
| 3 | `FinanceReportsService::overview()` cashbox balance | `Cashbox::active()->get()->sum(fn ($c) => $c->balance())` — one separate `SUM(amount)` per active cashbox. Classic N+1. | Medium | HIGH |
| 4 | `FinanceReportsService::cashboxes()` per-row balance | Same pattern inside the `map()` call — one balance query per cashbox row in the summary table. | Medium | HIGH |
| 5 | Dashboard sequential aggregate queries | `DashboardMetricsService` runs 17+ separate `Order::query()` aggregations plus several `Shipment::query()` / `Ticket::query()` / `Collection::query()` / `FiscalYear::query()` calls. Each is small + indexed but round-trips compound. | Medium | Medium |
| 6 | `/orders/create` locations tree | `CustomersController::locationTree()` ships country/state/city tree. Size depends on tenant data; could be 100 KB+. Not yet measured. | Low-Medium | Medium |
| 7 | `marketer-profit-preview` debounced calls | Fires per keystroke after debounce; on slow networks the preview block re-renders heavily. Each call: resolver + profit calc per item. | Low | Medium |
| 8 | `Returns/Show` nested eager loads | After Phase 5C/5G, eager-loads `order.items`, `order.customer`, `returnReason`, `shippingCompany`, `inspectedBy`, `refunds.with(4 users)`. Fine for typical returns; degrades only for pathological cases. | Low | Low |

---

## Pages Most Likely Affected

| Page | Risk | Why |
|---|---:|---|
| `/orders/create` | **High** | Full product catalogue + full marketer list shipped at page load. Single biggest user-facing slowdown. |
| `/finance/reports` (Overview) | Medium | N+1 cashbox balance computation in the `total_balance` card. |
| `/finance/reports/cashboxes` | Medium | Same N+1, repeated per cashbox row in the summary table. |
| `/dashboard` | Medium | Many sequential aggregate queries. Each fast individually; total round-trips add up. |
| `/orders/{id}/edit` | Low-Medium | Item list + customer + the new return_reasons / return_conditions / has_return props. Not the bottleneck but the heaviest "single-record" page. |
| `/returns/{id}` | Low-Medium | Many nested eager-loads after Phase 5C/5G; typical case is fine, pathological cases slow. |
| `/orders` (Index) | Low | Properly paginated 20/page with eager customer. |
| `/refunds` | Low | Properly paginated 30/page. |
| `/marketer-payouts` | Low | Properly paginated 30/page. |
| All other Finance Reports | Low | Properly paginated (30 or 50/page); aggregates use single grouped queries. |
| `/cashboxes/{id}` (Statement) | Low | Paginated 50/page on an indexed composite (`cashbox_id`, `occurred_at`). Fine. |

---

## What Is NOT the Main Problem

Documented explicitly so future debugging starts in the right place:

- **Not primarily missing indexes.** Every hot column on every modern table is already indexed (often composite). Adding more indexes would slow writes without helping the symptoms.
- **Not primarily React rendering.** `Orders/Create.jsx` caps visible product rows at 25 via `useMemo`. `Orders/Index.jsx` paginates 20/page. `Returns/Show.jsx` and `FinanceReports/*` render compact summary cards + paginated tables. No detected client-side render bombs.
- **Not primarily the Finance reports.** Phase 5E reports use single `GROUP BY` aggregates, paginate row tables, and respect index hints. Only the cashbox-balance-total query in Overview/Cashboxes needs work.
- **Not caused by the Return Management flow** (Phase 5G + the order Edit flow extension). The new endpoints add one Inertia prop here and there but no heavy queries. The new `OrderStatusFlowService` wraps two existing services in one `DB::transaction`; no new aggregation work.
- **Not solvable by aggressive caching.** Caching cashbox balances on the `cashboxes` table would denormalize the ledger and break Phase 0's "balance is computed from transactions" invariant. Caching report results is acceptable only with a short TTL (≤60s) — but query optimization is cheaper and safer.
- **Not solvable by virtualization libraries.** Adding `react-virtualized` to a 20-row paginated table is overkill. Pagination already bounds DOM size.
- **Not caused by Inertia overhead.** Inertia's serialization is essentially `json_encode($model)`. The Phase 5G `sanitizeProfitFor` helper proves that fields-out-of-JSON works fine.

---

## Methodology notes

This audit was qualitative. It identifies code patterns that are **likely** slow, not measured slow. Before implementing Phase 1 or Phase 2 fixes, the recommendation is:

1. **Take one measurement.** Open `/orders/create` on a tenant with the production catalogue, look at the Network tab, and note the size of the Inertia JSON payload. If it's <100 KB, Phase 1 is lower priority than Phase 2.
2. **Identify the dominant call.** For Finance reports, watch the Laravel query log on `/finance/reports` and count how many `SELECT SUM(amount) ... WHERE cashbox_id=?` queries fire. If it's 1 (already aggregated) you've fixed it elsewhere; if it's N (one per cashbox), Phase 2 is the right next step.
3. **Don't pre-optimize Dashboard if it isn't slow.** 17 small indexed queries on a Dashboard that loads in 400 ms is not a problem.

The Phase 1 → Phase 2 → Phase 3 → Phase 4 order reflects expected impact under the assumption that catalogue-size payload is the worst offender. Confirm before starting work.

---

## Constraints any fix must respect

- Do not break the cost/profit visibility gate (commit `ea3e6e5`). Any new product-search endpoint must NOT return `cost_price`, `marketer_trade_price`, or similar fields.
- Do not denormalize the cashbox ledger. `balance()` always reads from `cashbox_transactions`.
- Do not mutate historical data while optimizing.
- Do not remove pagination.
- Do not weaken the Phase 5C over-refund guard or the Phase 5F closed-period guard by reading stale aggregates.
- Do not add indexes without slow-query evidence.

---

## Cross-references

- [`README.md`](README.md) — landing page for this documentation folder.
- [`PERFORMANCE_ROADMAP.md`](PERFORMANCE_ROADMAP.md) — the four-phase plan derived from these findings.
- [`PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md`](PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md) — implementation plan for the top-priority phase.
