# Performance Documentation — Index

> **Project:** HBS_ERP (Hnawbas Operations System)
> **Folder:** `docs/performance/`
> **Status:** Phase 0 — Docs only. No application code, queries, indexes, or caching strategies have been changed by this phase.

This folder documents the performance roadmap for HBS_ERP after the Finance and Return Management updates landed (commits `ea3e6e5` and prior).

The goal: make `/orders/create`, Dashboard, and Finance Reports feel fast — **without** sacrificing the data-integrity guarantees that the Finance ledger, return lifecycle, and audit trail depend on.

---

## Current performance status

After a read-only audit (see [`PERFORMANCE_AUDIT_SUMMARY.md`](PERFORMANCE_AUDIT_SUMMARY.md)):

- **Indexes are comprehensive** — every hot column on `orders`, `returns`, `refunds`, `cashbox_transactions`, `marketer_payouts`, `finance_periods`, `expenses`, and `marketer_transactions` is already indexed (often with the right composite for the dominant query pattern).
- **All list endpoints paginate** — Orders Index (20/page), Returns Index (30/page), Refunds Index (30/page), Marketer Payouts Index (30/page), all Finance Reports (30 or 50/page), Cashbox Statement (50/page).
- **Eager-loading uses field selection** — `with('relation:id,name,...')` everywhere I checked. No accidental full-relation hydration.
- **No N+1 traps** found in controllers or services I read, with **two specific exceptions** that account for most observed slowness.

The audit identified **three** non-trivial bottlenecks. None require schema changes or new indexes. All three have safe, scoped fixes.

---

## Key principle: measure first, optimize safely

The rules every performance fix must follow:

1. **Identify a measured slow path** (a request that takes >X ms in production logs, or a clearly oversized payload visible in the Network tab) before writing optimization code.
2. **Do not denormalize the cashbox ledger.** "Balance is always computed from `cashbox_transactions`" is the single most important Finance invariant. Caching balances on the `cashboxes` table would break that.
3. **Do not hide required data only on the frontend.** Performance fixes for `Orders/Create` should reduce the page payload by NOT sending the full product catalogue — not by sending it and hiding it client-side.
4. **Do not break the cost/profit visibility gate** added in commit `ea3e6e5`. Non-`orders.view_profit` users must remain unable to receive cost/profit fields through any new endpoint introduced for performance.
5. **Do not add indexes blindly.** The audit found no clear missing indexes. Add only when a new query pattern + EXPLAIN plan + observed slow-query log justify it.
6. **Do not weaken pagination.** Replacing pagination with infinite scroll is a UX choice, not a performance fix — and it can amplify load if not carefully designed.
7. **Do not change order pricing formulas, finance ledger principles, or refund/return business rules** as a side-effect of performance work.

---

## Priority order

1. **`Orders/Create` payload reduction** (Phase 1) — single biggest user-facing slowdown. Replace the full active-product catalogue + full active-marketer list with a server-side search endpoint.
2. **`FinanceReportsService` cashbox-balance query optimization** (Phase 2) — replace the N+1 `Cashbox::active()->get()->sum($c->balance())` loop with one `GROUP BY` query.
3. **Dashboard query batching** (Phase 2) — combine sequential `Order::query()` aggregates where it doesn't hurt readability.
4. **Optional frontend UX improvements** (Phase 4) — loading skeletons on Dashboard, lazy-load Returns/Show refunds list, typeahead components.
5. **Index review** (Phase 3) — defer until production slow-query log identifies a missing index. **Not needed today.**

---

## Documents

| # | Document | Purpose |
|---|---|---|
| 1 | [PERFORMANCE_AUDIT_SUMMARY.md](PERFORMANCE_AUDIT_SUMMARY.md) | The audit findings: causes, severity, affected pages, and what is NOT the problem. |
| 2 | [PERFORMANCE_ROADMAP.md](PERFORMANCE_ROADMAP.md) | The four-phase plan with scope, expected impact, and ordering. |
| 3 | [PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md](PHASE_1_ORDERS_CREATE_PAYLOAD_REDUCTION.md) | Detailed implementation plan for the highest-priority phase. |
| 4 | [PHASE_2_QUERY_OPTIMIZATION.md](PHASE_2_QUERY_OPTIMIZATION.md) | Detailed plan for the Finance Reports + Dashboard query work. |
| 5 | [PHASE_3_INDEX_REVIEW.md](PHASE_3_INDEX_REVIEW.md) | Current index inventory + rules for when to add new ones. |
| 6 | [PHASE_4_FRONTEND_UX_OPTIMIZATION.md](PHASE_4_FRONTEND_UX_OPTIMIZATION.md) | Loading skeletons, typeahead, lazy panels, virtualization. |
| 7 | [PERFORMANCE_QA_CHECKLIST.md](PERFORMANCE_QA_CHECKLIST.md) | Manual QA scenarios for verifying each phase. |

---

## How to start a phase

1. Read the relevant `PHASE_N_*.md` file end-to-end.
2. Confirm preconditions (e.g., no other pending changes on `main`, latest commit known).
3. Implement strictly within the phase scope. Do not bundle phases.
4. Run `php artisan test`, `npm run build`, and the relevant QA checklist journey.
5. Commit with the suggested message in the phase document. Push only when explicitly told.

---

## Status board

| Phase | Status |
|---|---|
| Phase 0 — Docs | 🟡 In progress (this commit) |
| Phase 1 — `Orders/Create` payload reduction | ⬜ Not started |
| Phase 2 — Query optimization | ⬜ Not started |
| Phase 3 — Index review | ⬜ Deferred (no evidence of need) |
| Phase 4 — Frontend UX optimization | ⬜ Optional |

---

## Cross-references

- The companion audit report that produced these findings is summarized in [`PERFORMANCE_AUDIT_SUMMARY.md`](PERFORMANCE_AUDIT_SUMMARY.md).
- The Finance architecture this audit must not break lives in [`../finance/FINANCE_MODULE_FINAL_OVERVIEW.md`](../finance/FINANCE_MODULE_FINAL_OVERVIEW.md).
- The cost/profit visibility gate (must remain intact under Phase 1) is documented at commit `ea3e6e5` and tested by `tests/Feature/Orders/OrderProfitVisibilityTest.php`.
