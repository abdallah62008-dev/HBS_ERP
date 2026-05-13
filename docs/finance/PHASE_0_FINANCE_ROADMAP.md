# Phase 0 — Finance Roadmap (Planning Era)

> **Companion to:** [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
> **Purpose:** The exact phased plan to move HBS_ERP from "money lives in row snapshots" to a working hybrid lightweight ERP finance model.
>
> ⚠️ **This is the original planning document. The actual implementation diverged.** Phase 5 split into 5A–5F.1, Phase 6 "Marketer Payouts" became Phase 5D, Phase 7 "Reports" became Phase 5E, Phase 8 "Fiscal Controls / FiscalYearGuard" became Phase 5F as `FinancePeriodService` + `finance_periods`. Phase 9 "Order Price Override" remains deferred.
>
> **For the as-shipped phase mapping** see [`RELEASE_NOTES.md`](RELEASE_NOTES.md). For the current-state architecture, see [`FINANCE_MODULE_FINAL_OVERVIEW.md`](FINANCE_MODULE_FINAL_OVERVIEW.md).

Each phase below was **independently committable, independently shippable**. None of the phases needed to land in the same release window. Phase 0 was documentation only; Phases 1–9 in the original plan deliver code (in practice, Phases 1–5F.1 shipped; 6+ remains).

---

## Phase 0 — Documentation and Architecture

| Field | Value |
|---|---|
| **Goal** | Establish a written, reviewed financial architecture before any code is written. |
| **Scope** | The seven Markdown documents in `docs/finance/`. |
| **Files** | New: `docs/finance/*.md`. |
| **Migrations** | None. |
| **Tests** | None. |
| **Risks** | Minimal — documentation. Risk is the docs becoming stale; mitigated by referencing them from PR descriptions in later phases. |
| **Commit strategy** | One commit: `Document finance architecture roadmap`. |
| **Go / no-go** | Document review by an engineer and a business stakeholder. |

---

## Phase 1 — Cashboxes Foundation

| Field | Value |
|---|---|
| **Goal** | Create the cashboxes registry and the append-only transaction ledger. Provide statements and balances. **No integration with collections, expenses, or refunds yet.** |
| **Scope** | A self-contained module: list cashboxes, view balance, view statement, add a manual adjustment transaction. |
| **Files likely to change** | `app/Models/Cashbox.php` (new), `app/Models/CashboxTransaction.php` (new), `app/Services/CashboxService.php` (new), `app/Http/Controllers/CashboxesController.php` (new), `app/Http/Controllers/CashboxTransactionsController.php` (new), `resources/js/Pages/Cashboxes/Index.jsx`, `Edit.jsx`, `Statement.jsx`, `resources/js/Config/sidebar.js` (add nav entry), `routes/web.php` (add resource routes), `database/seeders/PermissionsSeeder.php` (add slugs), `database/seeders/RolesSeeder.php` (grant to roles). |
| **Migrations needed** | `create_cashboxes_table`, `create_cashbox_transactions_table`. Two new tables — no edits to existing tables. |
| **Tests needed** | `tests/Feature/CashboxTest.php` covering: opening balance writes one transaction, balance = SUM(transactions), opening balance is write-once after first non-opening transaction, deactivation prevents new transactions, `cashboxes.view` is permission-gated, audit-log row written on every mutation. |
| **Risks** | Low — pure additive. No existing data affected. |
| **Commit strategy** | One commit: `Add cashboxes finance foundation`. |
| **Go / no-go** | All tests pass; an admin can create a cashbox, view its (empty) statement, add a manual adjustment, and see the balance change. |

---

## Phase 2 — Payment Methods + Transfers

| Field | Value |
|---|---|
| **Goal** | Add the lookup table for payment methods and the ability to transfer money between cashboxes (paired transactions). |
| **Scope** | `payment_methods` table seeded with the canonical 7 methods. `cashbox_transfers` table. A "Transfer" page in the UI. |
| **Files likely to change** | `app/Models/PaymentMethod.php` (new), `app/Models/CashboxTransfer.php` (new), extend `app/Services/CashboxService.php` with `transfer()`, new controller `CashboxTransfersController`, new page `Pages/Cashboxes/Transfer.jsx`, `database/seeders/PaymentMethodsSeeder.php` (new), `routes/web.php`. |
| **Migrations needed** | `create_payment_methods_table`, `create_cashbox_transfers_table`. Two new tables. |
| **Tests needed** | Transfer writes exactly two transactions linked by `transfer_id`; both have correct signs; rejects self-transfer; rejects cross-currency; rejects inactive source or destination; permission-gated by `cashbox_transfers.create`. |
| **Risks** | Low. |
| **Commit strategy** | One commit: `Add payment methods and cashbox transfers`. |
| **Go / no-go** | Seeded payment methods visible. An admin can transfer X EGP from Main Cash to Bank Account, see both balances update, see the paired transactions in each statement. |

### Seeded payment methods
| Code | Name | Default cashbox |
|---|---|---|
| `cash` | Cash | Main Cash |
| `visa` | Visa / POS | Visa POS |
| `vodafone_cash` | Vodafone Cash | Vodafone Cash |
| `bank_transfer` | Bank Transfer | Bank Account |
| `courier_cod` | Courier COD | Courier COD Wallet |
| `amazon_wallet` | Amazon Wallet | Amazon Wallet |
| `noon_wallet` | Noon Wallet | Noon Wallet |

Cashboxes named above are conventions, not seeded by default — admin sets them up to match their actual environment.

---

## Phase 3 — Collections Integration

| Field | Value |
|---|---|
| **Goal** | Connect collections to cashboxes. Implement settlement reconciliation for courier COD. |
| **Scope** | Add `cashbox_id` and `payment_method_id` (both nullable) to `collections`. New "Reconcile courier settlement" bulk action. Automatic cashbox transaction on settlement. Existing flows for prepaid orders also write cashbox transactions. |
| **Files likely to change** | Migration to add columns. `app/Services/CollectionService.php` (new or extend existing handling). `app/Http/Controllers/CollectionsController.php` extended. New page `Pages/Collections/ReconcileSettlement.jsx`. Sidebar entry. |
| **Migrations needed** | `add_cashbox_and_payment_method_to_collections` (two nullable columns). |
| **Tests needed** | Direct/prepaid collection on order delivery writes a cashbox `in` transaction. Courier COD on delivery moves collection to `Pending Settlement` and writes **no** cashbox transaction yet. Settlement reconciliation writes one cashbox transaction per collection. Partial collection respects `amount_collected` exactly. Cannot reconcile the same collection twice (double-post guard). |
| **Risks** | Medium — touches existing collection flows. Feature flag the new behaviour during rollout. |
| **Commit strategy** | One commit: `Integrate collections with cashboxes`. |
| **Go / no-go** | A test order delivered with payment method = Cash writes +X to Main Cash immediately. A test order delivered as Courier COD writes nothing; running "Reconcile settlement" writes +X to Courier COD Wallet. Both collection rows show their cashbox and payment method. |

---

## Phase 4 — Expenses Integration

| Field | Value |
|---|---|
| **Goal** | Tie every new expense to a cashbox so expenses actually reduce a tracked balance. Preserve historical expenses by allowing legacy nulls. |
| **Scope** | Add `cashbox_id` and `payment_method_id` (nullable) to `expenses`. New expenses require these via form validation. Historical rows stay null. Expense save writes a cashbox `out` transaction. |
| **Files likely to change** | Migration. `app/Http/Controllers/ExpensesController.php`. `resources/js/Pages/Expenses/Form.jsx`. Optional: `app/Services/ExpenseService.php` to centralize the cashbox-side write. |
| **Migrations needed** | `add_cashbox_and_payment_method_to_expenses` (two nullable columns). |
| **Tests needed** | Saving a new expense writes a `cashbox_transactions` row with `source_type='expense'` and negative amount. Editing an expense's amount writes a correction transaction (reversal + new). Deleting an expense (soft) writes a reversal transaction. Historical expenses (cashbox_id null) are tolerated by reports. Permission `expenses.assign_cashbox` enforced. |
| **Risks** | Medium — every new expense now affects a balance. Validate cashbox is active and currency matches. |
| **Commit strategy** | One commit: `Integrate expenses with cashboxes`. |
| **Go / no-go** | Recording an expense of X EGP from Main Cash reduces Main Cash balance by X. The expense detail page links to the matching cashbox transaction. |

---

## Phase 5 — Returns / Refunds Financial Handling

| Field | Value |
|---|---|
| **Goal** | Make refunds a first-class concept with their own lifecycle. Block over-refunds. Connect Paid refunds to a cashbox outflow. |
| **Scope** | New `refunds` table. New `RefundService` and `RefundsController`. New pages: list, create, detail. Refund states: `Requested → Approved → Paid` / `Rejected`. Strict over-refund guard at the service layer. |
| **Files likely to change** | `app/Models/Refund.php` (new), `app/Services/RefundService.php` (new), `app/Http/Controllers/RefundsController.php` (new), pages, sidebar entry, routes, permissions, role grants. |
| **Migrations needed** | `create_refunds_table`. |
| **Tests needed** | Refund lifecycle transitions; only `Paid` writes a cashbox transaction; refund Paid sets `collections.collection_status` to `Refunded` when the refund covers full amount; `SUM(refunds.amount WHERE status IN ('Approved','Paid'))` ≤ `collections.amount_collected`; rejecting a refund writes audit log; partial refunds work; refund without `order_return_id` (goodwill) works. |
| **Risks** | Medium-High — multi-state machine, real money outflow. Strict permission separation: `refunds.create` ≠ `refunds.approve` ≠ `refunds.pay`. |
| **Commit strategy** | One commit: `Add refunds financial lifecycle`. |
| **Go / no-go** | An operator can request a refund against a delivered order, a manager can approve it, an accountant can pay it from Main Cash, and the cash balance drops by the refund amount. Attempting to refund more than collected is rejected with a clear error. |

---

## Phase 6 — Marketer Payouts and Profit Reversal

| Field | Value |
|---|---|
| **Goal** | (a) When an order goes to `Returned`, reverse the marketer's earned profit. (b) Make marketer payouts require a cashbox + payment method and write a real cashbox outflow. |
| **Scope** | Extend `MarketerWalletService::syncFromOrder()` to handle the `Returned` status (writes a `Cancelled Profit` row, net_profit=0). Extend payout flow with required cashbox/payment method selectors. |
| **Files likely to change** | `app/Services/MarketerWalletService.php`. `app/Http/Controllers/MarketersController.php` (payout endpoint). Pages: `Pages/Marketers/Payout.jsx`. Migration to add `cashbox_id` and `payment_method_id` (nullable on the table; required at the service layer only for payout rows). |
| **Migrations needed** | `add_cashbox_and_payment_method_to_marketer_transactions` (two nullable columns). |
| **Tests needed** | Order Delivered → Returned writes a `Cancelled Profit` row mirroring the original Earned Profit; marketer wallet balance reflects the reversal. Payout requires `cashbox_id` and `payment_method_id`; payout writes both a marketer_transactions row and a cashbox_transactions row, linked by `source_type='marketer_payout'`. Cannot pay out more than balance (unless `force`). |
| **Risks** | Low-Medium — small surface, but marketer profit is sensitive. Audit-log every change. |
| **Commit strategy** | One commit: `Integrate marketer payouts with cashboxes`. |
| **Go / no-go** | Returning a previously-delivered marketer order drops their wallet balance by the prior earned profit. Paying out a marketer reduces both their wallet balance and the cashbox balance. |

---

## Phase 7 — Finance Reports and Dashboard

| Field | Value |
|---|---|
| **Goal** | Surface the finance picture: cashbox balances, statements, refunds and expenses by cashbox, COD pending from courier, marketplace wallet balances, net cash movement. Wire into the existing dashboard Finance Snapshot band. |
| **Scope** | New ReportsService methods. New report pages. New dashboard cards (extending Phase 2 Finance Snapshot). New finance dashboard page (`/finance`). |
| **Files likely to change** | `app/Services/ReportsService.php` (new methods). `app/Http/Controllers/ReportsController.php`. New pages under `Pages/Reports/`. `app/Http/Controllers/DashboardController.php` + `app/Services/DashboardMetricsService.php` (extend with finance metrics; gated by `finance.reports`). `Pages/Dashboard.jsx`. |
| **Migrations needed** | None (read-only over Phase 1–6 data). |
| **Tests needed** | Each report query is correct against synthesized data. Permission gating on every new prop. Net cash movement excludes `source_type='transfer'` and `source_type='opening_balance'`. |
| **Risks** | Low — read-only. |
| **Commit strategy** | One commit: `Add finance reports and dashboard`. |
| **Go / no-go** | An admin can see live cashbox balances on the dashboard. The "Net cash movement (today/MTD)" matches the sum of non-transfer transactions in the period. |

### Reports added in this phase
- Cashbox balances
- Cashbox statement (per cashbox)
- Collections by cashbox
- Refunds by cashbox
- Expenses by cashbox
- COD pending from courier
- Marketplace wallet balances
- Net cash movement (period)
- Profit after refunds and expenses (extends existing profit report)

### Dashboard cards added
- Cash in hand
- COD pending from courier
- Marketplace wallet balance (top 2)
- Refunds pending approval
- Refunds pending payment
- Net cash movement today
- Marketer payouts pending

---

## Phase 8 — Fiscal Controls

| Field | Value |
|---|---|
| **Goal** | Once a fiscal year is `Closed`, prevent any new financial write whose `occurred_at` falls inside it. Reversal-only principle becomes hard-enforced. |
| **Scope** | Extend the existing fiscal-year lock pattern (already in `OrderService`) to cashbox writes, refund writes, expense writes, collection settlements, marketer payouts. |
| **Files likely to change** | `app/Services/CashboxService.php`, `app/Services/RefundService.php`, `app/Services/CollectionService.php`, `app/Services/ExpenseService.php` (if extracted), `app/Services/MarketerWalletService.php`. A small `FiscalYearGuard` helper may be useful. |
| **Migrations needed** | None. |
| **Tests needed** | Writing any financial transaction with `occurred_at` in a closed year raises the appropriate exception and is rejected. Reversal transactions in a closed year are also blocked. Audit log records the block attempt. |
| **Risks** | Low — additive guards. The risk is missing a code path; mitigated by a centralized helper. |
| **Commit strategy** | One commit: `Add fiscal controls for finance transactions`. |
| **Go / no-go** | A user with full permissions cannot post a cashbox transaction dated to a closed fiscal year; the UI surfaces a clear error. |

---

## Phase 9 — Order Price Override

| Field | Value |
|---|---|
| **Goal** | Allow authorized users to override an order item's unit price after order creation, with audit, optional approval, and protection against paid refunds. |
| **Scope** | Add `original_unit_price`, `price_override_reason`, `price_override_by` to `order_items` (and backfill). Add `orders.override_price` permission. Recompute all derived totals (order totals, marketer profit) on override. Block override if any `refunds.status='Paid'` exists for the order. |
| **Files likely to change** | Migration with backfill. `app/Http/Controllers/OrderItemsController.php` or new endpoint on `OrdersController`. `app/Services/OrderService.php` (recompute path). `app/Services/MarketerWalletService.php` (called by recompute). Pages: order edit form. Permissions and roles. |
| **Migrations needed** | `add_price_override_columns_to_order_items` (3 columns) **plus** a one-time data migration to set `original_unit_price = unit_price` for every existing row. |
| **Tests needed** | Override re-runs profit calculations correctly. Override is blocked if order has any `refunds.status='Paid'`. Audit log captures old + new unit_price. Permission `orders.override_price` enforced. Override stays inside fiscal-year lock. |
| **Risks** | High — touches revenue derivation. Land only after Phases 1–8 stabilise. |
| **Commit strategy** | One commit: `Add guarded order price override`. |
| **Go / no-go** | Override on a clean order updates totals and marketer profit correctly. Override on an order with a paid refund is rejected. Audit log shows the original and new unit_price values. |

---

## Phase dependency graph

```
Phase 0  (docs)
   │
   ▼
Phase 1  Cashboxes Foundation
   │
   ▼
Phase 2  Payment Methods + Transfers
   │
   ├──────────────┐
   ▼              ▼
Phase 3        Phase 4
Collections    Expenses
   │              │
   └──────┬───────┘
          ▼
Phase 5  Refunds  ────────────► Phase 6  Marketer Payouts + Reversal
          │                         │
          └────────────┬────────────┘
                       ▼
                 Phase 7  Reports + Dashboard
                       │
                       ▼
                 Phase 8  Fiscal Controls
                       │
                       ▼
                 Phase 9  Order Price Override
```

Phases 3 and 4 are independent of each other and can be parallelised. Everything else is strictly sequential.

---

## Cross-references

- Architecture & principles → [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
- Business rules per module → [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
- Draft schema → [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md)
- Permissions and role matrix → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Risks, controls, audit → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
- Per-phase Claude prompts and validation commands → [PHASE_0_IMPLEMENTATION_SEQUENCE.md](PHASE_0_IMPLEMENTATION_SEQUENCE.md)
