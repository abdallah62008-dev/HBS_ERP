# Returns — ERP Workflow Roadmap

> **Companion to:** [README.md](README.md) · [RETURNS_ARCHITECTURE_OVERVIEW.md](RETURNS_ARCHITECTURE_OVERVIEW.md)
> **Purpose:** Phased plan to evolve the Returns module from its current as-built state into a complete ERP/OMS-standard return management surface. Each phase is independently committable.

Each phase below is **independently shippable**. Phase 0 is documentation only; later phases are code work. Phases marked **"needs business decision"** must not be implemented before the listed open question is answered by the operations side.

---

## Phase 0 — Documentation

| Field | Value |
|---|---|
| **Goal** | Establish a reviewed Returns architecture before further code work. |
| **Scope** | The nine Markdown files in `docs/returns/`. |
| **Files** | New: `docs/returns/*.md`. |
| **Migrations** | None. |
| **Tests** | None. |
| **Risks** | Minimal — documentation. Risk is staleness; mitigated by referencing the docs from PRs in later phases. |
| **Commit strategy** | One commit: `Document returns module architecture and roadmap`. |
| **Go / no-go** | Operations stakeholder confirms the recommended lifecycle and the decision queue in §"Open business questions". |

---

## Phase 1 — UX Clarity & Active / Resolved Queue

| Field | Value |
|---|---|
| **Goal** | Make the Returns surface unambiguous: from an order, the operator can clearly reach its return; on the Returns index, "where did my Closed return go?" is answered at a glance. |
| **Scope** | Backend props + frontend tabs. NO behaviour change to inventory, refunds, order statuses, or permissions. |
| **Files likely to change** | `app/Http/Controllers/OrdersController.php` (`existing_return` prop on `show()` + `edit()`), `app/Http/Controllers/ReturnsController.php` (default-Active filter, `?status=resolved`, counts, `view_mode`), `resources/js/Pages/Orders/Show.jsx` (Manage Return button + amber banner), `resources/js/Pages/Orders/Edit.jsx` (wire the existing hint to `existing_return.id`), `resources/js/Pages/Returns/Index.jsx` (Active/Resolved/All tabs + per-status chips + counts + helper notice + dynamic subtitle). |
| **Migrations** | None. |
| **Tests** | New `tests/Feature/Returns/ReturnsIndexVisibilityTest.php` covering: default excludes Restocked + Closed; `?status=resolved` shows only resolved; `?status=all` includes everything; per-status filters still work; counts payload (active/resolved/all + per-status) agrees with seeded fixture; counts respect `q` search; `view_mode` prop reflects the filter. Existing `tests/Feature/Orders/ReturnFromStatusChangeTest.php` extended with a test that Orders/Show + Orders/Edit expose `existing_return.id`. |
| **Risks** | Low. The semantic shift on `/returns` (default no longer means "show everything") is the only user-visible behaviour change; mitigated by the prominent Active/Resolved/All tabs. |
| **Commit strategy** | Two commits — see "Commit grouping" below. |
| **Go / no-go** | All return + order tests green. Manual: an operator who closes a return on `/returns/{id}` can reach it again via the Resolved tab within one click. The order's Show page banner names the return id and links to it. |
| **Status** | 🟡 **pending uncommitted** at the time of writing Phase 0. |

**Commit grouping** (recommended for Phase 1):

1. `Add Manage Return action and active-only Returns queue` — `OrdersController`, `Orders/Show.jsx`, `Orders/Edit.jsx`, `ReturnFromStatusChangeTest.php` + the FIRST cut of `ReturnsController`, `Returns/Index.jsx`, `ReturnsIndexVisibilityTest.php`.
2. `Add Returns index tabs, counts, and resolved view` — the second-cut UX refinement on `ReturnsController` + `Returns/Index.jsx` + extended tests.

---

## Phase 2 — Return Intake & RMA Standards

| Field | Value |
|---|---|
| **Goal** | Tighten the "open a return" front door. The atomic flow (`Order → Returned`) is the strong path; the direct `/returns/create` flow exists for back-office corrections but should warn loudly that the modal is preferred. Add optional RMA reference if requested. |
| **Scope** | UX polish on Return Create. Optional: an `rma_number` nullable column for external integration. **No** lifecycle changes. |
| **Files likely to change** | `resources/js/Pages/Returns/Create.jsx` (clearer copy, "Use the order's Change-status modal instead" hint when reachable), `app/Http/Controllers/ReturnsController.php` (preselected-order improvements), maybe `app/Models/OrderReturn.php` + migration if `rma_number` is added. |
| **Migrations** | If `rma_number` is approved: `add_rma_number_to_returns_table` (nullable string, unique-soft). Otherwise none. |
| **Tests** | Direct-create path tests — already partially covered. Add: duplicate-return-prevention via the direct endpoint, RMA uniqueness if added. |
| **Risks** | Low. Most of this phase is wording. The RMA column is additive. |
| **Commit strategy** | One commit per concern: `Tighten direct return-creation UX`, optionally `Add RMA number to returns`. |
| **Go / no-go** | An operator who lands on `/returns/create?order_id=X` for an order that has a return sees a clear "already has a return" notice (already present today — polish copy only). |
| **Status** | ✅ **Shipped.** No migration; `rma_number` deferred. Implemented as a display-only convention. |

### Phase 2 — as-shipped notes

- **RMA reference is display-only.** `OrderReturn::getDisplayReferenceAttribute()` returns `RET-000006` (id zero-padded to 6 digits, `RET-` prefix). The accessor is in `$appends` so every JSON response carries the field; the frontend reads `ret.display_reference` directly. Used in `Returns/Index`, `Returns/Show` header, and the `Orders/Show` return-context banner + "Manage return" tooltip.
- **No `rma_number` column.** If an external system (carrier, marketplace, accounting) ever needs a real RMA identifier, promote the accessor to a real column in a future migration phase — at that point the front-end already reads from `display_reference`, so the swap is one-line.
- **Intake-form copy was tightened, not the lifecycle.** `Returns/Create.jsx` now opens with a slate "preferred path" notice nudging operators back to the atomic flow, and the help text under every money field spells out *intent vs. commitment*: `refund_amount` is intent, `shipping_loss_amount` is intent, `notes` are operations-internal. The behaviour of every field is unchanged.
- **Duplicate-return UX is now an explicit link.** `ReturnsController::create` exposes `existing_return_id` whenever the preselected order already has a return. The page renders an "Open existing return →" button alongside the amber notice instead of leaving the operator on a dead end.
- **Single `notes` field retained.** The split into `customer_note` / `internal_note` / `warehouse_note` is deferred to a later phase — needs a real operational ask before adding columns.
- **Tests added:** `tests/Feature/Returns/ReturnIntakeTest.php` (10 tests, 81 assertions). Pins the direct-create gaps the change-status suite doesn't cover: missing-reason 422, duplicate-blocked friendly error + originals preserved, success redirect, `existing_return_id` prop wiring, no-Refund/no-cashbox on intake, and the `display_reference` format + serialisation contract.

---

## Phase 3 — Inspection Workflow

| Field | Value |
|---|---|
| **Goal** | Introduce a proper warehouse inspection step. Today inspection collapses Receive → Inspect → Decision into a single button. Phase 3 separates them so warehouses with a "receive now, inspect later" practice are supported. |
| **Scope** | Activate the currently-dormant `Received` and `Inspected` statuses; add `ReturnService::markReceived()` and rework `ReturnService::inspect()` into `markInspected($condition)` followed by `decideRestock($restockable)`. The current single-step `inspect()` becomes the fast-path that does both. |
| **Files likely to change** | `app/Services/ReturnService.php`, `app/Http/Controllers/ReturnsController.php` (new endpoints `/returns/{id}/receive`, possibly split `/inspect`), `resources/js/Pages/Returns/Show.jsx` (new buttons), `tests/Feature/Returns/`. |
| **Migrations** | None — statuses are already in the constant. |
| **Tests** | New: receive → inspect → restock-decision flow; legacy direct-inspect still works; refund eligibility honoured at each new state. |
| **Risks** | Medium. Two new endpoints, new permission slugs (`returns.receive`?), new role grants. Backward compatibility with the direct-inspect fast-path must be preserved. |
| **Commit strategy** | One commit per service+UI step: `Add receive step to return inspection`, then `Split inspect into condition and restock-decision`. |
| **Go / no-go** | The classic `Pending → inspect()` shortcut still works. The new path `Pending → Received → Inspected → Restocked` also works and writes the right audit entries. Inventory side-effects unchanged. |
| **Decision needed** | Should the receive step be mandatory or optional? Recommend optional (a backwards-compatible shortcut). |
| **Status** | ✅ **Shipped (Option A — Received is optional).** The `markInspected → decideRestock` two-step split is **deferred** — `inspect()` continues to collapse condition + restock-decision into one transition. |

### Phase 3 — as-shipped notes

- **Received is optional.** Two equivalent legal paths: the legacy `Pending → inspect()` fast-path AND the new `Pending → markReceived() → inspect()` Received-path. Behaviour after `inspect()` is identical on both.
- **`Inspected` enum value remains dormant.** Phase 3 deliberately did NOT split `inspect()` into `markInspected → decideRestock` — the operational case for two clicks instead of one wasn't strong enough. If a future phase needs the split, the enum is already present.
- **New permission slug `returns.receive`** — granted to **warehouse-agent + manager + admin**. **Not** granted to order-agent, viewer, accountant. Mirrors the existing SoD: order-agent creates the paperwork at intake; warehouse handles physical receipt and inspection.
- **Zero inventory / refund / cashbox impact.** `markReceived()` is a pure lifecycle marker. Audit log entry `action=received, module=returns` is written; nothing else.
- **Race protection.** `markReceived()` uses `lockForUpdate` and re-checks `return_status === 'Pending'` inside the transaction to defend against two operators clicking simultaneously.
- **Files shipped:** `app/Services/ReturnService.php` (+`markReceived()`), `app/Http/Controllers/ReturnsController.php` (+`markReceived` action), `routes/web.php` (+POST `/returns/{return}/receive` name=`returns.receive`), `database/seeders/{Permissions,Roles}Seeder.php` (new slug + role grants), `resources/js/Pages/Returns/Show.jsx` (Receive panel shown only on Pending with `returns.receive`).
- **Tests added:** `tests/Feature/Returns/ReturnInspectionWorkflowTest.php` — 17 tests, 78 assertions. Covers state transition, permission gating (positive + negative for warehouse / order-agent / viewer / ad-hoc no-slug), blocked-from-non-Pending, no-side-effects on inventory and finance, `inspect-from-Received` writes the correct reversal on Damaged and writes nothing on Good+restockable, legacy fast-path regression, Received visible in Index + Reports.

---

## Phase 4 — Inventory & Restock Rules

| Field | Value |
|---|---|
| **Goal** | Decide whether to keep the current optimistic-restock-on-Returned behaviour OR migrate to the more conservative restock-on-inspection model. |
| **Scope** | This is a **policy decision first, code change second**. The trade-off lives in [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md). Once decided, the code change is contained: either keep `OrderService::applyInventoryForTransition` as-is (Option A) OR move the `Return To Stock` movement out of there and into `ReturnService::inspect` (Option B). |
| **Files likely to change** | `app/Services/OrderService.php`, `app/Services/ReturnService.php`, `tests/Feature/Returns/ReturnInventoryTest.php`. |
| **Migrations** | None. |
| **Tests** | The five existing `ReturnInventoryTest` tests cover the current behaviour exactly. If Option B is chosen, those tests need updating: `bug_a_delivered_to_returned_writes_return_to_stock` becomes `delivered_to_returned_does_NOT_write_return_to_stock_until_inspection`. |
| **Risks** | **High** — this is the most behavioural-impact phase. Stock is the company's working capital; any error here is felt by every concurrent salesperson. Recommend NOT shipping Phase 4 in the same release as any other Returns change so it is reviewable in isolation. |
| **Commit strategy** | One focused commit per option: `Move return restock from status-change to inspection` (Option B). Single revertable unit. |
| **Go / no-go** | **Needs explicit business approval** before any code is written. Specifically: is the operational risk of stock-inflation-between-status-change-and-inspection worse than the operational risk of stock-being-unavailable-until-inspection-completes? |
| **Decision needed** | See "Open question 4" below. |

---

## Phase 5 — Refund & Finance Integration (Pinning)

| Field | Value |
|---|---|
| **Goal** | The Finance module already ships `Refund` with `requested → approved → paid` lifecycle (Phase 5C from `docs/finance/`). Phase 5 of Returns is about **pinning the contract** — tests that the cross-module boundary cannot regress, and documentation of the rules. |
| **Scope** | Documentation only here; the code already exists in Finance. The added value of this phase is: (a) a regression test pinning that closing a return does NOT pay a refund, (b) a test pinning that a closed finance period blocks `pay()` from a return-originated refund, (c) a written contract in `docs/returns/RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md`. |
| **Files likely to change** | `tests/Feature/Returns/` (cross-boundary pinning tests). Possibly `app/Http/Controllers/ReturnsController.php::requestRefund` to surface `RefundService` errors more cleanly. |
| **Migrations** | None. |
| **Tests** | New cross-module tests: close-return-does-not-pay-refund, finance-period-locked-blocks-pay-from-return, refund-creation-from-return-requires-refunds.create-AND-returns.view. |
| **Risks** | Low. No business behaviour change; the rules are already in code. The pinning prevents regression. |
| **Commit strategy** | `Pin return-to-refund cross-module contract`. |
| **Go / no-go** | All cross-module tests green. |

---

## Phase 6 — Replacement / Reshipment Workflow

| Field | Value |
|---|---|
| **Goal** | First-class "we sent the customer a replacement" workflow. Today this happens via a new order, with no link back to the return. Phase 6 either introduces a `Reshipped` return status that points at the replacement shipment, OR introduces a child-shipment concept on the original order. |
| **Scope** | **Needs business decision** between: (a) a Returns-side `Reshipped` status with a nullable `replacement_shipment_id`, or (b) an Orders-side child-shipment under the original order. Each has very different reporting and auditing characteristics. |
| **Files likely to change** | Significant — would touch `app/Models/OrderReturn.php` (new constant), the service layer, the index visibility logic (the Reshipped chip needs a home — likely Resolved), the role matrix, every report that filters by status, the audit log. |
| **Migrations** | If Option (a): `add_replacement_shipment_id_to_returns_table` + update `STATUSES`. If Option (b): a new `child_order_id` (or similar) on `orders` plus reporting changes. |
| **Tests** | Whole new test class — replacement workflow E2E. |
| **Risks** | **High**. Affects status enums, reports, permissions, and any external integration. |
| **Commit strategy** | Multi-commit; design doc first (a sibling of this one), then code, then UI. |
| **Go / no-go** | **Business decision required** on whether reshipment is a Returns-side state or an Orders-side workflow. Recommend a 30-minute meeting before any code is written. |
| **Decision needed** | See "Open question 6" below. |

---

## Phase 7 — Reporting & QA

| Field | Value |
|---|---|
| **Goal** | Surface returns analytics to managers. |
| **Scope** | A `/reports/returns` Inertia page (already present in the sidebar but currently a placeholder under `reports.profit` permission per route definitions). Phase 7 fills it with: return rate (by product, by category, by marketer, by customer, by time window), reasons-breakdown (which reason is rising?), product-defect rate (`product_condition='Damaged'` proportions), customer-return rate, marketer-return rate, refund exposure (sum of `refund_amount` on open returns), stock-loss snapshot (sum of `shipping_loss_amount`). |
| **Files likely to change** | `app/Http/Controllers/Reports/ReturnsReportController.php` (new), `resources/js/Pages/Reports/Returns.jsx` (new), `routes/web.php`, permission seeders if a new slug is needed. |
| **Migrations** | None. Pure read queries over existing data. |
| **Tests** | Query-correctness tests on each metric; permission tests on the report endpoint. |
| **Risks** | Low — read-only. Performance risk if the query is unindexed; covered by adding indexes on `return_status`, `product_condition`, `inspected_at` if not already present. |
| **Commit strategy** | `Add returns analytics report`. |
| **Go / no-go** | Manager + Admin can open the report and see consistent numbers against ad-hoc spot-checks. |
| **Status** | ✅ **Shipped.** Expanded the existing `/reports/returns` page in place; no new controller, no new route, no permission churn. |

### Phase 7 — as-shipped notes

- **No new controller / route / permission.** The placeholder `/reports/returns` route + `ReportsController::returns` + `ReportsService::returns` + `Reports/Returns.jsx` all pre-existed. Phase 7 expanded the service query and the page; nothing else moved.
- **Permission gate kept at `reports.profit`.** The page surfaces financial-exposure totals (`refund_amount` + `shipping_loss_amount` sums) which are sensitive enough to deserve the existing slug. Roles that can open it today (super-admin, admin, manager, viewer) are unchanged.
- **Metrics added on the service side:**
  - `totals.active` and `totals.resolved` — derived from the `Active / Resolved` queue conventions pinned in `RETURNS_LIFECYCLE_AND_STATUSES.md §8`.
  - `by_status` — zero-filled over every `OrderReturn::STATUSES` value (so missing buckets are visible, not hidden).
  - `by_condition` — zero-filled over every `OrderReturn::CONDITIONS` value (separate axis from status — an Inspected return can still be Good).
  - `top_products` — joins `returns → orders → order_items → products`, counts distinct returns AND sums returned units, limit 10.
  - `status_groups` — the same active/resolved split the index page exposes, so the frontend never hard-codes the lists.
- **Page redesign keeps refund exposure SEPARATE from shipping loss.** The previous "Refunds + losses" combined card hid two distinct money concerns (intent vs. absorbed shipping cost). Phase 7 splits them into two cards and adds a restock-rate stat alongside.
- **Tests added:** `tests/Feature/Returns/ReturnReportsTest.php` (10 tests, 96 assertions). Pins permission gating (with/without `reports.profit`, with/without `reports.view`), the prop shape (every metric), date-range filtering, and the read-only contract (no return / refund / cashbox mutation on opening the report).
- **Open question 7 — headline metric.** The page intentionally does NOT pick a single "headline" return metric — the first row shows Total / Active / Resolved / Damaged, the second row shows Refund exposure / Shipping loss / Restocked / Restock rate. If ops wants a single headline KPI, surface that on the dashboard instead of redesigning this page.

---

## Cross-cutting open questions

Numbered so they can be referenced from phase tables:

1. **Phase 2 — Should we add an RMA number to returns?** Only valuable if there's an external system (carrier, marketplace, accounting) that uses one.
2. **Phase 3 — Should the `Received` step be mandatory or an optional shortcut?** Recommend optional.
3. **Phase 3 — Who can mark a return Received?** Probably the same role as inspect (warehouse-agent). Could split.
4. **Phase 4 — Optimistic restock vs. restock-on-inspection?** The single biggest operational decision left. See [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md).
5. **Phase 5 — Should closing a return require all linked refunds to be paid (or explicitly rejected)?** Today no — a closed return may have an in-flight refund. Tighter contract would be: cannot close while any refund is in `requested` or `approved`.
6. **Phase 6 — Reshipped as a return state, or as a child-order workflow?** Most consequential phase decision. Affects reports forever after.
7. **Phase 7 — Which metric is the "headline" return metric — return rate by SKU, by customer, or refund exposure?** Drives the report's top widgets.

---

## Phase numbering convention

Phases are numbered for the Returns roadmap and intentionally start at 0 (this docs phase). They do NOT correspond to the Finance phase numbering, which is its own track. Cross-references between modules use the full "Returns Phase N" / "Finance Phase NX" prefix.

---

## Out-of-scope for the foreseeable future

To keep the surface clean, these are explicit **non-goals**:

- **Customer-facing return portal.** Today the return is opened on behalf of the customer by an internal user. A self-service portal is a Phase 8+ idea, not on the current roadmap.
- **Multi-warehouse return routing.** Returns assume a single default warehouse. Multi-warehouse return destinations would require routing logic far outside the current scope.
- **Partial returns (some items but not all).** Today the return targets the WHOLE order's items. Per-item partial returns are a Phase 8+ idea.
- **Carrier-driven return labels.** The system records `shipping_company_id` on a return but does not issue return labels.
