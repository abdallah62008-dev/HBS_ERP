# Finance Architecture — Documentation Index

> **Project:** HBS_ERP (Hnawbas Operations System)
> **Folder:** `docs/finance/`
> **Current status:** **Phase 0 — Docs Only.** No application code, migrations, models, or seeders have been changed by this phase.

This folder contains the agreed-upon plan for evolving HBS_ERP from "money lives in row snapshots" to a working **Hybrid Lightweight ERP Finance** model.

The plan is intentionally additive, phased, and reversible. Every phase below is independently committable, testable, and shippable.

---

## Documents

| # | Document | Purpose |
|---|---|---|
| 1 | [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md) | The big picture. Current gaps, the recommended architecture, why not full double-entry, core principles, and the module map. |
| 2 | [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md) | Phase 1 through Phase 9, each with goal, scope, files, migrations, tests, risks, commit strategy, and go/no-go criteria. |
| 3 | [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md) | The concrete rules every finance code path must follow (cashboxes, collections, refunds, marketer payouts, override). |
| 4 | [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md) | Proposed schema for new tables (`cashboxes`, `cashbox_transactions`, `payment_methods`, `cashbox_transfers`, `refunds`) and nullable extensions to existing tables. |
| 5 | [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md) | New permission slugs and the recommended role matrix (separation of duties). |
| 6 | [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md) | Risk register, controls summary, per-phase audit obligations, rollback philosophy, reconciliation checklist. |
| 7 | [PHASE_0_IMPLEMENTATION_SEQUENCE.md](PHASE_0_IMPLEMENTATION_SEQUENCE.md) | Per-phase Claude prompt filenames, validation commands, manual checks, commit messages, push policy. |

---

## Roadmap summary

| Phase | Title | Headline deliverable |
|---|---|---|
| **0** | Documentation and Architecture | These docs (current phase) |
| **1** | Cashboxes Foundation | `cashboxes` + `cashbox_transactions` tables, statements, balances |
| **2** | Payment Methods + Transfers | Seeded 7 methods, `cashbox_transfers` table, transfer UI |
| **3** | Collections Integration | Collections linked to cashboxes; courier-COD settlement reconciliation |
| **4** | Expenses Integration | Expenses paid from a specific cashbox |
| **5** | Returns / Refunds Financial Handling | `refunds` table, lifecycle, over-refund guard |
| **6** | Marketer Payouts + Profit Reversal | Return reverses marketer profit; payouts from cashboxes |
| **7** | Finance Reports + Dashboard | Cashbox balances, statements, COD pending, refund impact |
| **8** | Fiscal Controls | Closed-period lock for all financial transactions |
| **9** | Order Price Override | Audited, approved, refund-aware price override |

Phases 1 and 2 are sequential. Phases 3 and 4 can be parallelised. Phases 5 → 6 → 7 → 8 → 9 are sequential.

---

## Core principles (one-line reminders)

1. No hard delete on finance tables — ever.
2. Cashbox transactions are append-only — corrections by reversal.
3. Balance is computed from transactions — never stored.
4. Every money mutation writes an audit log entry.
5. Refund is separate from Return — separate state machines, separate permissions.
6. Courier COD is pending until settlement — no premature cashbox credit.
7. Marketplace wallets are cashboxes — Amazon, Noon, etc.
8. Closed fiscal periods are locked — including reversals.
9. Order Price Override waits until refunds + cashboxes are stable.

---

## How to start a phase

1. Read [PHASE_0_IMPLEMENTATION_SEQUENCE.md §A](PHASE_0_IMPLEMENTATION_SEQUENCE.md) and run the pre-checks.
2. Open the prompt file named in the phase's row.
3. Implement the scope. Land one commit with the recommended message.
4. Run the post-phase validation commands. Push only when the user explicitly asks.

---

## Status board (update as phases land)

| Phase | Status |
|---|---|
| 0 — Docs | ✅ This folder |
| 1 — Cashboxes Foundation | ⬜ Not started |
| 2 — Payment Methods + Transfers | ⬜ Not started |
| 3 — Collections Integration | ⬜ Not started |
| 4 — Expenses Integration | ⬜ Not started |
| 5 — Refunds | ⬜ Not started |
| 6 — Marketer Payouts + Reversal | ⬜ Not started |
| 7 — Finance Reports + Dashboard | ⬜ Not started |
| 8 — Fiscal Controls | ⬜ Not started |
| 9 — Order Price Override | ⬜ Not started |

> Update this table at the end of each phase's commit message author's review, alongside the actual commit hash.
