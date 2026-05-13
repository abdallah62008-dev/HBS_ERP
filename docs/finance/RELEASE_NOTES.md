# Finance Module — Release Notes

> Authoritative commit-by-commit record of the Finance module from Phase 0 (docs) through Phase 5G (this document).
>
> Every commit listed below is on `main` and pushed to `origin/main`. See [`FINANCE_MODULE_FINAL_OVERVIEW.md`](FINANCE_MODULE_FINAL_OVERVIEW.md) for the architectural picture.

---

## Phase 0 — Finance Architecture Docs

**Commit:** initial docs/finance/ folder

Created the seven planning documents that defined the Hybrid Lightweight ERP Finance approach:

- `PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md`
- `PHASE_0_FINANCE_ROADMAP.md`
- `PHASE_0_FINANCIAL_BUSINESS_RULES.md`
- `PHASE_0_DATABASE_DESIGN_DRAFT.md`
- `PHASE_0_PERMISSIONS_AND_ROLES.md`
- `PHASE_0_RISK_CONTROLS_AND_AUDIT.md`
- `PHASE_0_IMPLEMENTATION_SEQUENCE.md`
- `README.md`

Established the nine core principles (no hard delete, append-only ledger, balance computed not stored, audit every mutation, etc.). No code changes.

---

## Phase 1 — Cashboxes Foundation

Established the cashbox ledger as the single source of truth for money movement.

**Tables added:**
- `cashboxes` — named places where money lives (cash, bank, digital wallet, marketplace, courier_cod). Currency and opening balance immutable after first transaction.
- `cashbox_transactions` — signed-amount append-only ledger. Source-typed (`opening_balance`, `adjustment`).

**Service added:** `CashboxService` with `createCashbox`, `updateCashbox`, `deactivateCashbox`, `reactivateCashbox`, `createOpeningBalanceTransaction`, `createAdjustmentTransaction`.

**UI:** Cashboxes Index / Create / Edit / Statement pages.

**Permissions:** `cashboxes.view/create/edit/deactivate`, `cashbox_transactions.view/create`.

**Audit:** Every mutation writes an `audit_logs` row via `AuditLogService`.

---

## Phase 2 — Payment Methods + Cashbox Transfers

**Commit:** `819223a Add payment methods and cashbox transfers`

**Tables added:**
- `payment_methods` — seeded with 7 standard methods (cash, instapay, vodafone_cash, etc.).
- `cashbox_transfers` — pairs of cashbox_transactions linked by `transfer_id`, source_type `transfer`.

**Service added:** `CashboxTransferService` with same-currency invariant, both-cashboxes-active guard, source-has-sufficient-balance guard, and dual `lockForUpdate` (ordered by id to prevent deadlocks).

**UI:** Payment Methods + Cashbox Transfers Index/Create pages.

**Permissions:** `payment_methods.*`, `cashbox_transfers.*`.

---

## Phase 3 — Collections Integration

**Commit:** `0a93d77 Integrate collections with cashboxes`

Added cashbox linkage to collections. When a collection moves into a "money in our hands" status (Collected / Partially Collected / Settlement Received), it can be posted to a cashbox, which writes a cashbox IN transaction with `source_type='collection'`.

**Columns added** to `collections`: `cashbox_id`, `payment_method_id`, `cashbox_transaction_id`, `cashbox_posted_at`.

**Service added:** `CollectionCashboxService::postCollectionToCashbox` with `lockForUpdate`, double-post prevention, status eligibility, currency consistency.

**UI:** Collection detail page surfaces the posting form + linked cashbox transaction.

**Permissions:** `collections.assign_cashbox`, `collections.reconcile_settlement`.

---

## Phase 4 — Expenses Integration

**Commit:** `c0c8f20 Integrate expenses with cashboxes`

Expenses can now be paid from a specific cashbox, writing a cashbox OUT transaction.

**Columns added** to `expenses`: `cashbox_id`, `payment_method_id`, `cashbox_transaction_id`, `cashbox_posted_at`.

**Service added:** `ExpenseCashboxService::postExpenseToCashbox` with double-post prevention, currency consistency, sufficient-balance guard when `allow_negative_balance=false`.

**Model guard:** `Expense::deleting` hook blocks delete of a posted expense — defence-in-depth even if the controller is bypassed.

**Permissions:** `expenses.assign_cashbox`, `expenses.post_to_cashbox`.

---

## Phase 4.5 — Cashbox Hardening & Immutability

**Commit:** `b303a06 Harden cashbox guards and lock posting transactions`

Defence-in-depth pass after a race-condition audit found three concurrency gaps:

- **`CashboxTransaction` model `booted` hook**: throws on UPDATE or DELETE. Cashbox transactions are now append-only at the model layer, not just by convention.
- **`Cashbox` model `booted` hook**: `currency_code` and `opening_balance` become immutable once any transaction exists.
- **`Expense` model `booted` hook**: posted expense delete throws.
- **`lockForUpdate` on posting flows** (Collection, Expense, Transfer) so two concurrent posts cannot both squeeze past a balance check.

**Test file:** `HardeningTest.php` (13 tests) proves each guard.

---

## Phase 5A — Refunds Foundation (paperwork only)

**Commit:** `e84f487 Add refunds foundation paperwork workflow`

Refunds become a first-class concept with their own lifecycle, but Phase 5A does NOT touch the cashbox.

**Tables added:** `refunds` with reserved nullable `paid_*` and cashbox columns (so Phases 5B + 5C land without further migration).

**Service added:** `RefundService::approve / reject` with `lockForUpdate`, status-check on the locked row, audit per transition.

**Lifecycle:** `requested → approved → rejected`. The `paid` enum value is reserved.

**Over-refund guard:** `assertRefundableAmount` — cumulative `requested + approved + paid` refunds for a given collection cannot exceed `amount_collected`. Rejected refunds are excluded.

**Permissions:** `refunds.view / create / approve / reject` (separation of duties).

---

## Phase 5B — Refund Payment + Cashbox OUT

**Commit:** `5467edf Add refund payment from cashbox`

The `paid` transition is now live.

**Service added:** `RefundService::pay()` — locks refund + cashbox, re-runs every guard, writes one cashbox OUT transaction (`source_type='refund'`, signed negative amount), stamps the refund with all `paid_*` and linkage columns.

**New cashbox source_type:** `refund`.

**Permission added:** `refunds.pay`.

**Test file:** `RefundTest.php` grew to 39 tests covering lifecycle, over-refund, double-pay protection, audit log, and immutability of paid refunds.

---

## Phase 5C — Returns Financial Handling

**Commit:** `bdc68b3 Link returns to refund requests`

Inspected returns can now request a refund.

**Service method added:** `RefundService::createFromReturn(OrderReturn $return, ...)` — locks the return, re-checks eligibility, creates a `requested` refund with `order_return_id` stamped.

**Over-return guard added:** `RefundService::assertReturnRefundableAmount` mirrors the collection-level guard. Both guards now run on direct `POST /refunds` and `PUT /refunds/{id}` as well as the new return-driven path.

**UI:** Returns/Show page exposes refund context (refundable remaining, active refund total, linked refunds list, inline Request Refund form).

**Permission reused:** `refunds.create` (no new slug added per scope).

**Test file:** `ReturnRefundTest.php` (18 tests).

---

## Phase 5D — Marketer Payouts / Profit Reversal

**Commit:** `73920c0 Add marketer payouts and refund profit reversal`

Two-layer phase: payout workflow + conservative refund-driven profit reversal.

### Layer A — Marketer Payout Foundation

**Table added:** `marketer_payouts` — workflow envelope for the `requested → approved → paid` lifecycle, with cashbox linkage when paid.

**Service added:** `MarketerPayoutService` with `requestPayout / approve / reject / pay`. The `pay()` method writes BOTH a `cashbox_transactions` row (`source_type='marketer_payout'`) AND a mirror `marketer_transactions(type='Payout', status='Paid')` row, so the existing `MarketerWalletService::recalculateWallet` keeps producing the right `total_paid` and `balance`.

**New cashbox source_type:** `marketer_payout`.

**Permissions:** `marketer_payouts.view/create/approve/reject/pay`.

**Legacy path note:** `MarketerWalletService::payout()` (instant Paid, no cashbox, no lifecycle) is preserved as-is — used by the marketer Wallet page's quick-pay modal. The new lifecycle path is the recommended way; legacy stays for backward compatibility.

### Layer B — Conservative Refund→Profit Reversal

**Service added:** `MarketerProfitReversalService::reverseFromPaidRefund(Refund)` — wired into `RefundService::pay()`.

**Migration added:** nullable `source_type`/`source_id` columns on `marketer_transactions` for traceability + idempotency.

**Skip conditions** (all required to fire a reversal):
- refund status = `paid`
- refund amount > 0
- refund has `order_id`
- **refund is NOT linked to an `order_return_id`** (return path will handle the cancellation via `syncFromOrder`)
- order has `marketer_id` and positive `marketer_profit` snapshot
- order has positive `total_amount`
- **order status is NOT already `Returned` or `Cancelled`** (otherwise `syncFromOrder` will zero the per-order row separately — double-reversal protection)
- no prior reversal row exists for this refund

**Formula:** `Δ = round(marketer_profit × (refund.amount / order.total_amount), 2)`, clamped to remaining un-reversed profit.

**Test file:** `MarketerPayoutTest.php` (28 tests including 3 double-reversal protection tests).

---

## Phase 5E — Finance Reports

**Commit:** `888087d Add finance reports backed by cashbox ledger`

Nine read-only reports backed by the cashbox ledger as the source of truth for cash movement.

**Reports added** (all gated by single new permission `finance_reports.view`):
1. **Overview** (`/finance/reports`) — summary cards (total balance, inflow, outflow, net, posted/paid totals per source type).
2. **Cashboxes** (`/finance/reports/cashboxes`) — per-box balance, inflow, outflow, last activity.
3. **Movements** (`/finance/reports/movements`) — paginated `cashbox_transactions` with date / cashbox / direction / source / payment-method filters.
4. **Collections** — posted/unposted COD with cashbox linkage.
5. **Expenses** — posted/unposted with cashbox linkage.
6. **Refunds** — full lifecycle + paid amounts.
7. **Marketer Payouts** — full lifecycle + paid amounts.
8. **Transfers** — inter-cashbox transfers.
9. **Cash Flow** — cashbox-domain inflow/outflow grouped by source type, with net excluding transfers.

**Side fix in same commit:** `CashboxesController::show` Statement filter dropdown migrated from `PHASE_1_SOURCE_TYPES` to `PHASE_5D_SOURCE_TYPES` so refund / expense / collection / transfer / marketer_payout are filterable.

**Coexistence:** The older operational `ReportsService::cashFlow` (orders/expenses/supplier_payments/marketer_transactions) remains alongside the new `FinanceReportsService::cashFlow` (cashbox_transactions). They answer different questions; see `FINANCE_MODULE_FINAL_OVERVIEW.md`.

**Test file:** `FinanceReportsTest.php` (17 tests).

---

## Phase 5F — Finance Controls / Period Close

**Commit:** `8379334 Add finance period close with cashbox posting guard`

Closed finance periods block financial writes whose `occurred_at` falls inside the range. Reports stay fully readable.

**Table added:** `finance_periods` (name, start_date, end_date, status, close/reopen audit fields). Independent of `fiscal_years` (annual scope).

**Service added:** `FinancePeriodService` with `assertDateIsOpen`, `isDateClosed`, `findClosedPeriodForDate`, `createPeriod`, `updatePeriod`, `closePeriod`, `reopenPeriod`. Overlap detection on create + update. `lockForUpdate` on close/reopen.

**Guard wired** into 7 cash-impacting write points across 6 services:
- `CashboxService::createOpeningBalanceTransaction` (today's date)
- `CashboxService::createAdjustmentTransaction` (request date or today)
- `CashboxTransferService::createTransfer`
- `CollectionCashboxService::postCollectionToCashbox`
- `ExpenseCashboxService::postExpenseToCashbox`
- `RefundService::pay`
- `MarketerPayoutService::pay`

**Permissions added:** `finance_periods.view/create/update/close/reopen`. Reopen is Admin-only.

**Model guards:** `FinancePeriod::booted` blocks all deletes. No DELETE route registered.

**Test file:** `FinancePeriodTest.php` (originally 23 tests, grew to 25 after Phase 5F.1).

**Known scope boundary:** `MarketerWalletService::payout()` legacy quick-pay path is NOT guarded (it writes only to `marketer_transactions`, not the cashbox ledger). The new `MarketerPayoutService::pay` path IS guarded.

---

## Phase 5F.1 — Cashbox UX Fix

**Commit:** `9137251 Surface cashbox guard errors as flash instead of 500`

Audit-driven follow-up. `CashboxesController::store` and `::storeTransaction` did not catch the new Phase 5F `RuntimeException`, so closed-period blocks on these two paths surfaced as a 500 page instead of a flash error. Added try/catch wrappers mirroring the pattern used by the other 5 finance controllers.

**Files:** `app/Http/Controllers/CashboxesController.php` + `tests/Feature/Finance/FinancePeriodTest.php` (+2 HTTP-layer tests).

---

## Phase 5G — Documentation + Manual QA

**Commit:** (this commit)

Documentation-only phase. No code changes.

**Updated:**
- `README.md` — refreshed status board and roadmap summary to reflect as-shipped phase numbering.
- `PHASE_0_*.md` files — added banner notes flagging that the planning-era documents diverged from the as-shipped implementation, with pointers to the new authoritative references.
- `PHASE_0_RISK_CONTROLS_AND_AUDIT.md` — updated R-11 to point at `FinancePeriodService` (Phase 5F) instead of the never-built `FiscalYearGuard` (Phase 8). Added R-19 for the Phase 5D double-reversal protection.
- `PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md` — core principle #8 updated to reference `FinancePeriodService` + `finance_periods` table (vs. fiscal_years).

**Added:**
- `RELEASE_NOTES.md` — this document.
- `FINANCE_MODULE_FINAL_OVERVIEW.md` — current-state architecture reference.
- `QA_CHECKLIST.md` — nine user-journey scenarios for manual QA before the next feature.

---

## Cumulative numbers (as of Phase 5G)

| Metric | Count |
|---|---|
| Tests in `tests/Feature/Finance/` | **209** (across 11 test files) |
| Total test suite (all of `tests/`) | **277** |
| Total test assertions | **1225** |
| Finance services | 10 |
| Finance controllers | 7 |
| Finance migrations | 10 |
| Cashbox source types | 7 (`opening_balance`, `adjustment`, `transfer`, `collection`, `expense`, `refund`, `marketer_payout`) |
| Finance permission slugs | 27 |
| Inertia finance pages | 30+ |

---

## Known limitations / deferred follow-ups

These items are tracked here so they aren't forgotten. None of them block production use of the Finance module today.

| Topic | Status | Notes |
|---|---|---|
| **Legacy `MarketerWalletService::payout()` quick-pay path** | Live, NOT guarded by closed period | Writes to `marketer_transactions` only; doesn't touch the cashbox ledger. Recommendation: either deprecate it in favour of `MarketerPayoutService::pay` or extend the guard to cover it. |
| **Finance reports exports (CSV / Excel)** | Not implemented | Phase 5E deliberately deferred. No `finance_reports.export` permission yet. Reasonable Phase 6 candidate. |
| **Supplier payments integration with cashbox** | Not implemented | `supplier_payments` table exists but isn't part of the cashbox ledger. `ReportsService::cashFlow` sums it but `FinanceReportsService::cashFlow` does not. Reasonable Phase 6 candidate. |
| **Bank reconciliation** | Not implemented | Would benefit cashboxes of `type='bank'`. Needs product input on bank statement format + matching strategy. |
| **Order Price Override** | Deferred | Originally planned as Phase 9. Touches all financial paths we just built; better as a Phase 7+ candidate after a full manual QA pass (Phase 5G QA_CHECKLIST.md). |
| **Cashbox Statement prop name** | Cosmetic | `phase1_source_types` Inertia prop now contains `PHASE_5D_SOURCE_TYPES`. Works correctly, just misnamed. Rename when convenient. |
| **DI consistency across finance services** | Cosmetic | `CashboxService` constructor-injects `?FinancePeriodService`; other 5 services resolve via `app()` inline. Both work identically. |
| **Source-type whitelist enforcement** | Defence-in-depth opportunity | `CashboxTransaction::PHASE_*_SOURCE_TYPES` constants are documentation; not enforced at insert. Could add a `creating` hook. |
| **Sidebar "Collections" duplication** | Cosmetic | Appears under both Order Operations and Finance Operations. Could be intentional dual-perspective; could be consolidated. |

---

## How to read the commit history

```
git log --oneline --reverse --grep="cashbox\|refund\|marketer\|finance" --since="2026-05-09"
```

…will reproduce the finance commit timeline. The 13 commits below build the entire module:

```
Phase 0   — (initial finance docs)
Phase 1   — (initial cashboxes commit)
Phase 2   — 819223a Add payment methods and cashbox transfers
Phase 3   — 0a93d77 Integrate collections with cashboxes
Phase 4   — c0c8f20 Integrate expenses with cashboxes
Phase 4.5 — b303a06 Harden cashbox guards and lock posting transactions
Phase 5A  — e84f487 Add refunds foundation paperwork workflow
Phase 5B  — 5467edf Add refund payment from cashbox
Phase 5C  — bdc68b3 Link returns to refund requests
Phase 5D  — 73920c0 Add marketer payouts and refund profit reversal
Phase 5E  — 888087d Add finance reports backed by cashbox ledger
Phase 5F  — 8379334 Add finance period close with cashbox posting guard
Phase 5F.1 — 9137251 Surface cashbox guard errors as flash instead of 500
Phase 5G  — (this commit) docs + QA
```
