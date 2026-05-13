# Phase 0 — Risk, Controls, and Audit

> **Companion to:** [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
> **Purpose:** The risks the finance roadmap must defend against, the controls in place to defend against them, and the audit obligations per phase.
>
> ⚠️ Some "(Phase N)" references below predate the as-shipped phase numbering. R-11 in particular landed at Phase 5F as `FinancePeriodService` (not Phase 8 as `FiscalYearGuard`). See [`FINANCE_MODULE_FINAL_OVERVIEW.md`](FINANCE_MODULE_FINAL_OVERVIEW.md) for the as-shipped control mapping.

This document is the single source of truth for "what could go wrong" and "what stops it." Every PR in the finance roadmap should reference one or more rows from §1 in its description.

---

## 1. Risk register

| # | Risk | Likelihood | Impact | Control(s) |
|---|---|---|---|---|
| R-01 | **Double-counting revenue** — orders revenue used raw, ignoring refunds and cancellations | Medium | High | Profit / revenue reports must subtract paid refunds in-period (Phase 7 reports). Cancelled orders already excluded via `whereNotIn('status', ['Cancelled'])`. |
| R-02 | **Treating courier COD as collected before settlement** | High (today's behaviour) | High | `collection_status = 'Pending Settlement'` is mandatory for courier-COD orders. Cashbox transaction only on settlement reconciliation (Phase 3). |
| R-03 | **Refunding before approval** | Medium | High | Refund lifecycle requires `status = 'Paid'` for any cashbox transaction. `Requested` and `Approved` write nothing to cashboxes. Permission separation: `refunds.create` ≠ `refunds.approve` ≠ `refunds.pay`. |
| R-04 | **Refunding more than collected** | Medium | High | Server-side hard rule: `SUM(refunds.amount WHERE order_id=X AND status IN ('Approved','Paid')) ≤ collections.amount_collected`. Enforced in `RefundService`. |
| R-05 | **Deleting financial history** | Low (no UI for it) | Catastrophic | No hard delete on `cashboxes`, `cashbox_transactions`, `cashbox_transfers`, `refunds`, `payment_methods`. No soft delete on cashbox transactions (append-only). Reversal-by-transaction is the only correction path. |
| R-06 | **Marketplace wallet mismatch** | High (manual area) | Medium | Marketplace cashbox balance reconciled to marketplace dashboard by hand. Drift corrected via adjustment transaction with mandatory `notes`. No automated marketplace API integration (out of scope). |
| R-07 | **Expenses without a cashbox** | Medium (legacy) | Low–Medium | New expenses require `cashbox_id` (Phase 4 service guard). Legacy null-cashbox rows are tolerated and flagged in reports for cleanup. |
| R-08 | **Marketer profit not reversed after return** | Confirmed today | Medium | `MarketerWalletService::syncFromOrder()` extended to handle `Returned` → writes `Cancelled Profit` row (Phase 6). Audited. |
| R-09 | **User permission leaks** | Medium | High | Every new finance prop is server-gated. Phase 1 dashboard pattern (see existing `permissions.orders_view` gating) extended to every finance metric. Frontend hiding is belt-and-braces only. |
| R-10 | **Manual adjustment abuse** | Low | High | `cashbox_transactions.create` is its own permission. `source_type='adjustment'` requires `notes`. Every adjustment writes audit log. UI surfaces adjustments distinctly in statements. |
| R-11 | **Editing closed finance periods** | Low | High | **As shipped:** `FinancePeriodService::assertDateIsOpen()` (Phase 5F) refuses any financial write with `occurred_at` inside a closed `finance_periods` row. Applied to all 6 cash-impacting services. Tested. The older `fiscal_years` table still tracks annual scope but is not the table the guard checks against. |
| R-19 | **Refund-then-return double-reversal of marketer profit** | Medium | Medium | **As shipped (Phase 5D):** `MarketerProfitReversalService` skips reversal when (a) the refund is linked to an `order_return_id`, or (b) the order's status is already `Returned`/`Cancelled`. Prevents stacking the proportional refund reversal on top of the `syncFromOrder` order-status-driven reversal. Tested in `MarketerPayoutTest`. |
| R-12 | **Price override after refund** | Medium (sales pressure) | High | `OrderService` override path checks for `refunds.status='Paid'` and rejects (Phase 9). Tested. |
| R-13 | **Cross-currency contamination** | Low (single currency today) | Medium | Service-layer guard: cashbox transaction's source must match cashbox's `currency_code`. Cross-currency transfers explicitly rejected. |
| R-14 | **Partial state on failure** | Medium | High | Every multi-row financial write is wrapped in `DB::transaction()`. Tested in service-layer specs. |
| R-15 | **Silent failures** | Medium | Medium | No swallowed exceptions in finance code paths. Every refusal surfaces a flash error or validation error. Tested. |
| R-16 | **Stale denormalized balances** | Eliminated by design | High (if introduced) | No `current_balance` column on `cashboxes`. Balance always computed from transactions. |
| R-17 | **Adjustment racing settlement** | Low | Medium | Same-cashbox writes are serialised via DB transaction + advisory lock if contention emerges. Initial implementation relies on row-level locks during DB::transaction. |
| R-18 | **Refund issued, then order price overridden** | Medium | High | Phase 9 blocks override when any `refunds.status='Paid'` exists for the order. R-12 above. |
| R-19 | **Marketer payout to wrong cashbox** | Low | Medium | UI requires explicit cashbox + payment method selection (Phase 6). No default. Audit log captures both. |
| R-20 | **Reports drift from raw data** | Medium | Medium | Reports query the source tables directly (no separately stored aggregates). Net cash movement filters `source_type NOT IN ('transfer','opening_balance')` consistently. |

---

## 2. Control summary

The controls below are referenced by row IDs in §1. Each control may defend against multiple risks.

| Control | Defends |
|---|---|
| **Append-only cashbox transactions** | R-05, R-10, R-16, R-17 |
| **No hard delete on finance tables** | R-05 |
| **Deactivate instead of delete** | R-05 |
| **Reversal transaction (instead of edit)** | R-05, R-10, R-15 |
| **Audit log on every money mutation** | R-03, R-05, R-08, R-10, R-11, R-15, R-19 |
| **Permission separation (request / approve / pay)** | R-03, R-09 |
| **Approval workflow for refunds** | R-03, R-04 |
| **Approval workflow for large expenses (future)** | R-07 |
| **Closed fiscal period lock** | R-11 |
| **Over-refund validation** | R-04, R-18 |
| **Cashbox statement and reconciliation UI** | R-06, R-10, R-17 |
| **No silent balance edits** | R-10, R-15, R-16 |
| **Server-side permission gating on every prop** | R-09 |
| **Service-layer cross-currency guard** | R-13 |
| **DB::transaction wrapping** | R-14 |
| **Source_type filtering in reports** | R-20 |

---

## 3. Audit requirements per phase

Each phase must:
- Identify every state mutation it introduces.
- Call `AuditLogService::log()` from the service layer, **not** the controller — services are the canonical site for "what happened."
- Populate `old_values_json` and `new_values_json` for state transitions where the before/after matters (refund status changes, cashbox edits, override events).

### Per-phase audit obligations

| Phase | Audit events introduced |
|---|---|
| **1 — Cashboxes Foundation** | `cashbox.created`, `cashbox.updated`, `cashbox.deactivated`, `cashbox.activated`, `cashbox_transaction.created` |
| **2 — Payment Methods + Transfers** | `payment_method.created`, `payment_method.updated`, `payment_method.deactivated`, `cashbox_transfer.created` (transfers also produce two `cashbox_transaction.created` events) |
| **3 — Collections Integration** | `collection.cashbox_assigned`, `collection.settlement_reconciled`. Existing `collection.updated` continues. |
| **4 — Expenses Integration** | `expense.cashbox_assigned`. Existing `expense.created/updated/deleted` continue (the expense events that already exist now also imply cashbox transaction events). |
| **5 — Refunds** | `refund.requested`, `refund.approved`, `refund.paid`, `refund.rejected`. The `refund.paid` event causes one `cashbox_transaction.created`. |
| **6 — Marketer Payouts + Reversal** | `marketer_profit.reversed` (on Return), `marketer.payout` (extended event with cashbox + payment_method fields). |
| **7 — Finance Reports + Dashboard** | None (read-only). |
| **8 — Fiscal Controls** | `finance.fiscal_lock_blocked` (records every blocked attempt — this is *also* a security signal). |
| **9 — Order Price Override** | `order_item.price_overridden` with old_unit_price + new_unit_price in JSON. |

---

## 4. Rollback philosophy

| Question | Answer |
|---|---|
| **Can a deployed financial migration be rolled back?** | Forward-only in practice. The `down()` methods exist for emergency use only. In production, prefer a forward fix: add a corrective migration rather than rolling back. |
| **Can a financial code change be reverted?** | Yes — code reverts are safe as long as the underlying tables remain. Roll-forward beats roll-back for state-bearing modules. |
| **Can data be unwound?** | Only by writing reversal transactions. Never by `DELETE`. This is the same rule as in real-world accounting. |
| **Can a fiscal year be re-opened?** | Yes, by an admin action that writes an explicit audit event. Re-opening is rare and reviewed. |
| **Does emergency rollback exist?** | If the DB column / table can be left in place without breaking the previous code version, yes. Migrations are designed so that the table can exist while old code ignores it. |

---

## 5. Daily closing (future control)

Not in the initial roadmap, but identified here so the design does not preclude it.

A future phase may introduce **daily closing**: a daily snapshot of each cashbox's balance, optionally with a "closed by" user. After daily close, transactions whose `occurred_at` is before that closing date can no longer be inserted into that cashbox (the fiscal-year lock pattern, scaled down to one day).

Daily closing is **not** required to ship Phases 1–9. It can be added as a Phase 10 or operational tool.

---

## 6. Reconciliation checklist (operational)

The accountant (or admin) is expected to run this checklist on a regular cadence — initially weekly, later daily once daily closing exists.

1. Every "Pending Settlement" collection older than 7 days has a follow-up note or has been reconciled.
2. Every "Approved" refund older than 7 days has been Paid or has a documented hold reason.
3. For each marketplace cashbox: the computed balance matches the marketplace dashboard. Any drift is corrected with an adjustment transaction *and* a `notes` field explaining the cause.
4. The bank account cashbox balance matches the bank statement at the chosen reconciliation date.
5. Every `source_type='adjustment'` transaction in the reconciliation period has a non-empty `notes` field.
6. No cashbox has fallen below zero unless `allow_negative_balance = true` for that cashbox.
7. The "Net cash movement" report total for the period matches the sum of period transactions on each cashbox statement (transitive sanity check).

The dashboard Finance Snapshot band (Phase 7) surfaces #1, #2, #6, #7 directly so the accountant does not need to query manually.

---

## Cross-references

- Module principles → [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
- Rules these controls enforce → [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
- Permission slugs (split for separation of duties) → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Per-phase commit and test gates → [PHASE_0_IMPLEMENTATION_SEQUENCE.md](PHASE_0_IMPLEMENTATION_SEQUENCE.md)
