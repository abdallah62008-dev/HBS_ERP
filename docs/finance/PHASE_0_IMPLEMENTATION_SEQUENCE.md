# Phase 0 — Implementation Sequence (Planning Era)

> **Companion to:** [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
> **Purpose:** The original execution order, validation commands, and commit/push policy for every phase.
>
> ⚠️ **This is the planning-era sequence.** The actual implementation diverged. Phase 5 was executed as 5A–5F.1; the closed-period work landed at 5F (not Phase 8) as `FinancePeriodService`. For commit-by-commit detail see [`RELEASE_NOTES.md`](RELEASE_NOTES.md).

Each phase below assumes the engineer (or Claude Code instance) is operating on **branch `main`** of the project at `C:\Users\Lam\Downloads\HBS_ERP\APP`, with the working tree clean. The validation commands match the patterns already established in Phases 1 and 2 of the dashboard rollout.

---

## A. Standard checks (run for every phase)

### A.1 Before starting any phase
```bash
git status
git branch --show-current
php artisan test
npm run build
```
- `git status` must show a clean working tree (untracked `.claude/worktrees/` is acceptable).
- `git branch --show-current` should return `main`.
- Test suite must be green before any new code lands.
- Build must succeed before any new code lands.

### A.2 During every phase
- **Do NOT push.** Only the user pushes.
- **Do NOT deploy.**
- **Do NOT run `php artisan migrate:fresh`.**
- **Do NOT delete data.**
- **Do NOT change `.env`.**
- **Do NOT stage `.claude/worktrees/`.** Only the four-or-so changed files for the phase get staged.

### A.3 After every phase
```bash
php artisan test
npm run build
git status
git log --oneline -5
```
- All tests still green.
- Build still passes.
- `git status` shows only the intended files for this phase (modified + new).
- `git log --oneline -5` shows the new commit at HEAD.

---

## B. Per-phase plan

The implementer requests Phase N by sending the prompt named below. The prompt is the source of truth for scope and constraints; this document is the framing.

### Phase 0 — Documentation and Architecture
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_0_DOCS_CLAUDE_PROMPT.md` (already used to produce this folder) |
| **Implementation constraints** | Docs only. No code, no migrations, no permissions seeder changes. |
| **Validation commands** | `git status`. Tests and build are optional in this phase. |
| **Manual checks** | Open every doc in `docs/finance/` and verify it reads cleanly. |
| **Commit message** | `Document finance architecture roadmap` |
| **Push policy** | User-initiated only. |

---

### Phase 1 — Cashboxes Foundation
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_1_CASHBOXES_CLAUDE_PROMPT.md` |
| **Implementation constraints** | Self-contained module. No integration with collections, expenses, or refunds. New tables only; no edits to existing tables. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Create a cashbox; add a manual adjustment; view statement; confirm balance updates; confirm `cashboxes.view` gates the page. |
| **Commit message** | `Add cashboxes finance foundation` |
| **Push policy** | User-initiated only. |

---

### Phase 2 — Payment Methods + Transfers
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_2_PAYMENT_METHODS_AND_TRANSFERS_CLAUDE_PROMPT.md` |
| **Implementation constraints** | New tables only. The seven canonical payment methods are seeded. Transfers must be atomic. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Transfer X EGP from one cashbox to another and verify both balances and both statements. |
| **Commit message** | `Add payment methods and cashbox transfers` |
| **Push policy** | User-initiated only. |

---

### Phase 3 — Collections Integration
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_3_COLLECTIONS_INTEGRATION_CLAUDE_PROMPT.md` |
| **Implementation constraints** | Two nullable columns on `collections`. Touch existing flows carefully; feature-flag the new behaviour if needed. Settlement reconciliation is the new headline UI. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Direct (Cash) order delivery writes a cashbox `in` transaction immediately. Courier COD delivery writes no cashbox transaction. Reconcile settlement adds the cashbox transaction and flips collection_status. |
| **Commit message** | `Integrate collections with cashboxes` |
| **Push policy** | User-initiated only. |

---

### Phase 4 — Expenses Integration
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_4_EXPENSES_INTEGRATION_CLAUDE_PROMPT.md` |
| **Implementation constraints** | Two nullable columns on `expenses`. New expenses require `cashbox_id`. Historical rows stay null. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Record an expense from Main Cash — verify Main Cash balance drops; expense detail links to the matching cashbox transaction. |
| **Commit message** | `Integrate expenses with cashboxes` |
| **Push policy** | User-initiated only. |

---

### Phase 5 — Returns / Refunds Financial Handling
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_5_REFUNDS_CLAUDE_PROMPT.md` |
| **Implementation constraints** | One new table (`refunds`). Strict permission separation (`refunds.create` / `.approve` / `.pay` / `.reject`). Over-refund guard at the service layer. No financial impact until `status='Paid'`. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Request → Approve → Pay a partial refund. Attempt to refund more than collected (rejected). View the resulting cashbox transaction on the refund detail page. |
| **Commit message** | `Add refunds financial lifecycle` |
| **Push policy** | User-initiated only. |

---

### Phase 6 — Marketer Payouts and Profit Reversal
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_6_MARKETER_PAYOUTS_CLAUDE_PROMPT.md` |
| **Implementation constraints** | One column-extension migration on `marketer_transactions`. `MarketerWalletService::syncFromOrder()` extended for `Returned` status. Payout flow requires cashbox + payment method. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Move a marketer's delivered order to Returned — verify wallet balance drops. Pay out a marketer — verify both their wallet and the cashbox decrease. |
| **Commit message** | `Integrate marketer payouts with cashboxes` |
| **Push policy** | User-initiated only. |

---

### Phase 7 — Finance Reports and Dashboard
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_7_REPORTS_AND_DASHBOARD_CLAUDE_PROMPT.md` |
| **Implementation constraints** | Read-only. No migrations. Extends Phase 2 dashboard Finance Snapshot band. New dedicated `/finance` page is permitted. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Cash in hand card matches sum of cash-type cashboxes. Net cash movement excludes transfers and opening balances. Every new metric is permission-gated. |
| **Commit message** | `Add finance reports and dashboard` |
| **Push policy** | User-initiated only. |

---

### Phase 8 — Fiscal Controls
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_8_FISCAL_CONTROLS_CLAUDE_PROMPT.md` |
| **Implementation constraints** | No migrations. Centralized `FiscalYearGuard` helper. Every financial service consults the guard before insert / reversal. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Close a fiscal year; attempt to post a transaction with `occurred_at` inside the closed period; verify rejection with a clear error and an audit log entry. |
| **Commit message** | `Add fiscal controls for finance transactions` |
| **Push policy** | User-initiated only. |

---

### Phase 9 — Order Price Override
| Item | Value |
|---|---|
| **Prompt filename** | `HBS_ERP_FINANCE_PHASE_9_ORDER_PRICE_OVERRIDE_CLAUDE_PROMPT.md` |
| **Implementation constraints** | One migration with **the only data-altering step in the roadmap** — backfill `original_unit_price = unit_price` on every existing order_items row. Override path re-runs total + profit recomputation. Block override if any `refunds.status='Paid'` exists for the order. |
| **Validation commands** | `php artisan test`, `npm run build`, `git status` |
| **Manual checks** | Override a unit price on a clean order — verify order totals, gross/net profit, marketer profit are recomputed. Attempt override on an order with a paid refund — verify rejection. Audit log shows old/new unit_price. |
| **Commit message** | `Add guarded order price override` |
| **Push policy** | User-initiated only. |

---

## C. Commit message conventions

All finance-phase commits follow the existing project convention (imperative present tense, brief summary, no scope prefix). Examples:

- `Document finance architecture roadmap` *(Phase 0)*
- `Add cashboxes finance foundation` *(Phase 1)*
- `Add payment methods and cashbox transfers` *(Phase 2)*
- `Integrate collections with cashboxes` *(Phase 3)*
- `Integrate expenses with cashboxes` *(Phase 4)*
- `Add refunds financial lifecycle` *(Phase 5)*
- `Integrate marketer payouts with cashboxes` *(Phase 6)*
- `Add finance reports and dashboard` *(Phase 7)*
- `Add fiscal controls for finance transactions` *(Phase 8)*
- `Add guarded order price override` *(Phase 9)*

Each commit ends with the `Co-Authored-By:` trailer used in prior phases, when the work is paired with Claude Code.

---

## D. Non-negotiables

These apply to every phase. A PR that violates any of them must not merge.

1. **Phase 0 commits docs only.** No model, migration, controller, page, route, or permission seeder change in the Phase 0 commit.
2. **Phase 1 does not integrate with collections, expenses, or refunds.** The Cashboxes module is self-contained. Subsequent phases extend.
3. **Phase 9 (Order Price Override) does not begin** until Phases 1 through 8 are merged and stable. Specifically: refunds must exist and the over-refund guard must be in place, or the price-override block-if-paid-refund cannot be enforced.
4. **No hard delete on any finance table.** Reviewers reject PRs that introduce a `DELETE` button or endpoint on cashboxes, cashbox_transactions, cashbox_transfers, payment_methods, or refunds.
5. **No denormalized balance on cashboxes.** Reviewers reject PRs that introduce a `current_balance` column.
6. **Server-side permission gating on every prop.** Reviewers reject PRs where a permission-locked metric leaks via the Inertia payload to an unauthorized user. The Phase 1 dashboard `latest_orders` pattern is the gold standard.
7. **DB::transaction wrapping for every multi-row financial write.** Partial state must not be possible.

---

## E. Go / no-go before each phase

Before opening the prompt for Phase N (N ≥ 2), verify:

- [ ] The previous phase's commit is in `main` and pushed.
- [ ] The previous phase's tests pass on a fresh clone.
- [ ] No unrelated changes are sitting in the working tree.
- [ ] If Phase N depends on a Phase ≤ N−1 feature being usable (e.g. Phase 5 depends on Phase 1 cashboxes existing), verify the dependency is live in `main` — not just merged into a side branch.
- [ ] The phase's documentation in `PHASE_0_FINANCE_ROADMAP.md` still matches the team's understanding. If it doesn't, update the doc first and commit.

---

## Cross-references

- The overall plan → [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
- Architecture and principles → [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
- Schema → [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md)
- Permissions matrix → [PHASE_0_PERMISSIONS_AND_ROLES.md](PHASE_0_PERMISSIONS_AND_ROLES.md)
- Risk register → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
