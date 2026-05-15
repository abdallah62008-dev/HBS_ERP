# Returns Module — Documentation Index

> **Project:** HBS_ERP (Hnawbas Operations System)
> **Folder:** `docs/returns/`
> **Current status:** **Returns Phase 0 — Documentation only.** No application code is changed by this folder.

This folder contains the architecture, workflow, and operational reference for the Returns Management module. It documents both the **current as-built behaviour** and a **recommended forward roadmap**. It is the planning equivalent of `docs/finance/` for the Returns surface.

> ⚠️ **This is documentation, not implementation.** No file in `docs/returns/` causes a behaviour change. Any code change must go through its own scoped task with explicit business approval where flagged.

---

## Authoritative current-state references

If you only have time for one document, read these in order:

| Document | When to read |
|---|---|
| [RETURNS_ARCHITECTURE_OVERVIEW.md](RETURNS_ARCHITECTURE_OVERVIEW.md) | First. Current architecture, entities, data flow, module boundaries. |
| [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) | Second. The state machine — what each status means and what's allowed in it. |
| [RETURNS_QA_CHECKLIST.md](RETURNS_QA_CHECKLIST.md) | Before any release that touches Returns. Seventeen user journeys covering the whole module. |

---

## Documents

| # | Document | Purpose |
|---|---|---|
| 1 | [RETURNS_ARCHITECTURE_OVERVIEW.md](RETURNS_ARCHITECTURE_OVERVIEW.md) | Entities, relationships, data flow, boundaries with Orders / Inventory / Finance. |
| 2 | [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) | Current statuses (`Pending`, `Received`, `Inspected`, `Restocked`, `Damaged`, `Closed`), what each means, what actions they allow, what they forbid. Plus the recommended long-term lifecycle. |
| 3 | [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md) | Phases 0 → 7 with goal, scope, files-likely-to-change, tests, risks, go/no-go for each. |
| 4 | [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md) | The current optimistic-restock-on-Returned behaviour, the alternative restock-on-inspection model, trade-offs, and the recommendation. |
| 5 | [RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md](RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md) | The wall between Returns and Finance. A return can exist without a refund; a refund is its own controlled finance action with its own approval and payment path. |
| 6 | [RETURNS_PERMISSIONS_AND_ROLES.md](RETURNS_PERMISSIONS_AND_ROLES.md) | Permission slugs (existing + recommended) and the role-to-action matrix. |
| 7 | [RETURNS_UI_UX_GUIDELINES.md](RETURNS_UI_UX_GUIDELINES.md) | Page-by-page UX patterns — Orders/Show banner, Manage Return action, Returns Index tabs + counts, Returns Show layout. |
| 8 | [RETURNS_QA_CHECKLIST.md](RETURNS_QA_CHECKLIST.md) | Manual QA journeys to run before any Returns-touching release. |

---

## As-built status — at a glance

| Aspect | Status |
|---|---|
| Order → Returned transition creates a linked `OrderReturn` atomically | ✅ shipped |
| Optimistic `Return To Stock` movement on Shipped/OfD/Delivered → Returned | ✅ shipped |
| Inspection (Good+restockable keeps the +qty, otherwise reverses) | ✅ shipped |
| `ReturnService::close()` (no inventory side effects) | ✅ shipped |
| Limited-fields edit (`refund_amount`, `shipping_loss_amount`, `notes`) | ✅ shipped |
| Refund request from a return (Phase 5C — refund lifecycle is the Finance module) | ✅ shipped |
| RBAC: `returns.view`, `returns.create`, `returns.inspect`, `returns.approve` | ✅ shipped |
| `order-agent` role granted `returns.view` + `returns.create` | ✅ shipped (`ac08d7e`) |
| Orders/Show "Manage Return" button + return-context banner | 🟡 **pending uncommitted** (see below) |
| Returns index default-Active queue + Active/Resolved/All tabs + counts | 🟡 **pending uncommitted** (see below) |

### Current pending work — uncommitted at the time of writing this doc

The following modifications exist in the working tree but have not yet been committed to `main`:

| File | What it adds |
|---|---|
| `app/Http/Controllers/OrdersController.php` | `existing_return` prop on Orders/Show + Orders/Edit |
| `app/Http/Controllers/ReturnsController.php` | Default-Active filter, `?status=resolved`, `?status=all`, counts (`active` / `resolved` / `all` / `by_status`), `view_mode` prop |
| `resources/js/Pages/Orders/Show.jsx` | Amber "Manage return" button + return-context banner |
| `resources/js/Pages/Orders/Edit.jsx` | Wired the existing "open the return" hint to `existing_return.id` (was previously broken) |
| `resources/js/Pages/Returns/Index.jsx` | Active / Resolved / All tabs with counts + per-status drill-down + helper notice + dynamic subtitle |
| `tests/Feature/Orders/ReturnFromStatusChangeTest.php` | +1 test pinning `existing_return` exposure on Show + Edit |
| `tests/Feature/Returns/ReturnsIndexVisibilityTest.php` *(new)* | 10 tests covering the default queue, resolved/all/per-status filters, counts, and view_mode |

These collectively deliver **Returns Phase 1 — UX Clarity** described in [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md). They are deliberately preserved as-is by Phase 0; Phase 0 changes no code.

---

## Planned phases

| Phase | Title | Scope summary | Status |
|---|---|---|---|
| **0** | Documentation | These docs | ✅ this folder |
| **1** | UX Clarity & Active / Resolved Queue | Manage-Return button, return banner, tabbed Returns index with counts, default-Active queue | 🟡 pending commit |
| **2** | Return Intake & RMA Standards | Create-from-order-only flow polish, optional RMA reference, reason taxonomy review | planned |
| **3** | Inspection Workflow | Stronger Received → Inspected progression, mandatory inspector, structured inspection notes | planned |
| **4** | Inventory & Restock Rules | Decision: keep optimistic restock OR migrate to restock-on-inspection | **needs business decision** |
| **5** | Refund & Finance Integration | The `requestRefund` path already shipped; this phase pins the contract + adds reconciliation tests | partial (Phase 5C from Finance) |
| **6** | Replacement / Reshipment | The "good return → replacement shipped" path. May introduce a `Reshipped` status. | **needs business decision** |
| **7** | Reporting & QA | Return rate, reasons analysis, defect rate, stock loss, refund exposure | planned |

Full per-phase detail in [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md).

---

## Conventions

- **"OrderReturn"** is the Eloquent model name. The DB table is `returns` (the SQL keyword conflict is avoided at the PHP side only).
- **"Active" / "Resolved"** describe queue views; they are NOT statuses. The underlying statuses are `Pending | Received | Inspected | Damaged` (Active) and `Restocked | Closed` (Resolved).
- **Optimistic restock** = the inventory `Return To Stock (+qty)` movement is written at the moment the order transitions to `Returned`, BEFORE the goods are physically inspected. The Good/restockable inspection later "confirms" it (no extra movement); anything else writes a reversal `Return To Stock (−qty)` to cancel the optimistic restock. See [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md) for the trade-offs and the decision register.
- **A return is not a refund.** Closing a return does **not** pay a refund. Creating a return does **not** debit any cashbox. Finance is a separate, permission-gated module. See [RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md](RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md).
