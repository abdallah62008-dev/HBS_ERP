# Phase 0 — Financial Business Rules

> **Companion to:** [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
> **Purpose:** The concrete rules every code path in the finance modules must follow. If a rule below is violated, the system has a bug — even if the code compiles and tests pass.

These rules are normative. Implementation phases (1–9) inherit them. New rules added later should extend this document, not replace items in place.

---

## 1. Cashboxes

| Rule | Detail |
|---|---|
| **No hard delete** | `cashboxes` rows are never `DELETE`d. The model deliberately does **not** use soft-deletes — the only way to retire a cashbox is `is_active = false`. |
| **Deactivate only** | Setting `is_active = false` blocks new transactions but preserves every historical row and the full statement. |
| **Opening balance only at creation** | The `opening_balance` field is editable only while the cashbox has zero transactions. After the first non-opening transaction, the field becomes read-only. |
| **Opening balance creates a transaction** | Setting `opening_balance = X` writes one `cashbox_transactions` row with `source_type = 'opening_balance'`, `amount = X`. The cashbox row itself does not hold the running balance. |
| **Balance is calculated** | `balance = SUM(cashbox_transactions.amount WHERE cashbox_id = ?)`. Always. The cashbox table has **no `current_balance` column**. |
| **`allow_negative_balance` is per cashbox** | A boolean on the cashbox row. When false, transactions that would push the balance below zero raise a warning (default: warn but allow) or are blocked (admin policy). Default: `true` — operationally, cashboxes are sometimes momentarily negative during reconciliation. |
| **Currency is fixed at creation** | `currency_code` is set from settings at creation and cannot be edited. Transactions whose source domain object has a different currency must be rejected. |
| **Active / inactive behaviour** | An inactive cashbox is read-only: no new transactions, no transfers in or out, no payouts from it. It remains visible in lists (with a clear inactive indicator) and exportable. |

---

## 2. Cashbox transactions

| Rule | Detail |
|---|---|
| **Append-only** | Once inserted, a `cashbox_transactions` row is never updated, never deleted. |
| **No delete button** | The UI exposes no delete action. The endpoint, if added, must throw. |
| **Correction by reversal** | A mistaken transaction is corrected by inserting a new transaction with the opposite sign and `source_type = 'adjustment'`, plus a `notes` field that references the original transaction id ("Reverses #1234: typo in expense amount"). |
| **Every transaction has `source_type` and `created_by`** | No anonymous adjustments. The full set of allowed `source_type` values: `opening_balance`, `collection`, `expense`, `refund`, `transfer`, `marketer_payout`, `adjustment`. Each value implies a `source_id` (except `adjustment`, where source_id may be null). |
| **`occurred_at` vs `created_at`** | `occurred_at` is when the money actually moved (operator-supplied, may be backdated within rules). `created_at` is when the row was inserted (system-supplied, immutable). Reports use `occurred_at`. The fiscal-period lock (Phase 8) checks `occurred_at`. |
| **No silent edits** | Every insert writes an `audit_logs` row. |

---

## 3. Collections

| Rule | Detail |
|---|---|
| **Direct payment writes the cashbox** | When an order is delivered with payment method = Cash, Visa, Vodafone Cash, Amazon Wallet, etc. (anything that is not Courier COD), a cashbox transaction is written immediately and `collection_status = 'Collected'`. |
| **Courier COD is *not* collected until settlement** | Delivering a courier-COD order moves the collection to `collection_status = 'Pending Settlement'`. No cashbox transaction is written yet. |
| **Settlement reconciliation writes the cashbox** | When the courier remits, the "Reconcile courier settlement" action selects 1+ collections + a target cashbox + settlement reference. Per collection, one cashbox transaction is written (source_type='collection') and the collection moves to `Settlement Received`. |
| **Partial collection** | `collection_status = 'Partially Collected'` is supported. The cashbox transaction reflects `amount_collected`, not `amount_due`. The gap (`amount_due − amount_collected`) is a candidate for a write-off (manual adjustment by accountant). |
| **Cannot double-post** | A collection that has already produced a cashbox transaction cannot produce a second one. Service-layer guard checks for existing transactions of `source_type='collection'`, `source_id=collection.id`. |

---

## 4. Marketplace wallets

| Rule | Detail |
|---|---|
| **Marketplaces are cashboxes** | Amazon Wallet, Noon Wallet, etc. are created as cashboxes of `type = 'marketplace'`. They behave like any other cashbox. |
| **Marketplace sale credits the wallet** | When a marketplace order is `Delivered`, the matching collection writes a cashbox `in` transaction on the marketplace cashbox. This represents "the marketplace owes us this much money." |
| **Marketplace fees are out-transactions** | Marketplace platform fees are recorded as `expenses` (with a marketplace fee category, paid from the marketplace cashbox) OR as direct cashbox adjustments. The expense path is preferred for reporting clarity. |
| **Marketplace payout to bank is a transfer** | When the marketplace remits to the bank account, a `cashbox_transfer` records the move (negative on Amazon Wallet, positive on Bank Account). Both transactions share a `transfer_id`. |
| **Reconciliation is manual** | The marketplace cashbox balance vs the marketplace dashboard balance is reconciled by hand. Any drift is corrected with an adjustment transaction. There is no automated marketplace API integration in this roadmap. |

---

## 5. Expenses

| Rule | Detail |
|---|---|
| **New expenses must use a cashbox** | After Phase 4, every new expense requires `cashbox_id` and `payment_method_id`. Form validation enforces this. |
| **Historical expenses may remain null** | Expenses created before Phase 4 keep `cashbox_id = null`. Reports treat them as "untracked source." Backfilling is optional and done by an admin tool, not automatically. |
| **Expense creates an OUT transaction** | Saving a new expense writes a `cashbox_transactions` row of `amount = -expense.amount`, `source_type='expense'`, `source_id=expense.id`. |
| **Edits write correction transactions** | Editing an expense amount writes a reversal of the old transaction and a new transaction at the new amount. Both are linked via the audit log to the expense edit event. |
| **Soft delete writes a reversal** | Soft-deleting an expense writes one cashbox transaction reversing the original. The expense row stays (soft-deleted) for audit. |
| **Approval thresholds (later phase)** | A future phase may require an approval workflow for expenses above a per-role threshold (e.g. agent ≤ 500 EGP without approval). Reuses the existing `ApprovalService`. Not in the initial Phase 4 scope. |

---

## 6. Returns and Refunds

| Rule | Detail |
|---|---|
| **Return is goods movement** | The `order_returns` table and `ReturnService` describe the goods state (Pending → Received → Inspected → Restocked / Damaged → Closed). Returns affect inventory. |
| **Refund is money movement** | The new `refunds` table describes the money state (Requested → Approved → Paid / Rejected). Refunds affect cashboxes. |
| **No financial impact until refund is Paid** | `status = 'Requested'` and `status = 'Approved'` are paperwork only. `status = 'Paid'` is the only state that writes a cashbox transaction. |
| **A return can have 0, 1, or N refunds** | Partial refunds are real (e.g. refund shipping only, not goods). Linkage is via `refund.order_return_id` (nullable). |
| **A refund can exist without a return** | Goodwill refunds, customer-service refunds, mis-charge corrections — all allowed. `order_return_id` is nullable. |
| **Refund requires a cashbox** | By the time `status='Paid'`, `cashbox_id` and `payment_method_id` are required. Service layer rejects payment otherwise. |
| **Cannot refund more than collected** | Hard rule: `SUM(refunds.amount WHERE order_id=X AND status IN ('Approved', 'Paid')) ≤ collections.amount_collected`. Service layer guard. |
| **Partial refunds supported** | Multiple refund rows per order are allowed up to the over-refund limit. |
| **Same payment method suggested, not mandatory** | The UI pre-selects the cashbox where the original collection landed. The operator may override (e.g. customer asks for cash refund of a Visa purchase). |
| **Rejected refunds are kept** | A refund moved to `Rejected` stays in the table (status=Rejected) with the rejection reason. Never deleted. |

---

## 7. Marketer payouts

| Rule | Detail |
|---|---|
| **Profit earned only after delivery** | Existing rule, unchanged. `Earned Profit` row is created only when the order reaches `Delivered`. |
| **Return reverses earned profit** | New rule (Phase 6): when order goes to `Returned`, a `Cancelled Profit` row mirroring the original Earned Profit is written. Net effect on `marketer_wallet.balance` is the reversal. |
| **Payout requires cashbox + payment method** | Marketer payout (`MarketerWalletService::payout`) requires `cashbox_id` and `payment_method_id` from Phase 6 onward. |
| **Payout creates an OUT transaction** | A payout writes (a) a `marketer_transactions` row of type `Payout`, status `Paid`, AND (b) a `cashbox_transactions` row with `source_type='marketer_payout'`, negative amount. Both share linkage via `source_id` pointing to the marketer_transactions id. |
| **Cannot pay out more than balance** | Existing rule, unchanged. `force` flag (admin override) is preserved but should write a prominent audit log entry. |
| **Adjustments allowed** | `MarketerWalletService::adjust` continues to work; for Phase 6 it should support attaching a cashbox if the adjustment is a real money movement. Pure book adjustments do not need a cashbox. |

---

## 8. Cashbox transfers

| Rule | Detail |
|---|---|
| **A transfer is two transactions** | One `out` on the source cashbox, one `in` on the destination cashbox. Both rows share a `transfer_id` so reports can group them. |
| **Same currency only** | The two cashboxes must have the same `currency_code`. Cross-currency transfers are out of scope. |
| **Self-transfer is rejected** | `from_cashbox_id != to_cashbox_id`. |
| **Inactive cashboxes cannot send or receive** | Service-layer guard. |
| **Atomic** | The transfer row and both transactions are written inside a single DB transaction. Partial failure rolls back. |
| **Transfers excluded from net cash movement** | When computing "net cash movement", reports filter `source_type NOT IN ('transfer', 'opening_balance')`. Otherwise transfers double-count. |

---

## 9. Order Price Override

| Rule | Detail |
|---|---|
| **Not allowed until finance foundation is ready** | Override depends on refunds existing (so the system can detect "paid refund exists, block override"). Lands in Phase 9, not before. |
| **Does not change master product price** | `products.selling_price` is unchanged. Only `order_items.unit_price` is mutated for that specific order. |
| **`original_unit_price` must be preserved** | At first override, the system snapshots the pre-override value into `order_items.original_unit_price`. Subsequent overrides do **not** overwrite `original_unit_price` — it always reflects the first price applied. |
| **Reason required** | `order_items.price_override_reason` (string) is required at override time. Free text. |
| **Audit required** | Every override writes one `audit_logs` row with old_values + new_values JSON (existing pattern). |
| **Block if Paid refund exists** | Service-layer guard: if `refunds.status='Paid' AND order_id=X` returns any row, override is rejected with a clear error. |
| **Approval (optional, later)** | A future enhancement may require approval for overrides above a threshold (e.g. 10% off). Reuses ApprovalService. |
| **Recompute all derived totals** | Override triggers `OrderService::recomputeTotals()` which updates order totals, gross/net/marketer profit. `MarketerWalletService::syncFromOrder()` is called to update the marketer ledger. |

---

## 10. General rules that cross every module

| Rule | Detail |
|---|---|
| **Audit log everything that touches money** | Any insert/update/state-change on cashboxes, cashbox_transactions, refunds, payment_methods, cashbox_transfers, or on the new finance columns of collections/expenses/marketer_transactions writes an `audit_logs` row. |
| **Permission separation** | Request, approve, and execute are distinct permissions wherever money leaves the system. `refunds.create` ≠ `refunds.approve` ≠ `refunds.pay`. |
| **Fiscal lock applies to all financial writes** | Once a fiscal year is `Closed`, no financial transaction with `occurred_at` inside it may be inserted, reversed, or modified. Centralised via `FiscalYearGuard` helper (Phase 8). |
| **No silent failures** | Every refused operation surfaces a flash error or validation error to the operator. No swallowed exceptions. |
| **DB transaction wrapping** | Every multi-row financial write is wrapped in `DB::transaction()`. Partial state must never be possible. |
| **Single currency for now** | All financial operations assume the system's configured currency. Cross-currency operations are explicitly rejected at the service layer to surface design holes early. |

---

## Cross-references

- Module map → [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
- Phased delivery plan → [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
- Schema for the tables these rules govern → [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md)
- Permissions enforcing rules 7 and 10 → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Risk register → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
