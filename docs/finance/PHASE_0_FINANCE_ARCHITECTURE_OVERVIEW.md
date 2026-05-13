# Phase 0 — Finance Architecture Overview

> **Status:** Originally written before any code shipped. Phases 1 → 5F.1 have since shipped to `main`.
> **Scope:** Sets the direction for the full HBS_ERP financial roadmap.
> **Audience:** Engineering, operations, and business stakeholders.
> **For the as-shipped picture** see [`FINANCE_MODULE_FINAL_OVERVIEW.md`](FINANCE_MODULE_FINAL_OVERVIEW.md) and [`RELEASE_NOTES.md`](RELEASE_NOTES.md). Where this doc diverges from the implementation, those documents take precedence.

---

## 1. Executive summary

HBS_ERP today tracks money as **column snapshots** on individual rows (orders, collections, expenses, marketer transactions). There is no general-purpose place that answers the question *"how much money do we have, and where?"* — there is no cash ledger, no payment account, and no concept of routing money to a specific cashbox.

This document and the six others in this folder establish the **Hybrid Lightweight ERP Finance** architecture for HBS_ERP. The plan is to add a small set of well-scoped tables and services — cashboxes, cashbox transactions, payment methods, transfers, refunds — that collectively answer all the questions an operations-grade ERP must answer:

- Where is the cash right now (per box, per type)?
- Did the courier pay us back for COD orders?
- What did this refund actually cost us?
- How much do we owe each marketer, and from which box will we pay them?
- What was our true net cash movement today / this month / this fiscal year?

Implementation will roll out **phase by phase**, each phase shippable in isolation, with the riskiest change — Order Price Override — kept until after the finance foundation is stable.

---

## 2. Why this architecture is needed

The current system has three structural gaps that create real operational risk:

1. **No source of cash truth.** Reports compute money on the fly from order rows and collection rows. Two reports can disagree on net revenue depending on whether they subtract refunds — because refunds are not real financial events yet.
2. **Returns do not move money.** A return today only reverses inventory. `order_returns.refund_amount` is recorded but never paid out. The marketer profit for a returned order is not reversed. Sales, profit, and marketer commissions are all over-stated whenever there is a return.
3. **No payment routing.** Cash, Visa, courier COD, Vodafone Cash, Amazon Wallet, Noon Wallet — all land in the same conceptual pool. Operationally they are very different (some are pending settlement, some are immediate, some are owed *to* us by a third party). The system today cannot tell them apart.

These gaps make it impossible to safely build features that depend on a clean financial picture — most notably **Order Price Override**, which directly mutates revenue.

---

## 3. Current gaps in the system

| Gap | Today | Consequence |
|---|---|---|
| **No cash ledger** | No `cashboxes` table, no `cashbox_transactions` table, no `accounts` table. | "How much cash do we have?" cannot be answered from data. |
| **Collections not linked to a cashbox** | `collections` has `amount_collected`, `collection_status`, `settlement_date` but no `cashbox_id` or `payment_method_id`. | Reconciliation is by-hand. Cannot tell where the money landed. |
| **Expenses not linked to a cashbox** | `expenses.payment_method` is free-text only. No `cashbox_id`. | Expenses do not reduce any tracked balance. |
| **Returns do not create real refunds** | `ReturnService::inspect()` writes `refund_amount` but no refund flow exists. | Operators issue refunds outside the system. No audit trail. |
| **Marketer profit not reversed on return** | `MarketerWalletService::syncFromOrder()` handles cancellation but not 'Returned'. | Marketer wallets over-state earnings when returns happen. |
| **Courier COD treated as collected** | `orders.cod_amount = total_amount` at creation. No "pending courier" state in cash terms. | Reports show cash that is not actually in our hands yet. |
| **Marketplace wallets invisible** | Amazon / Noon balances are not modeled at all. | Cannot answer "how much does Amazon owe us?". |
| **Order Price Override blocked** | `order_items` has no `original_unit_price`. No audit, no approval, no protection against override-after-refund. | The feature is unsafe to build today. |

---

## 4. Recommended architecture: Hybrid Lightweight ERP Finance

This architecture sits between two extremes and is the right fit for HBS_ERP at its current scale.

### What it is
- **Cashboxes** are the named places where money lives: Main Cash, Visa POS, Vodafone Cash, Bank Account, Amazon Wallet, Noon Wallet, Courier COD Wallet.
- **Cashbox transactions** are the append-only ledger of every money movement. Every transaction is signed (+inflow, −outflow), timestamped, attributable to a user, and linked to its source domain object (`collection`, `expense`, `refund`, `transfer`, `marketer_payout`, `opening_balance`, `adjustment`).
- **Payment methods** are a small lookup table (~7 rows) describing *how* money moved (Cash, Visa, Vodafone Cash, Bank Transfer, Courier COD, Amazon Wallet, Noon Wallet). Each method has a default cashbox to streamline UI entry.
- **Refunds** are a separate domain with their own lifecycle (Requested → Approved → Paid / Rejected). Only `Paid` writes a cashbox transaction. Refunds are never auto-issued by a return; a return prompts the question, a refund answers it.
- **Cashbox transfers** are paired transactions (one negative, one positive) tied together by a `transfer_id`. Reports can exclude transfers from "real" cash-flow totals.
- **Existing concepts extend cleanly:**
  - `collections` gains `cashbox_id` + `payment_method_id` (nullable initially).
  - `expenses` gains the same.
  - `marketer_transactions` gains the same on payout rows.

### What it is NOT
- It is **not** double-entry accounting. There is no chart of accounts, no debits-and-credits balancing requirement, no trial balance. The pattern intentionally does not enforce accounting-grade consistency at the DB layer.
- It is **not** a replacement for an external accounting system (QuickBooks / Xero). When HBS_ERP grows to need that, it will be exported to, not replaced by.
- It is **not** a multi-currency system. Single currency, snapshot via `currency_code` on every row, single setting `app.currency_code`.

---

## 5. Why not full double-entry accounting now

| Concern | Full double-entry | Hybrid lightweight | Verdict |
|---|---|---|---|
| Provable consistency | Every transaction balances (debits = credits) | Application layer guards | Hybrid trades audit-grade proof for simplicity |
| Reports | Trial balance, P&L, balance sheet come free | Per-domain reports written explicitly | Hybrid simpler to author and explain |
| Implementation cost | High — chart of accounts, journal entry posting layer | Low — six small tables + service layer | Hybrid ships in weeks, not months |
| Operator training | Requires accounting literacy | Operates like any other ERP module | Hybrid fits current team |
| Migration cost | Complex retro-fit of historical data | Additive (nullable columns + new tables) | Hybrid does not disturb history |
| Risk of getting it wrong | High (mismapping accounts is hard to detect) | Low (each domain has its own state machine) | Hybrid is safer |
| When to upgrade | When external accounting integration is required | — | Hybrid is a good staging ground |

The Hybrid model can later **feed** a double-entry system (each cashbox transaction maps to one journal entry pair) if the business grows in that direction. The reverse — collapsing a double-entry system into operational simplicity — is much harder.

---

## 6. Core finance principles

These principles apply to every phase of the roadmap. Every PR, review, and feature decision should be checked against them.

1. **No hard delete.** Cashboxes, cashbox transactions, refunds, payment methods are never `DELETE FROM`-ed. Inactive states (`is_active=false`, `status='Rejected'`) take the place of deletion.
2. **Cashbox transactions are append-only.** Once a row exists, it cannot be edited or removed. A mistake is corrected by writing a new, opposite-signed transaction (a "reversal"). Reversals are linked to the original via `notes` and audit log.
3. **Balance is calculated from transactions.** Always. `SUM(cashbox_transactions.amount WHERE cashbox_id=?)`. There is **no `current_balance` column** on `cashboxes`. Denormalised balances drift; the `marketer_wallets` table demonstrates how a derived balance must be recomputed, not stored authoritatively.
4. **Audit every money mutation.** Every cashbox transaction insert, every refund state change, every cashbox creation/edit/deactivation, every transfer, every payout — `AuditLogService::log()` is called. No silent edits.
5. **Refund is separate from Return.** Return = goods state machine (existing). Refund = money state machine (new). They are linked by `refund.order_return_id` but follow independent lifecycles. A return can have 0, 1, or N refunds.
6. **Courier COD is *pending* until settlement.** Delivering an order with payment method = "Courier COD" does **not** immediately credit any cashbox. The collection enters `Pending Settlement` state. Only when the courier remits is a cashbox transaction written (one per collection settled, linked to the settlement batch).
7. **Marketplace wallets are cashboxes.** Amazon Wallet and Noon Wallet are modelled as cashboxes of `type='marketplace'`. Marketplace sales credit them at delivery / settlement-per-marketplace-rules. Marketplace fees are out-transactions or expenses on the marketplace cashbox. Marketplace payouts to bank are `cashbox_transfers`.
8. **Closed finance periods are locked.** Once a finance period is `closed`, no financial transaction whose `occurred_at` falls inside it may be written. Implemented in Phase 5F as `FinancePeriodService::assertDateIsOpen()` + `finance_periods` table. The older `fiscal_years` annual-scope table still exists for accounting boundary tracking; the closed-period guard runs against `finance_periods`, not `fiscal_years`.

---

## 7. High-level module map

The following modules will be built (each is its own phase — see `PHASE_0_FINANCE_ROADMAP.md`).

| Module | Purpose | Phase |
|---|---|---|
| **Cashboxes** | The registry of every cash location, wallet, and account. Names, types, opening balances. | 1 |
| **Cashbox Transactions** | The append-only ledger. Every money movement, signed and attributed. | 1 |
| **Payment Methods** | Lookup table of how money moves (Cash, Visa, Vodafone Cash, Bank, Courier COD, Amazon Wallet, Noon Wallet). | 2 |
| **Transfers** | Paired transactions for moving money between cashboxes (e.g. Visa POS → Bank Account). | 2 |
| **Collections Integration** | `collections` gains `cashbox_id` + `payment_method_id`. Settlement reconciliation flow. COD pending-until-settled. | 3 |
| **Expenses Integration** | `expenses` gains `cashbox_id` + `payment_method_id`. Every new expense is a real outflow. | 4 |
| **Refunds** | New domain with its own lifecycle. Paid refunds write cashbox outflow. Over-refund prevented. | 5 |
| **Marketer Payouts + Profit Reversal** | Return reverses marketer profit. Payouts require cashbox + payment method. | 6 |
| **Finance Reports / Dashboard** | Cashbox balances, statements, COD pending, refund impact, marketer payouts pending, net cash movement. | 7 |
| **Fiscal Controls** | Block financial writes into closed fiscal periods. Reversal-only principle enforced in code. | 8 |
| **Order Price Override** | `original_unit_price` on order_items, audit, approval, block-if-paid-refund. | 9 |

---

## 8. Cross-references

- Roadmap with goals, files, migrations, tests per phase → [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
- Business rules (cashboxes, collections, refunds, marketer payouts, …) → [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
- Draft schema for new and extended tables → [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md)
- New permission slugs and role matrix → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Risks, controls, and audit requirements → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
- Execution sequence and per-phase Claude prompts → [PHASE_0_IMPLEMENTATION_SEQUENCE.md](PHASE_0_IMPLEMENTATION_SEQUENCE.md)
