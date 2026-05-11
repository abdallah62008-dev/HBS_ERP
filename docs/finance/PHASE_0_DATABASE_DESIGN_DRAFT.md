# Phase 0 — Database Design Draft

> **Companion to:** [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
> **Status:** DRAFT. Exact column names, indexes, and FK behaviour are finalised at the start of each implementation phase. This document is the agreed-upon shape, not the migration source.
> **Principle:** All migrations are **additive and reversible**. No destructive `down()`. No editing of existing rows except documented safe backfills.

---

## 1. New tables

### 1.1 `cashboxes` (Phase 1)

The registry of cash locations, wallets, and accounts.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | — | — | |
| `name` | string(80) | no | — | "Main Cash", "Visa POS", "Vodafone Cash", "Bank Account", "Amazon Wallet", … |
| `type` | enum | no | — | `cash` \| `bank` \| `digital_wallet` \| `marketplace` \| `courier_cod` \| `other` |
| `currency_code` | string(8) | no | from settings | Snapshot; immutable after first transaction |
| `opening_balance` | decimal(14,2) | no | 0 | Editable only while the cashbox has 0 transactions |
| `allow_negative_balance` | bool | no | true | Per-cashbox policy |
| `is_active` | bool | no | true | Deactivation is the only way to retire |
| `description` | text | yes | null | Free notes |
| `created_by` | FK users | yes | null | onDelete: null |
| `updated_by` | FK users | yes | null | onDelete: null |
| `created_at` / `updated_at` | timestamps | — | — | |

**Indexes:** unique `name`; `(is_active, type)`; `currency_code`.
**Soft delete:** intentionally **omitted**. Deactivation replaces deletion.
**Audit:** every create, edit (name / type / is_active / description), and deactivation writes an `audit_logs` row.

---

### 1.2 `cashbox_transactions` (Phase 1)

The append-only ledger.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | — | — | |
| `cashbox_id` | FK cashboxes | no | — | onDelete: restrict (cashboxes are never hard-deleted) |
| `direction` | enum | no | — | `in` \| `out`. Informational; sign of `amount` is authoritative. |
| `amount` | decimal(14,2) | no | — | Signed: +inflow, −outflow |
| `occurred_at` | timestamp | no | — | When the money actually moved |
| `source_type` | string(40) | yes | null | `opening_balance` \| `collection` \| `expense` \| `refund` \| `transfer` \| `marketer_payout` \| `adjustment` |
| `source_id` | bigint unsigned | yes | null | Polymorphic-by-convention; not a real FK |
| `transfer_id` | FK cashbox_transfers | yes | null | Set on the two halves of a transfer |
| `payment_method_id` | FK payment_methods | yes | null | Available from Phase 2 |
| `notes` | text | yes | null | Operator notes; required for adjustments |
| `created_by` | FK users | no | — | Required |
| `created_at` | timestamp | — | — | Immutable. No `updated_at`. |

**Indexes:** `(cashbox_id, occurred_at)`; `(source_type, source_id)`; `transfer_id`; `created_at`.
**Soft delete:** **omitted**. Append-only.
**Audit:** every insert writes an `audit_logs` row (action='cashbox_transaction.created', module='finance').

> The `payment_method_id` and `transfer_id` columns are added in this migration (nullable) so Phase 2 does not need to alter the table.

---

### 1.3 `payment_methods` (Phase 2)

Lookup table — small, seeded.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | — | — | |
| `name` | string(60) | no | — | "Cash", "Visa / POS", "Vodafone Cash", … |
| `code` | string(40) | no | — | Slug: `cash`, `visa`, `vodafone_cash`, … |
| `default_cashbox_id` | FK cashboxes | yes | null | Default landing place; nullable so a method can exist before its cashbox is created |
| `is_active` | bool | no | true | |
| `created_at` / `updated_at` | timestamps | — | — | |

**Indexes:** unique `code`; `is_active`.
**Soft delete:** **omitted**. Deactivation only.
**Audit:** every create, edit, deactivate.

**Seeded rows (Phase 2):**
| code | name |
|---|---|
| `cash` | Cash |
| `visa` | Visa / POS |
| `vodafone_cash` | Vodafone Cash |
| `bank_transfer` | Bank Transfer |
| `courier_cod` | Courier COD |
| `amazon_wallet` | Amazon Wallet |
| `noon_wallet` | Noon Wallet |

---

### 1.4 `cashbox_transfers` (Phase 2)

Records that a transfer happened. Each row spawns exactly two `cashbox_transactions`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | — | — | |
| `from_cashbox_id` | FK cashboxes | no | — | onDelete: restrict |
| `to_cashbox_id` | FK cashboxes | no | — | onDelete: restrict; check `<> from` |
| `amount` | decimal(14,2) | no | — | Positive (sign is implied by direction) |
| `occurred_at` | timestamp | no | — | |
| `reason` | string(180) | yes | null | |
| `created_by` | FK users | no | — | |
| `created_at` / `updated_at` | timestamps | — | — | |

**Indexes:** `(from_cashbox_id, occurred_at)`, `(to_cashbox_id, occurred_at)`.
**Soft delete:** **omitted**.
**Audit:** create writes an audit row.

---

### 1.5 `refunds` (Phase 5)

The money-movement counterpart to `order_returns`.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK | — | — | |
| `order_id` | FK orders | no | — | Required |
| `order_return_id` | FK order_returns | yes | null | Optional — refunds can exist without a return (goodwill) |
| `amount` | decimal(14,2) | no | — | Positive |
| `reason` | string(180) | no | — | |
| `status` | enum | no | `Requested` | `Requested` \| `Approved` \| `Paid` \| `Rejected` |
| `cashbox_id` | FK cashboxes | yes | null | Required by service layer when status moves to `Paid` |
| `payment_method_id` | FK payment_methods | yes | null | Required when status moves to `Paid` |
| `requested_by` | FK users | no | — | |
| `approved_by` | FK users | yes | null | |
| `approved_at` | timestamp | yes | null | |
| `paid_at` | timestamp | yes | null | Stamped when status moves to `Paid` |
| `notes` | text | yes | null | |
| `created_at` / `updated_at` | timestamps | — | — | |

**Indexes:** `(order_id)`; `(status, paid_at)`; `(order_return_id)`.
**Soft delete:** **omitted**. Rejected refunds stay with `status='Rejected'`.
**Audit:** every state transition writes an audit row including old_values + new_values.

---

## 2. Extensions to existing tables

All extensions are **nullable column additions** — no edits to existing data.

### 2.1 `collections` — Phase 3
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `cashbox_id` | FK cashboxes | yes | null | Required at service layer when collection_status reaches `Collected` or `Settlement Received` |
| `payment_method_id` | FK payment_methods | yes | null | Same |

**Index:** `(cashbox_id, settlement_date)`.

### 2.2 `expenses` — Phase 4
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `cashbox_id` | FK cashboxes | yes | null | Required for new expenses; legacy rows stay null |
| `payment_method_id` | FK payment_methods | yes | null | Same. The existing free-text `payment_method` column is preserved for historical fidelity. |

**Index:** `(cashbox_id, expense_date)`.

### 2.3 `marketer_transactions` — Phase 6
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `cashbox_id` | FK cashboxes | yes | null | Required at service layer for `Payout` type rows |
| `payment_method_id` | FK payment_methods | yes | null | Same |

**Index:** `(cashbox_id)` only for Payout rows.

### 2.4 `order_items` — Phase 9
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `original_unit_price` | decimal(12,2) | no | 0 | Backfilled from `unit_price` for every existing row in a one-time data migration immediately after the column is added |
| `price_override_reason` | string(180) | yes | null | Required at override time via service-layer validation |
| `price_override_by` | FK users | yes | null | onDelete: null. Stamped on every override. |

**Index:** none new (existing `(order_id, product_id)` is sufficient).

**Backfill strategy for `original_unit_price`:**
1. Migration adds the column with `default(0)` and `nullable=false`.
2. Within the same migration's `up()`, run `UPDATE order_items SET original_unit_price = unit_price`.
3. This is the only "edit existing data" migration in the entire roadmap.

---

## 3. Tables NOT being created

Documented here to avoid accidental design drift later.

| Table | Why not |
|---|---|
| `accounts` (chart of accounts) | This is a double-entry concept; the architecture is intentionally not double-entry. |
| `journal_entries` / `journal_lines` | Same. |
| `cashbox_balances` | Balances are computed, not stored. |
| `marketer_payouts` | Payouts continue to use `marketer_transactions` rows of type `Payout`. No parallel domain. |
| `collection_allocations` | Collections are 1:1 with orders today; no need for an allocation table. |
| `refund_payment_methods_history` | Refund's `payment_method_id` is sufficient; multi-method refunds are out of scope. |

---

## 4. Conventions across the schema

| Convention | Detail |
|---|---|
| **Money columns** | `decimal(14,2)` for cashbox amounts (signed, can be large). `decimal(12,2)` for per-row money like order_items, expenses, refunds (matches existing convention). |
| **Currency** | `currency_code` snapshot on rows that carry money. Single currency assumed. |
| **Timestamps** | `created_at` and `updated_at` on most tables. Append-only tables (`cashbox_transactions`) omit `updated_at`. |
| **Audit** | Done via `audit_logs` table, not via `withTrashed` shenanigans. AuditLogService::log() is the entry point. |
| **Soft delete** | Used sparingly. Cashboxes, transactions, transfers, refunds — none use SoftDeletes. Expenses already use SoftDeletes (existing) and we keep it. |
| **Foreign keys** | `onDelete('restrict')` for finance-critical refs. `onDelete('set null')` for user-attribution refs. Never `cascade` on finance tables. |

---

## 5. Migration order summary

| Phase | Migration name (proposed) | Tables touched |
|---|---|---|
| 1 | `create_cashboxes_table` | new |
| 1 | `create_cashbox_transactions_table` | new |
| 2 | `create_payment_methods_table` | new |
| 2 | `create_cashbox_transfers_table` | new |
| 3 | `add_cashbox_and_payment_method_to_collections` | extend collections (2 nullable cols) |
| 4 | `add_cashbox_and_payment_method_to_expenses` | extend expenses (2 nullable cols) |
| 5 | `create_refunds_table` | new |
| 6 | `add_cashbox_and_payment_method_to_marketer_transactions` | extend marketer_transactions (2 nullable cols) |
| 9 | `add_price_override_to_order_items` | extend order_items (3 cols + backfill) |

**Total: 4 new tables + 4 extension migrations + 1 column-add-and-backfill migration = 9 migrations across 5 implementation phases.** Phases 7 (reports) and 8 (fiscal controls) require no migrations.

---

## 6. What this draft is NOT

- **Not the final migration source.** Exact `Schema::create()` syntax, FK names, index names, and any small adjustments are decided at the start of each implementation phase. This document is the agreement on shape and intent.
- **Not destructive.** No `down()` migration drops user data. Even if rolled back, columns are dropped only after the corresponding feature is fully un-deployed.
- **Not a constraint engine.** Service-layer business rules (over-refund block, currency match, opening-balance write-once) are enforced in PHP, not via DB check constraints. This is consistent with the existing codebase pattern.

---

## Cross-references

- Rules these tables enforce → [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
- When each migration lands → [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
- Permission slugs guarding writes to these tables → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Audit and risk controls → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
