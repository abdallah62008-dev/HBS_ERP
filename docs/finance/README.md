# Finance Architecture — Documentation Index

> **Project:** HBS_ERP (Hnawbas Operations System)
> **Folder:** `docs/finance/`
> **Current status:** **Phases 0 → 5F.1 shipped to `main`. Phase 5G (docs + QA) in progress.**

This folder contains both the original planning (Phase 0) for evolving HBS_ERP from "money lives in row snapshots" to a working **Hybrid Lightweight ERP Finance** model, AND the as-built current-state references.

The plan was intentionally additive, phased, and reversible. The actual implementation diverged from the original phase numbering — see `RELEASE_NOTES.md` for the as-shipped breakdown.

## Authoritative current-state references

If you only have time for one document, read these in order:

| Document | When to read |
|---|---|
| [FINANCE_MODULE_FINAL_OVERVIEW.md](FINANCE_MODULE_FINAL_OVERVIEW.md) | First. Current architecture, models, services, permissions, workflows. |
| [RELEASE_NOTES.md](RELEASE_NOTES.md) | Second. What shipped in each phase, with commit hashes + user impact. |
| [QA_CHECKLIST.md](QA_CHECKLIST.md) | Before a release. Nine user journeys covering the entire module. |

The Phase 0 documents below are the **planning era** record and are kept for historical context. Where they diverge from the as-shipped implementation, the three documents above take precedence.

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

## Roadmap summary — AS SHIPPED

| Phase | Title | Headline deliverable | Status |
|---|---|---|---|
| **0** | Documentation and Architecture | These docs | ✅ shipped |
| **1** | Cashboxes Foundation | `cashboxes` + `cashbox_transactions` tables, statements, balances | ✅ shipped |
| **2** | Payment Methods + Transfers | Seeded methods, `cashbox_transfers` table, transfer UI | ✅ shipped |
| **3** | Collections Integration | Collections linked to cashboxes; courier-COD settlement reconciliation | ✅ shipped |
| **4** | Expenses Integration | Expenses paid from a specific cashbox | ✅ shipped |
| **4.5** | Cashbox Hardening & Immutability | Append-only `booted` hooks, lock-for-update on posting transactions | ✅ shipped |
| **5A** | Refunds Foundation | `refunds` table, paperwork lifecycle (requested → approved/rejected), over-refund guard | ✅ shipped |
| **5B** | Refund Payment + Cashbox OUT | `pay()` writes a cashbox OUT with `source_type='refund'` | ✅ shipped |
| **5C** | Returns Financial Handling | Refund request linked to `order_return_id`; over-return guard | ✅ shipped |
| **5D** | Marketer Payouts / Profit Reversal | `marketer_payouts` workflow + cashbox mirror + idempotent refund-driven profit reversal | ✅ shipped |
| **5E** | Finance Reports | 9 read-only cashbox-domain reports | ✅ shipped |
| **5F** | Finance Controls / Period Close | `finance_periods` + closed-period guard wired into all cash-impacting services | ✅ shipped |
| **5F.1** | Cashbox UX Fix | Surface cashbox guard errors as flash instead of 500 | ✅ shipped |
| **5G** | Documentation + Manual QA | This phase — refresh planning docs, add release notes + final overview + QA checklist | 🟡 in progress |
| **6+** | Order Price Override (and other follow-ups) | Audited, approved, refund-aware price override | ⬜ not started |

### Divergence from original plan

The original Phase 0 plan listed Phase 5 as a single "Refunds Financial Handling" phase and reserved Phase 8 for "Fiscal Controls" via a `FiscalYearGuard`. In practice:

- Phase 5 split into 5A–5F.1 (six sub-phases) because each shipped independently with its own commit + tests.
- Phase 8 "Fiscal Controls" landed as Phase 5F with a different name: **`FinancePeriodService`** + `finance_periods` table. The older `fiscal_years` annual table still exists for annual scope but is **not** what the closed-period guard checks against.
- Phase 6 "Marketer Payouts" landed as Phase 5D.
- Phase 7 "Finance Reports + Dashboard" reports landed as Phase 5E; the dashboard portion was already shipped in earlier Dashboard work (Phases 1–2 of the Dashboard track, separate from Finance).
- Phase 9 "Order Price Override" remains deferred.

See [RELEASE_NOTES.md](RELEASE_NOTES.md) for commit-by-commit detail.

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

## Status board

| Phase | Status | Commit |
|---|---|---|
| 0 — Docs | ✅ shipped | (this folder) |
| 1 — Cashboxes Foundation | ✅ shipped | (initial cashboxes commit) |
| 2 — Payment Methods + Transfers | ✅ shipped | `819223a` |
| 3 — Collections Integration | ✅ shipped | `0a93d77` |
| 4 — Expenses Integration | ✅ shipped | `c0c8f20` |
| 4.5 — Cashbox Hardening | ✅ shipped | `b303a06` |
| 5A — Refunds Foundation | ✅ shipped | `e84f487` |
| 5B — Refund Payment | ✅ shipped | `5467edf` |
| 5C — Returns Financial Handling | ✅ shipped | `bdc68b3` |
| 5D — Marketer Payouts / Reversal | ✅ shipped | `73920c0` |
| 5E — Finance Reports | ✅ shipped | `888087d` |
| 5F — Period Close | ✅ shipped | `8379334` |
| 5F.1 — Cashbox UX Fix | ✅ shipped | `9137251` |
| 5G — Docs + QA | 🟡 in progress | (this commit) |
| 6+ — Order Price Override | ⬜ not started | — |

> Status board reflects what is on `main` at the time of the 5G commit.
