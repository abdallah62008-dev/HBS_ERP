# Implementation Phases

> Status: **DESIGN ONLY.** This document sequences every other roadmap in this folder into a delivery plan. Each phase is a self-contained release: own migration window, own QA pass, own rollback.

---

## 0. How to read this document

- **Phase code** = stable identifier referenced everywhere else (`P-1`, `O-4`, etc.). `P-` = product-side; `O-` = order-side; bare numbers (`Phase 4`, `Phase 8`) = existing project phase numbers from older specs.
- **Risk** = blast radius if a deploy goes wrong. `Low` = additive; `Medium` = touches write paths; `High` = data migration / column drop.
- **Depends on** = phases that must ship first. Pulling a later phase forward without its prerequisite breaks history.
- **Effort** = rough order-of-magnitude in dev-days. Includes migration + service + UI + tests, not docs.

---

## 1. Phase 0 — Documentation (current)

| Field | Value |
|---|---|
| Code | Phase 0 |
| Risk | None (design only) |
| Depends on | — |
| Effort | 1–2 dev-days |
| Status | In progress |

### Scope
- The 15 documents in this folder.
- No code, no migrations, no schema changes.
- Existing `docs/` files referenced but not edited.

### Exit criteria
- All 15 docs land on `main`.
- Reading-order in `README.md` matches actual file list.
- Each "Should" / "Later" item is traceable from `README.md` → roadmap doc → existing code reference.

### Why this is its own phase
- Captures consensus before implementation. Future phases reference these docs in PR descriptions and migration headers.
- Avoids "what did we agree?" loops when phases pick up months apart.

---

## 2. Phase P-1 — Brand + Channel SKU Foundation

| Field | Value |
|---|---|
| Code | P-1 |
| Risk | Low (additive only) |
| Depends on | Phase 0 |
| Effort | 3–5 dev-days |
| Status | **Shipped 2026-05-17** |

### Shipped
- 3 additive migrations: `brands`, `products.brand_id` FK, `product_channel_skus`.
- 2 models: `Brand`, `ProductChannelSku`. Relationships wired into `Product` + `ProductVariant`.
- Brand admin CRUD at `/brands` + sidebar entry under Inventory & Products.
- Product Create/Edit: Brand dropdown + Quick-Brand modal; Channel SKU repeater (variant × channel × external SKU/barcode/URL/notes/active).
- Product Show: Brand badge + Channel SKUs table.
- Product Index: Brand filter + Brand column.
- Product index search extended to variant SKU/barcode + channel external SKU/barcode (EXISTS sub-queries).
- Tests: `tests/Feature/Products/ProductBrandAndChannelSkuTest.php` (22 tests).
- Full regression: 438 tests pass.

### Deviations from §2 plan
- **`products.edit_brand` / `products.edit_channel_sku` slugs not added.** P-1 uses existing `products.create`/`products.edit` per the brief's "avoid adding new permission slugs unless already necessary." Add slugs in a follow-up phase when separation-of-duties demand surfaces.
- **Order Create product search (`/orders/products/search`) NOT extended.** Hot-path; the existing (name/sku/barcode) contract stays. The extension is queued for a later phase.
- **Channel ENUM stored as VARCHAR(32), not a DB enum.** SQLite portability for tests + future marketplace additions become code-only changes. App-level validation enforces the allowed set via `ProductChannelSku::CHANNELS`.
- **`external_barcode` added** to `product_channel_skus` beyond what the design doc specified. Some marketplaces print their own barcode; ops needs both fields for reconciliation.

### Scope
- `brands` table + `products.brand_id` FK + Brand admin CRUD page.
- `product_channel_skus` table + Channel SKU sub-form on Product Edit.
- Brand seeder; existing products fall in `Unknown` bucket.
- Brand & Channel SKU filters added to product index list.

### Migrations
1. `create_brands_table`
2. `add_brand_id_to_products` (nullable)
3. `create_product_channel_skus_table`
4. Seed an initial `Unknown` brand and assign every product to it.

### Why first
- Low risk, blocks several reports + the eventual Phase O-4 snapshot work, and gives ops an immediate UX win (brand filter / channel SKU search).
- No write-path change inside Order Create — Phase P-1 leaves orders untouched.

### Exit criteria
- New `brands` and `product_channel_skus` tables exist with seed data.
- Product Edit page shows Brand dropdown + Channel SKU tab.
- Product index list filters by brand.
- All existing tests still pass.

---

## 3. Phase O-1 — Order Create UX

| Field | Value |
|---|---|
| Code | O-1 |
| Risk | Low–Medium (touches the most-used screen) |
| Depends on | Phase 0 |
| Effort | 4–6 dev-days |
| Status | **Shipped 2026-05-17** (Draft variant deferred) |

### Shipped
- 4 of 5 save variants:
  - **Save** → Order Show (existing).
  - **Save & Add New** → fresh `orders.create`.
  - **Save & Duplicate** → `orders.create?duplicate_from={id}` with customer + items + non-financial fields pre-filled by the controller; cost/profit fields deliberately omitted from the prefill.
  - **Save & Print Label** → `shipping-labels.print` if user has `shipping.print_label`; falls back to Order Show otherwise with a flash hint.
- `submit_action` field validated by `StoreOrderRequest`. Allowed values: `save`, `save_add_new`, `save_duplicate`, `save_print_label`. Default `save`. Unknown values 422.
- Per-line below-min red border + inline `min X` hint.
- Pre-submit warnings panel (non-blocking) — low stock, below-min selling price, negative marketer profit, missing cost (marketer attached), low margin < 10% (marketer attached).
- Rolling `productCache` so warnings work for products added beyond the initial 25-product seed.
- Duplicate-source banner with a back-link to the source order.
- 16 feature tests in `tests/Feature/Orders/OrderCreateSaveActionsTest.php`. Full regression: 454 / 454 pass.

### Deferred from plan
- **Save as Draft.** Adds `Draft` to the `orders.status` enum → requires a migration; also requires `OrderService::createFromPayload` to branch on whether to reserve stock, run marketer-wallet accrual, run duplicate detection, hide from the default index, plus enable a `Draft → New` promote action. Out of scope for a UX phase.
- **`orders.is_draft` boolean column + Draft filter** — same dependency.
- **8 new permission slugs.** None added in O-1. The "Save & Print Label" button uses the existing `shipping.print_label` slug.
- **Per-line "Reason for price override" textarea** + `orders.override_price` slug. Awaits Phase 8 approval workflow.
- **Missing-phone / unusual-quantity warnings.** Phone normalization lands in O-2; unusual-quantity heuristic deferred until a defensible threshold exists.

### Migrations
- **None.** O-1 ships zero migrations.

### Why second
- Doesn't depend on schema changes outside `orders`; the warnings consume data that's already available.
- Lifts the daily-use friction immediately.

### Exit criteria
- Order Create page renders all save variants behind the right permission gates.
- Draft orders don't appear in main order list by default.
- Warnings render in the UI but don't block submission unless flagged in the matrix.

---

## 4. Phase O-2 — Phone Normalization & WhatsApp Readiness

| Field | Value |
|---|---|
| Code | O-2 |
| Risk | Low (additive + a one-time backfill) |
| Depends on | Phase 0 |
| Effort | 3–4 dev-days |
| Status | **Shipped 2026-05-17** |

### Shipped
- 2 additive migrations:
  - `add_phone_normalization_to_customers` — adds primary + secondary phone triples on `customers` (each: `country_code`, `local_phone`, `normalized_phone`). Indexes on the two `normalized_phone` columns.
  - `add_customer_phone_normalized_to_orders` — adds `customer_phone_normalized` (indexed) snapshot column on `orders`.
- `App\Services\PhoneNormalizationService` — country rules in PHP constants (EG/SA/AE/IQ). Pure, DB-free, idempotent.
- `StoreCustomerRequest` / `UpdateCustomerRequest` validate `country_code` against the allow-list and reject phones that don't normalize (422 with field-level errors).
- `CustomersController` populates the triple on store/update and extends the index search to match `normalized_phone` / `secondary_normalized_phone`.
- `OrderService::createFromPayload` snapshots `customer->normalized_phone` to `orders.customer_phone_normalized`.
- `DuplicateDetectionService` Rule 1 (same primary phone, recent) queries the indexed `customer_phone_normalized` column first; falls back to the legacy raw-string compare for un-backfilled orders.
- `Customer::whatsappUrl()` accessor — returns `https://wa.me/...` (or null when opted-out).
- Customer Create/Edit form: country code dropdowns + "Saved as `<E.164>`" hint + explicit WhatsApp opt-in checkbox.
- Customer Show: E.164 display + 🟢 WhatsApp click-to-chat link.
- `php artisan customers:backfill-phones` (idempotent, supports `--country`, `--dry-run`, `--limit`). Side-effect: backfills `orders.customer_phone_normalized` for the customer's pre-O-2 orders.
- Tests: 23 unit (service) + 8 store/update (controller) + 6 backfill + 3 dedupe = **40 new tests**. Full regression: **494 / 494**.

### Deviations from plan
- **`customer_addresses` phone triple was NOT added.** That table is rarely populated today; the doc-spec lists it but the value proposition for O-2 is on `customers` where every record lives. Defer until address-book usage justifies it.
- **`countries` table extension** (`dial_code`, `national_prefix`, etc.) NOT added. PHP constants in `PhoneNormalizationService::COUNTRY_RULES` deliver the same functionality with zero schema impact. Promote to a DB lookup later if the rule set grows.
- **Unique index on `normalized_phone` NOT added.** Backfill would surface real duplicates and block inserts. The doc-spec dedupe-merge workflow (Phase 8) is the right gate before locking down uniqueness.
- **`customers.merge_duplicate` permission slug NOT added** — wait for the merge UI to ship.

### Migrations
- 2 additive migrations + 1 artisan command. No `migrate:fresh` needed.

### Exit criteria — verified
- ✅ Customer Create / Edit form uses the country picker + local input pair.
- ✅ Validation rejects malformed numbers per country rule (test: `creating_customer_with_invalid_phone_returns_422`).
- ✅ Backfill idempotent (test: `backfill_is_idempotent`).

---

## 4a. Phase C-1 — Customer Show Quick Actions

| Field | Value |
|---|---|
| Code | C-1 |
| Risk | Low (controller-props + UX only) |
| Depends on | O-1 (duplicate_from flow), O-2 (whatsappUrl) |
| Effort | 1–2 dev-days |
| Status | **Shipped 2026-05-17** |

### Shipped
- `CustomersController::show` ships 5 new props: `latest_order_id` (latest non-Cancelled, non-Need-Review order), `total_orders`, `whatsapp_url` (from `Customer::whatsappUrl()`), `can_create_order`, `can_view_orders`.
- `OrdersController::create` reads `?customer_id=<id>` and ships `prefill_customer` (cost/profit-free slim payload). When both `customer_id` and `duplicate_from` are present, `duplicate_from` wins.
- `OrdersController::index` accepts a `customer_id` filter and ships `filter_customer` for the UI pill. Non-numeric / unknown ids are handled defensively (cast to 0 / null filter_customer).
- `Pages/Customers/Show.jsx` action bar: **+ Add Order** (primary) / **View Orders** / **Duplicate Last Order** / **🟢 WhatsApp** / **Edit** / **Delete**. Each button is a `Link` (or `<a>`) — never a POST.
- "View all N →" shortcut under the recent-orders panel when there are more orders than the 20-row cap.
- `Pages/Orders/Create.jsx` hydrates customer slot on mount from `prefill_customer` (items remain empty). Green "Creating order for {name}" banner.
- `Pages/Orders/Index.jsx` renders an indigo "Showing orders for {name} ✕" pill when the filter is active. Clicking ✕ removes the `customer_id` filter while preserving status/q.
- Tests: 17 new (6 + 6 + 5) in `tests/Feature/Customers/CustomerShowQuickActionsTest.php`, `tests/Feature/Orders/OrderCreateCustomerPrefillTest.php`, `tests/Feature/Orders/OrderIndexCustomerFilterTest.php`. Full regression: **511 / 511**.

### Deferred items (per C-0 review)
- ⛔ **Stats cards** (10 aggregates) — Phase C-2.
- ⛔ **Customer activity timeline** — Phase C-3.
- ⛔ **Customer notes table + UX** — Phase C-4.
- ⛔ **Address book UX** — Phase C-4 (`customer_addresses` table already exists; just unused in app code).
- ⛔ **Duplicate customer detection / merge** — Phase C-5.
- ⛔ **WhatsApp message templates / automation** — Phase 7 / external (n8n).
- ⛔ **Visual risk score (color bar) + recommendation copy** — Phase C-2.

### Migrations
- **None.** C-1 ships zero migrations and zero new permission slugs.

### Exit criteria — verified
- ✅ Customer Show renders the 4 quick-action buttons behind the correct permissions and data conditions.
- ✅ Add Order opens Order Create with the customer pre-filled (no auto-create).
- ✅ Duplicate Last Order excludes Cancelled / Need Review orders.
- ✅ View Orders narrows the index by `customer_id` (real filter, not phone search).
- ✅ WhatsApp button uses `Customer::whatsappUrl()` and respects the opt-out flag.

---

## 4b. Phase C-2 — Customer 360 Stats Cards

| Field | Value |
|---|---|
| Code | C-2 |
| Risk | Low (read-only aggregates) |
| Depends on | C-1 (Customer Show props), O-2 (normalized_phone for duplicate alert) |
| Effort | 1 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- `CustomersController::show` ships 3 new props:
  - `stats` — 12 aggregate fields computed in one indexed SUM-CASE query (`customer_id` index): `total_orders`, `delivered_orders`, `returned_orders`, `cancelled_orders`, `total_spent` (Delivered only), `outstanding_balance` (estimated; sum of `cod_amount` on open collections), `cod_orders`, `cod_collected_orders`, `cod_success_rate`, `return_rate`, `average_order_value`, `last_order_at`.
  - `duplicate_customers` — non-deleted customers sharing the current `normalized_phone`, excluding self. Capped at 5; read-only — merge is C-5.
  - `risk_recommendation` — pure mapping of `risk_breakdown.level` → operator copy (Low → "Normal order flow." / Medium → "Review recent history before shipping." / High → "Confirm carefully before shipping or COD."). **Order flow remains unblocked** regardless of level.
- New private helpers on `CustomersController`: `customerStats()`, `duplicateCustomers()`, `riskRecommendation()`. No new service class needed.
- `Pages/Customers/Show.jsx`:
  - Compact 5-column stat-card grid above the profile/risk row. Money uses the system currency symbol; rates show as percentages; null values render as "—".
  - Amber duplicate-customer alert at the top of the page when `duplicate_customers.length > 0`. Each row links to the other customer.
  - Risk panel gains a one-line recommendation chip under the score.
- Tests: 9 new in `tests/Feature/Customers/Customer360StatsTest.php`. Full regression: **520 / 520**.

### Math contracts pinned by tests
- `total_spent` = Delivered orders' `total_amount` only — never Returned / Cancelled.
- `average_order_value` = `total_spent / delivered_orders`. Null when delivered = 0.
- `cod_success_rate` = `(Collected + Settlement Received) / (cod_amount > 0)`. Null when no COD orders.
- `return_rate` = `Returned / (Delivered + Returned)`. Null when both are zero.
- `outstanding_balance` = `SUM(cod_amount)` where `collection_status IN ('Not Collected','Partially Collected','Pending Settlement','Rejected')` — labeled "Estimated outstanding" in the UI because the pre-O-5 single-COD model can't reflect multi-payment splits.

### Migrations / permissions
- **None.** C-2 ships zero migrations, zero new permission slugs, zero `.env` changes, zero package installs.

### Deferred items (per C-0 roadmap)
- ⛔ Customer activity timeline — **C-3 (shipped 2026-05-17)**.
- ⛔ Customer notes (new table) — **C-4**.
- ⛔ Address book UX — **C-4** (`customer_addresses` table exists; unused in app code).
- ⛔ Duplicate merge workflow — **C-5**.
- ⛔ WhatsApp message templates / n8n automation — **Phase 7**.
- ⛔ Visual risk colour-bar — punted until ops asks; the text chip is enough for now.

---

## 4c. Phase C-3 — Customer Activity Timeline

| Field | Value |
|---|---|
| Code | C-3 |
| Risk | Low (read-only event merge from existing indexed tables) |
| Depends on | C-2 (Customer Show extension surface) |
| Effort | 1 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- `CustomersController::show` ships a new `timeline` prop. Helper `customerTimeline()` merges 4 source queries (each capped at 30 rows) and slices the merged list to 30 events newest-first.
- Sources included:
  - `customers.created_at` → `customer_created`
  - `orders WHERE customer_id = ?` (indexed) → `order_created`
  - `order_status_history WHERE order_id IN (recent_order_ids)` → `order_status_changed`
  - `returns WHERE customer_id = ?` (indexed) → `return_created`
  - `refunds WHERE customer_id = ?` (indexed) → `refund_created` (+ `refund_approved` / `refund_rejected` / `refund_paid` when their respective indexed timestamps are non-null)
- Event payload shape: `{ id, type, title, subtitle, timestamp, actor_name, tone, href, meta }`. Tone drives the colour of the timeline dot; href is server-rendered only for the safe routes (`orders.show`, `returns.show`).
- `Pages/Customers/Show.jsx` renders an "Activity timeline" panel under the recent-orders table — vertical list with type chip + colour dot + title (linked when safe) + actor + subtitle + relative timestamp.
- Tests: 12 in `tests/Feature/Customers/CustomerActivityTimelineTest.php`. Full regression: **532 / 532**.

### Deferred items (per C-0 review)
- ⛔ **`audit_logs` events** — `record_type + record_id` is indexed, but pulling events for all of a customer's related orders requires an unbounded IN-list which has unclear performance characteristics at scale. Revisit once a per-customer audit view is needed.
- ⛔ **`shipments` events** — keeps initial timeline focused; order_status_changed already covers the shipping lifecycle at the order level.
- ⛔ **`collections` events** — duplicates order info today (one-to-one with order). Re-evaluate after O-5 multi-payment.
- ⛔ **WhatsApp events** — no source data today.
- ⛔ **Refund show route link** in event href — refund show route permission scope wasn't fully verified for every role; defer until the Phase 5 finance permission audit completes.
- ⛔ Customer notes timeline events — depends on C-4 schema.

### Migrations / permissions
- **None.** C-3 ships zero migrations and zero new permission slugs.

### Exit criteria — verified
- ✅ Timeline ships from Customer Show.
- ✅ Always emits a `customer_created` anchor event.
- ✅ Order, return, refund events appear when their data exists.
- ✅ Sorts newest-first.
- ✅ Capped at 30 events.
- ✅ Other customers' events do not leak.
- ✅ Order events link to Order Show.
- ✅ Each source query is bounded (≤ 30 rows) and uses an indexed column.

---

## 4d. Phase C-4A — Customer Notes Foundation

| Field | Value |
|---|---|
| Code | C-4A |
| Risk | Low (additive — one new table) |
| Depends on | C-3 (timeline integration) |
| Effort | 0.5–1 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- 1 additive migration: `create_customer_notes_table` — `id`, `customer_id` (FK cascade), `note` (text), `is_internal` (bool default true), `created_by` (FK users nullOnDelete), timestamps + composite index `(customer_id, created_at)`.
- New `App\Models\CustomerNote` (mirrors `OrderNote`).
- `Customer::customerNotes()` relation — deliberately NOT named `notes()` to avoid collision with the legacy `customers.notes` text column.
- `CustomersController::show()` ships a `customer_notes` prop (latest 50) + `can_delete_customer` permission flag.
- 2 new endpoints: `POST /customers/{customer}/notes` (gated by `customers.edit`), `DELETE /customers/{customer}/notes/{note}` (gated by `customers.delete`). **Zero new permission slugs** — reuses existing `customers.edit` / `customers.delete`.
- Defence-in-depth on delete: the URL `customer_id` must match the note's `customer_id`, else 404 — prevents wrong-row deletion across tabs.
- Audit-log entries on `created` and `deleted` events (module = `customers`).
- C-3 timeline gains a 6th source: `customer_note_added` events with body preview (80 chars) and internal/external metadata.
- `Pages/Customers/Show.jsx`: new Notes panel between the duplicate alert and the stats grid. Inline add-form (textarea + internal-only checkbox + Save). Per-note row with internal/external badge, actor, timestamp, and delete (confirm) when the user has `customers.delete`.
- Tests: 9 in `tests/Feature/Customers/CustomerNotesTest.php`. Full regression: **541 / 541**.

### Deferred items (per C-4 review)
- ⛔ **Address book UX (C-4B)** — uses existing `customer_addresses` table; UX-only.
- ⛔ **Address selector on Order Create** — defer until O-3 districts ship the full address tree.
- ⛔ **Notes-as-blocking-warning in Order Create** — defer (current C-1 prefill banner is enough).
- ⛔ **Pinned notes** — out of scope until ops asks.
- ⛔ **Note categories / tags** — out of scope.
- ⛔ **Customer-facing (external) notes UI** — `is_internal` flag is in place but the customer-facing surface doesn't exist.
- ⛔ **Soft-delete on notes** — hard delete only in C-4A; audit log captures the action.
- ⛔ **Duplicate merge workflow** — Phase C-5.
- ⛔ **WhatsApp message templates / n8n automation** — Phase 7.

### Migrations / permissions
- **1 additive migration. 0 new permission slugs.**

### Exit criteria — verified
- ✅ Notes panel renders on Customer Show.
- ✅ Notes are scoped to the correct customer; no cross-customer leak.
- ✅ Empty notes rejected at validation.
- ✅ Delete endpoint refuses to delete a note belonging to a different customer.
- ✅ Notes show up in the C-3 timeline as `customer_note_added` events with body preview.
- ✅ Existing `customers.notes` text column untouched (back-compat preserved).

---

## 4e. Phase C-4B — Customer Address Book UX

| Field | Value |
|---|---|
| Code | C-4B |
| Risk | Low (UX-only on existing schema) |
| Depends on | C-4A (Customer Show extension surface) |
| Effort | 0.5–1 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- **Zero migrations.** Uses the existing `customer_addresses` table (`id, customer_id, address, city, governorate, country, is_default, created_by, updated_by, timestamps`) — schema unchanged.
- `CustomerAddress` model gains `createdBy()` / `updatedBy()` relations for actor display in the Show panel + the timeline.
- `CustomersController::show()` ships `customer_addresses` (default-first, then newest by id) + `can_manage_addresses` permission flag.
- 4 new endpoints + routes:
  - `POST /customers/{customer}/addresses` → `customers.addresses.store` (gated by `customers.edit`)
  - `PUT /customers/{customer}/addresses/{address}` → `customers.addresses.update` (gated by `customers.edit`)
  - `PATCH /customers/{customer}/addresses/{address}/default` → `customers.addresses.default` (gated by `customers.edit`)
  - `DELETE /customers/{customer}/addresses/{address}` → `customers.addresses.destroy` (gated by `customers.delete`)
- **Zero new permission slugs.**
- Single-default invariant enforced inside a DB transaction. Setting a new default clears the prior default; first-address-becomes-default auto-promotion.
- Cross-customer mutation (address belongs to a different customer than the URL) returns 404 — defence-in-depth on all 4 endpoints.
- **Legacy sync:** `customers.default_address` / `city` / `governorate` / `country` are updated whenever the default address changes, so existing read paths (Order Create prefill, reports) keep working without code changes.
- Delete-the-default behaviour: most-recent remaining address is automatically promoted to default. If none remain, the legacy `customers.default_address` value is intentionally preserved (no auto-clear) so downstream reports don't lose history.
- Idempotent backfill: `php artisan customers:backfill-addresses` (with `--dry-run` and `--limit=<n>` flags). Copies each customer's `default_address` + city/governorate/country into a `customer_addresses` row with `is_default = true`, only when the customer has zero existing address rows.
- C-3 timeline gains a 7th source: `customer_address_added` events. Title is "Default address added" or "Address added"; subtitle includes city/governorate/country + an 80-char body preview.
- `Pages/Customers/Show.jsx`: new Address book panel beneath the Notes panel. Add-form (address textarea + city/governorate/country inputs + default checkbox + Save), per-row list with default badge, Set default / Edit (inline) / Delete actions. Inline `AddressEditRow` component keeps each editing row's state local.
- Tests: 12 in `tests/Feature/Customers/CustomerAddressBookTest.php`. Full regression: **553 / 553**.

### Deferred items (per C-4 review)
- ⛔ **Address selector on Order Create** — defer until the combined C-4B/O-3 phase ships the district FK + full address tree. Order Create still uses `customers.default_address` today.
- ⛔ **`district_id` FK, `street`, `landmark`, `label`/`type` columns** — Phase **O-3**.
- ⛔ **Soft-delete on addresses** — hard delete only; audit log captures actions.
- ⛔ **Update / default-change timeline events** — the current row-only schema cannot store update history without fabrication. C-4B emits the `customer_address_added` event only.
- ⛔ **Duplicate merge workflow** — Phase **C-5**.
- ⛔ **WhatsApp message templates / n8n automation** — Phase 7.

### Migrations / permissions
- **0 additive migrations. 0 new permission slugs.**

### Exit criteria — verified
- ✅ Customer Show renders the address book panel under the Notes panel.
- ✅ Add / edit / set-default / delete actions are gated by existing customers.* slugs.
- ✅ First address auto-defaults; `customers.default_address` stays in sync.
- ✅ Single-default invariant holds across set-default and update paths.
- ✅ Cross-customer mutation attempts return 404.
- ✅ Backfill command is idempotent and supports `--dry-run` and `--limit`.
- ✅ Address-added events surface in the C-3 timeline.
- ✅ Legacy `customers.default_address` is preserved when the last address is deleted (no auto-clear).

---

## 4f. Phase C-5A — Duplicate Merge Preview (read-only)

| Field | Value |
|---|---|
| Code | C-5A |
| Risk | Very Low (zero writes — pure read view) |
| Depends on | C-2 (duplicate alert) |
| Effort | 0.5 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- New route `GET /customers/{source}/duplicates/{target}/preview` → `customers.duplicates.preview`. Gated by existing `customers.view`. **Zero new permission slugs.**
- New `CustomersController::previewDuplicateMerge()` method + helpers: `slimCustomerSummary()`, `customerRelatedCounts()`, `buildMergeConflicts()`, `recommendMergeTarget()`, `buildMergeWarnings()`. All read-only.
- Validation: source ≠ target (redirect with error), neither soft-deleted (redirect with error). `merged_into_customer_id` validation deferred until C-5B introduces the column.
- New `Pages/Customers/MergePreview.jsx` — read-only side-by-side comparison page. Shows: per-side profile + risk + WhatsApp opt-in + related-records counts (orders / returns / refunds / notes / addresses / tags), conflict strip with per-field policy, warnings panel, recommended-survivor highlight (emerald), swap source↔target link, "Coming in C-5B" footer placeholder.
- Customer Show duplicate alert gains a per-row "Review →" link to the preview page.
- Recommended-target heuristic: more orders → wins; tie → older `created_at`; final tie → target route parameter.
- Warnings emitted (read-only flags):
  - `cross_phone` (HIGH) — different `normalized_phone` on the two sides.
  - `source_active_orders` (MEDIUM) — Pending Confirmation / Confirmed / Ready to Pack / Packed / Ready to Ship / Shipped / Out for Delivery.
  - `source_outstanding_balance` (MEDIUM) — `cod_amount > 0` AND `collection_status IN ('Not Collected','Partially Collected','Pending Settlement','Rejected')`.
  - `source_open_returns` (MEDIUM) — return_status `Pending` / `Received` / `Inspected`.
  - `source_open_refunds` (MEDIUM) — refund status `requested` / `approved`.
  - `target_high_risk` (MEDIUM) — `risk_level = 'High'`.
  - `target_restricted_type` (HIGH) — `customer_type IN ('Blacklist','Watchlist')`.
- Tests: 11 in `tests/Feature/Customers/DuplicateMergePreviewTest.php` including a hard zero-write verification that counts every customer-related table before and after the GET and asserts identical row counts. Full regression: **564 / 564**.

### Zero-write guarantee
- The `preview_does_not_write_anything` test counts rows in `customers`, `orders`, `returns`, `refunds`, `customer_notes`, `customer_addresses`, `customer_tags` before and after the preview GET. Any drift fails the test. This is the contractual gate that protects financial / order data from accidental mutation while C-5B is in design.

### Migrations / permissions
- **Zero migrations. Zero new permission slugs.**

### Deferred items
- ⛔ **Merge execution (C-5B)** — actual reassignment of orders/returns/refunds/notes/addresses/tags + source-row marking + `customer_merges` log table. Needs migration + new `customers.merge` permission slug.
- ⛔ **Approval workflow (C-5C)** — wire into Phase 8 `ApprovalRequest`. Only if ops needs a second pair of eyes.
- ⛔ **Rollback command** — relies on the `customer_merges.payload` snapshot which C-5B introduces.
- ⛔ **`merged_into_customer_id` filter on the C-2 duplicate detector** — needs the C-5B column. Until then, an already-merged customer can theoretically still surface as a duplicate (irrelevant pre-C-5B since no rows are marked merged).
- ⛔ **Unique constraint on `normalized_phone`** — final cleanup step once operators have run merges and resolved existing duplicates. Document as a future migration.

### Exit criteria — verified
- ✅ Preview page loads.
- ✅ Source ≠ target enforced.
- ✅ Affected-record counts cover all 6 related tables.
- ✅ Conflicts computed only when both sides have differing non-null values.
- ✅ Warnings include all 7 categories listed above.
- ✅ Recommended target follows the orders → age heuristic.
- ✅ Zero writes verified by before/after row counts.
- ✅ Permission gate uses `customers.view`.
- ✅ C-2 duplicate alert exposes the Review link.

---

## 4g. Phase C-5B — Duplicate Merge Execution

| Field | Value |
|---|---|
| Code | C-5B |
| Risk | **High** (financial / order reference rewiring) — mitigated by transaction + audit + tests |
| Depends on | C-5A (preview surface) |
| Effort | 1 dev-day |
| Status | **Shipped 2026-05-17** |

### Shipped
- **2 additive migrations:**
  - `create_customer_merges_table` — `id, source_customer_id, target_customer_id, merged_by, reason, affected_*_count (6 counts), payload (json), created_at` + 3 indexes (source / target / actor).
  - `add_merged_fields_to_customers` — `merged_into_customer_id (FK nullOnDelete), merged_at, merged_by (FK nullOnDelete)` + index on `merged_into_customer_id`.
- **1 new permission slug:** `customers.merge`. Granted to Admin (via the "all minus 3" rule) and Super Admin (bypass). Manager intentionally NOT granted — separation of duties on financial / order reference rewiring.
- New `App\Services\CustomerMergeService` — **single writer** for the entire merge transaction. Locks both customer rows (`lockForUpdate` with sorted ids to prevent deadlock), re-checks invariants under the lock, then reassigns + tombstones + audits.
- Reassignment scope:
  - `orders.customer_id` → target (snapshot columns NEVER touched).
  - `returns.customer_id` → target.
  - `refunds.customer_id` → target.
  - `customer_notes.customer_id` → target.
  - `customer_addresses.customer_id` → target. Source defaults flattened if target already has a default. Single-default invariant re-enforced after the move.
  - `customer_tags` → **union** behaviour. Source rows that duplicate a target tag string are deleted; non-overlapping rows are reassigned.
- Source row marked `merged_into_customer_id` / `merged_at` / `merged_by`. **NOT soft-deleted** — stays visible as a read-only tombstone.
- Target risk score recomputed via `CustomerRiskService::calculate()` after reassignment.
- `customer_merges` row persisted with affected counts + a `payload` JSON containing the source profile snapshot and the lists of affected ids per table (foundation for a future rollback command).
- 2 audit_logs rows: `customers.merged_out` on source + `customers.merged_in` on target. Both reference the `customer_merges.id`.
- C-2 duplicate detector now filters `merged_into_customer_id IS NULL` so a merged-out source never resurfaces as a duplicate of new arrivals.
- C-3 timeline emits `customer_merged_in` on the target and `customer_merged_out` on the source.
- New endpoint `POST /customers/{source}/duplicates/{target}/merge` → `customers.duplicates.merge`, gated by `customers.merge`. Requires `reason` (min 10 chars) + `confirmation = 'MERGE'` (typed exactly).
- `Pages/Customers/MergePreview.jsx` gains an execute form (renders only when the operator has `customers.merge`). Cross-phone merges show a blocking message for non-super-admin operators (the form stays disabled).
- `Pages/Customers/Show.jsx` renders a tombstone banner when the customer is a merged source, and suppresses the create-order / duplicate / edit / delete actions (the row is historical).
- Tests: **16 in `CustomerMergeExecutionTest`**. Full regression: **580 / 580**.

### Snapshot-column contract (pinned)
The service **never** writes to `orders.customer_name`, `orders.customer_phone`, `orders.customer_phone_secondary`, `orders.customer_phone_whatsapp`, `orders.customer_phone_normalized`, `orders.customer_address`, `orders.city`, `orders.governorate`, `orders.country`. These are the historical record at order-create time. Pinned by the `merge_does_not_touch_order_snapshot_columns` test.

### Cross-phone merge policy
A merge between customers with different `normalized_phone` values is allowed but escalates to **Super Admin** only. Server-side enforced inside `CustomerMergeService::preflight()` (RuntimeException → 422). UI surfaces the block reason in the preview's execute form.

### Migrations / permissions
- **2 additive migrations.**
- **1 new permission slug** (`customers.merge`). Re-run `php artisan db:seed --class=PermissionsSeeder` and `php artisan db:seed --class=RolesSeeder` on deploy.

### Deferred items
- ⛔ **Approval workflow** — Phase 8 `ApprovalRequest` integration. Reserved for **C-5C** when ops needs a second pair of eyes.
- ⛔ **Rollback command** — `php artisan customers:rollback-merge {merge_id}`. The `customer_merges.payload` carries everything needed; the command itself can land in a follow-up.
- ⛔ **Unique constraint on `customers.normalized_phone`** — final cleanup once operators have used C-5B to resolve existing duplicates. Document as a future migration.
- ⛔ **Cascading merge re-targeting** — if A was merged into B, and a new operator picks A as source for a new merge, the service rejects (already merged). A future enhancement could auto-resolve A → final survivor. Out of scope for C-5B.

### Exit criteria — verified
- ✅ Reassignment moves orders / returns / refunds / notes / addresses / tags to target.
- ✅ Snapshot columns on orders preserved.
- ✅ Tag union de-dups overlapping strings.
- ✅ Single-default invariant on target addresses preserved.
- ✅ Source marked `merged_into_customer_id` (NOT soft-deleted).
- ✅ `customer_merges` row + audit logs on both sides.
- ✅ Already-merged source rejected.
- ✅ Empty / short reason rejected.
- ✅ Wrong confirmation phrase rejected.
- ✅ Permission gate uses new `customers.merge` slug.
- ✅ Cross-phone merge requires super-admin.
- ✅ C-2 duplicate detector excludes already-merged rows.
- ✅ C-3 timeline emits merge events on both sides.
- ✅ Source Show renders tombstone banner; create/edit/delete actions suppressed.

### C-5B Must-Fix follow-up (M1–M6) — shipped 2026-05-17

The architecture review surfaced six high-/medium-severity items beyond the original C-5B happy path. All shipped as a single follow-up batch.

| ID | Fix |
|---|---|
| **M1** | Block writes on merged sources. New `CustomersController::blockIfMerged()` helper short-circuits store/destroy on `customer_notes`, `customer_addresses` (store/update/setDefault/destroy), the customer `update` route, the Order Create `?customer_id=` prefill, AND the Order Store payload. Every block also writes a `write_blocked_merged_source` audit log row. |
| **M2** | Synthetic `App\Events\CustomerRecordsReassigned` event dispatched after the merge transaction commits. Carries source/target ids, merge id, affected-id lists per table, actor id. Listeners hook here instead of relying on Eloquent `updated` events (which mass `update()` skips). No subscribers today — the event is the canonical hook for future modules (marketer wallet recompute, search index sync, n8n webhooks, cache invalidation). |
| **M3a** | Feature flag `customer_merge_enabled` (boolean, default false) enforced at three layers: controller preflight, service preflight, frontend banner. Service throws `RuntimeException('Customer merge workflow is disabled.')` if execution is attempted with the flag off. Preview page renders an info banner explaining the disabled state. |
| **M3b** | Field-merge policies on target now actually run: secondary phone/email copied when target empty; `customer_type` promoted to more-restrictive (Blacklist > Watchlist > VIP > Normal); `risk_level` promoted to higher (High > Medium > Low); source legacy `customers.notes` text converted to a structured `customer_notes` row on target tagged "Imported from merged customer #X". Pre-merge target profile + the applied patch land in `payload.target_profile_pre_merge` + `payload.target_patch_applied` for future rollback. |
| **M4** | Tombstone stats pivot — when `customer.merged_into_customer_id` is non-null, `CustomersController::show()` sources stats from the latest `customer_merges` row (`affected_*_count`) instead of live queries. New `from_merge_log` flag on the prop; UI swaps the panel for a "Counts at time of merge" block showing only the reliable fields. |
| **M5** | Wrong-direction guard. Server-side check in `executeMerge`: when `recommended_target_id !== target.id`, `wrong_direction_ack` is required. UI surfaces a prominent amber warning + an acknowledgement checkbox above the Execute button when the operator is on the wrong side of the recommendation. |
| **M6** | Every rejection path writes an `audit_logs` row with `action = merge_rejected` and a `reason_code` (one of `feature_flag_off`, `wrong_confirmation`, `wrong_direction_unacknowledged`, `source_equals_target`, `soft_deleted`, `already_merged_source`, `already_merged_target`, `short_reason`, `cross_phone_non_super_admin`, `unknown`). Detects probing + recurring operator confusion. |

### Must-fix testing
- 25 additional tests in `tests/Feature/Customers/CustomerMergeMustFixTest.php` cover M1–M6 across all surfaces.
- Pre-existing `CustomerMergeExecutionTest` was patched to enable the feature flag in `setUp()` and bake `wrong_direction_ack` into the shared payload helper.
- Full regression: **605 / 605** (was 580 before must-fix work; +25 new tests, 0 regressions).

### Deployment additions
- New step required: `php artisan tinker --execute="App\Services\SettingsService::set('customer_merge_enabled', true, 'customers', 'boolean');"` to enable the workflow. Defaults to OFF — workflow ships dark.
- Operators can flip the flag without redeploy by setting `customer_merge_enabled` via the settings UI (or the artisan one-liner above).

---

## 4h. Phase R-11 — Order Status Transition DAG

| Field | Value |
|---|---|
| Code | R-11 (Order_P0_Doc Phase 1) |
| Risk | Medium — touches the core `OrderService::changeStatus` write path; mitigated by an additive gate + full-suite regression |
| Depends on | — (additive; the 13 `Order::STATUSES` already existed) |
| Effort | ~1 dev-day |
| Status | **Shipped 2026-05-20** |

> R-11 is the first item delivered from the **Order_P0_Doc Phase 1 plan** (the
> order-system review backlog) — not the Customer 360 series. It is filed as
> `4h` only to keep the surrounding section numbers stable.

### Shipped
- **Commit `50bc165`** — DAG enforcement:
  - `Order::ALLOWED_TRANSITIONS` — explicit map of every legal status edge, keyed by all 13 `Order::STATUSES`. Pre-ship warehouse sub-states (`Confirmed`, `Ready to Pack`, `Packed`, `Ready to Ship`) are optional refinements — an operator may fast-forward straight to `Shipped`. Pre-ship fulfilment states may also be marked `Returned` directly. `Returned` / `Cancelled` are terminal. `On Hold` / `Need Review` are pause overlays.
  - `Order::isLegalTransition($from, $to)` — boolean helper reading the DAG.
  - `App\Exceptions\IllegalOrderTransitionException` — typed; carries order id / number + from/to statuses + `allowedTargets()`. Extends `RuntimeException` so existing `catch` blocks absorb it (the gate is additive).
  - `OrderService::changeStatus` — DAG gate inserted after the unknown-status guard and **before** any side-effect (shipping checklist, inventory, history row, audit log). An illegal jump throws before any write.
  - `tests/Feature/Orders/OrderTransitionDagTest.php` — 33 tests: DAG structure, every legal/illegal edge, terminal states, gate wiring, and the no-side-effect-on-rejection guarantee.
  - Regression-surfaced fix: `tests/Feature/Returns/ReturnInventoryTest.php` had a latent stale-`$order` bug (two `changeStatus` calls passed an in-memory `Confirmed` instance instead of `$order->fresh()`); corrected to `->fresh()`.
- **Commit `2ff838b`** — frontend dropdown filter:
  - `OrdersController::show` ships an `allowed_transitions` Inertia prop = `Order::ALLOWED_TRANSITIONS[$order->status] ?? []`.
  - `Orders/Show.jsx` — the Change Status modal's `availableStatuses` memo is re-based on the DAG: `[current status as no-op baseline, ...allowed_transitions]`, de-duped, with the existing `Returned` permission / one-return rule preserved. Illegal jumps never render.
  - `tests/Feature/Orders/OrderShowAllowedTransitionsTest.php` — 5 Inertia-prop tests.

### Defense-in-depth (two layers)
| Layer | Mechanism |
|---|---|
| UX | The Change Status dropdown renders only legal options — an illegal jump cannot be selected. |
| Enforcement | `OrderService::changeStatus` throws `IllegalOrderTransitionException` for any bad transition that still arrives (bypassed UI / stale tab / API client). |

The dropdown filter does NOT replace the server gate — both stay.

### DAG edges — inferred
The source `Order_P0_Doc` enumerates the 13 statuses but not the edges. The matrix was reconciled against `ShippingController` and the existing Returns test fixtures so it rejects no transition the system already performs — notably the `Confirmed → Shipped` fast-forward and pre-ship `→ Returned`.

### Migrations / permissions
- **None.** R-11 is pure application logic — no schema change, no new permission slug.

### PR-3 legacy audit
- Dev DB scan: 32 `order_status_history` rows, **11** historical transitions violate the new DAG (`New → Ready to Ship`, `Returned → Shipped`, `Ready to Ship → Delivered`) — all dated 10–16 May 2026, dev test data. Accepted as historic noise: the gate is **forward-only** and never replays history. Re-run the audit on production before enabling there.

### Follow-up — ShippingController
The audit traced the `New / Pending Confirmation → Ready to Ship` rows to `ShippingController::assign` (carrier-assignment auto-advance). The controller **already** guards `$order->status === 'Confirmed'` and wraps every `changeStatus` call in `catch (Throwable)`, so post-R-11 it surfaces a clean flash error rather than a 500. No controller change required — verified by reading `ShippingController` + `OrdersController` (both catch `RuntimeException` / `Throwable`).

### Deferred items
- ⛔ **Backward / corrective transitions** — the DAG is forward-only. If operators need an explicit "undo" (e.g. `Packed → Ready to Pack`), add the edge in a follow-up.
- ⛔ **Audit-logging rejected attempts** — `changeStatus` does not write an audit row when it rejects an illegal jump. Recommended (`action = status_change_rejected`) to detect UI bugs / probing; deferred.
- ⛔ **Edit-page dropdown** — `Orders/Edit.jsx` still lists all `STATUSES`; only `Orders/Show.jsx` was filtered. The server gate covers it; the UX polish is deferred.

### Exit criteria — verified
- ✅ `ALLOWED_TRANSITIONS` covers all 13 statuses; every target is a known status.
- ✅ An illegal jump (e.g. `New → Delivered`) throws `IllegalOrderTransitionException` before any side-effect.
- ✅ A rejected transition writes no `order_status_history` row.
- ✅ Legal transitions accepted — incl. `Confirmed → Shipped` fast-forward and pre-ship `→ Returned`.
- ✅ Terminal `Returned` / `Cancelled` reject all outgoing transitions.
- ✅ Unknown status still throws the original `RuntimeException` (pre-R-11 behaviour preserved).
- ✅ `Orders/Show` dropdown renders only legal targets + the current-status baseline.
- ✅ Full regression: **643 / 643** (33 new DAG tests + 5 new dropdown tests; the 3 stale-instance failures in `ReturnInventoryTest` surfaced by the gate were fixed; 0 regressions).

---

## 5. Phase P-2 — Pricing UX

| Field | Value |
|---|---|
| Code | P-2 |
| Risk | Low–Medium |
| Depends on | Phase 0 |
| Effort | 4–6 dev-days |
| Status | Should |

### Scope
- Two-way VAT calculator (exclusive ↔ inclusive) on Product Edit.
- Margin calculator next to selling price (shows margin % + EGP).
- Warning banners for below-cost / below-min / unusual margin.
- Marketer profit preview UI polish on Order Create (negative-profit red, source tag).
- Per [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md) + [MARKETER_PRICING_AND_PROFIT_ROADMAP.md §9](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md).

### Migrations
- None — UI-only phase, consumes existing fields.

### Why after O-1
- O-1 ships the warning infrastructure on Order Create. P-2 reuses it on Product Edit.
- Stand-alone otherwise.

### Exit criteria
- VAT calculator round-trip exclusive → inclusive → exclusive returns input value to 2 decimals.
- Margin display matches `(selling - cost - VAT) / selling`.
- All four warnings appear when their thresholds are crossed.

---

## 6. Phase O-3 — Districts & Address Hierarchy

| Field | Value |
|---|---|
| Code | O-3 |
| Risk | Low |
| Depends on | Phase 0 |
| Effort | 2–3 dev-days |
| Status | Should (small) |

### Scope
- `districts` table per [SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md §3](./SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md).
- `district_id` FK on `customers` and `customer_addresses` and `orders`.
- Customer / Order Create dropdowns chain country → governorate → city → district.
- Free-text columns retained; backfill matcher (free-text → FK) is opportunistic.

### Migrations
- `create_districts_table`.
- `add_district_id_to_customers`.
- `add_district_id_to_customer_addresses`.
- `add_district_id_to_orders`.

### Exit criteria
- District seed exists for top-3 cities per active country.
- Order Create cascades from country to district without page reload.
- Old orders show district blank (no migration error).

---

## 7. Phase O-4 — Order Item Snapshot Extension

| Field | Value |
|---|---|
| Code | O-4 |
| Risk | Medium (adds columns to a hot table) |
| Depends on | P-1 (brand FK must exist) |
| Effort | 3–4 dev-days |
| Status | Should |

### Scope
- `order_items` snapshot extension per [ORDER_FINANCIAL_SNAPSHOT_POLICY.md §3](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md): `brand_id_snapshot`, `category_id_snapshot`, `supplier_id_snapshot`, `vat_rate_snapshot`, `vat_inclusive_flag_snapshot`, `currency_code_snapshot`.
- Indexes per [REPORTING_ROADMAP.md §4](./REPORTING_ROADMAP.md).
- `OrderItem::populateSnapshot()` writes new columns at create.
- Optional one-time backfill for historical rows.

### Migrations
- `extend_order_items_with_snapshots` (additive columns + indexes).
- Optional `backfill_order_item_snapshots` job (operator triggered).

### Why after P-1
- `brand_id_snapshot` references the brand FK that P-1 introduces.

### Exit criteria
- New orders populate all six new columns automatically.
- A historical-accuracy test: rename a product's brand after the order, run a report on the order period — report still groups under the original brand.

---

## 8. Phase P-3 — Marketer Pricing Unification

| Field | Value |
|---|---|
| Code | P-3 |
| Risk | High (column drop + data migration) |
| Depends on | P-1, O-4 (snapshot must protect existing reports first) |
| Effort | 5–7 dev-days |
| Status | Should — but only after a dry-run audit |

### Scope
- Per [MARKETER_PRICING_AND_PROFIT_ROADMAP.md §4](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md).
- Rename A/B/D/E display labels → Silver/Gold/Platinum/VIP.
- Audit marketers using legacy `price_group_id`; migrate to `marketer_price_tier_id`.
- Drop `marketers.price_group_id` (first column-drop migration in the project).
- Simplify `MarketerPricingResolver` to one chain.
- Update Admin UI to one tier dropdown.

### Migrations
1. `rename_tier_display_labels` (data-only on `marketer_price_groups`).
2. `migrate_legacy_price_group_to_tier` (per-marketer data migration, with audit log).
3. `drop_price_group_id_from_marketers` (column drop).

### Why high risk
- First column-drop. Rollback re-adds the column AND restores values from the audit log.
- Resolver behavior change → marketer wallet math must produce identical output for at-rest orders.

### Exit criteria
- Dry-run pass on production-shaped data.
- Backup taken ≤ 24h before migration.
- 100% of marketers have `marketer_price_tier_id` set; 0 rows in `marketers` reference dropped column.
- Snapshot tests: report on a historical period before vs after migration → byte-identical results.

---

## 9. Phase P-4 — Country Pricing

| Field | Value |
|---|---|
| Code | P-4 (often called Phase 4 in older specs) |
| Risk | Medium |
| Depends on | P-1, P-2 |
| Effort | 5–7 dev-days |
| Status | Later — only when multi-country sales become real |

### Scope
- `product_country_prices` per [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md).
- Per-country selling / min / cost prices.
- Extend `marketer_product_prices` with `country_id`.
- Resolver chain extends per [MARKETER_PRICING_AND_PROFIT_ROADMAP.md §6](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md).
- `currency_code` propagation through reports.

### Migrations
- `create_product_country_prices_table`.
- `add_country_id_to_marketer_product_prices` + unique-index rebuild.

### Why "Later"
- Currently single-country (Egypt) is the operational reality. The schema is forward-compatible without this phase shipping.
- Pulling forward without business need adds resolver complexity that benefits nobody today.

### Exit criteria
- When P-4 is needed, the spec is read end-to-end before any code lands.

---

## 10. Phase O-5 — Multi-Payment

| Field | Value |
|---|---|
| Code | O-5 |
| Risk | Medium (finance-adjacent) |
| Depends on | Phase 0 |
| Effort | 6–9 dev-days |
| Status | Should — but in a low-volume window |

### Scope
- `order_payments` table per [MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md §2](./MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md).
- `OrderPaymentService` (record, mark paid, refund, cancel, outstanding balance).
- Order Show "Payments" section.
- Order Create accepts optional initial payment rows.
- 4 new `payments.*` permission slugs.
- Backfill: every existing order → one `order_payments` row of type `courier_cod`.

### Migrations
1. `create_order_payments_table`.
2. `backfill_order_payments_from_existing_orders`.

### Why finance-adjacent risk
- A drift between `orders.cod_amount`, `order_payments`, `collections`, and `cashbox_transactions` is invisible to the user but devastating at month-end.
- Run during a quiet window; reconcile against `cashbox_transactions` before going live.

### Exit criteria
- Sum of `order_payments` per existing order == old `cod_amount` (within 0.01).
- New orders create one or many `order_payments` rows as the payload dictates.
- Cashbox daily-close totals don't shift post-backfill.

---

## 11. Phase 8 — Approval Handlers + Reports Expansion

| Field | Value |
|---|---|
| Code | Phase 8 (legacy code) |
| Risk | Low–Medium |
| Depends on | P-1, O-4 (for `ordersByChannel` + `profitByBrand`) |
| Effort | 6–8 dev-days |
| Status | Should |

### Scope
- Register the 5 new approval handlers per [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md §4](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md).
- New report methods per [REPORTING_ROADMAP.md §§ 2 + 3](./REPORTING_ROADMAP.md): `topSelling`, `lowMarginProducts`, `outOfStockProducts`, `slowMovingProducts`, `profitByBrand`, `confirmationRate`, `cancellationRate`, `avgProfitPerOrder`, `ordersByChannel`.
- New permission slugs: `reports.brand`, `reports.channel`.
- New `Pages/Reports/{TopSelling,LowMargin,OOS,SlowMoving,ProfitByBrand,Confirmation,Cancellation,AvgProfit,ByChannel}.jsx` pages.

### Exit criteria
- Every new report has a feature test asserting expected aggregates against a seeded dataset.
- Reports group by the snapshot column, not the live FK — verified by the "rename brand → run report" test.

---

## 12. Phase 6 — Shipping Engine

| Field | Value |
|---|---|
| Code | Phase 6 (legacy code) |
| Risk | Medium |
| Depends on | O-3 |
| Effort | 8–12 dev-days |
| Status | Later |

### Scope
- `shipping_zones` + `shipping_zone_members` per [SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md §4](./SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md).
- Extend `shipping_rates` to optionally reference a zone.
- Carrier suggestion engine.
- `shipments.delivery_attempts` + `last_failure_reason`.
- Carrier API integrations (per carrier).

### Why "Later"
- Operators are functional with the current (country, governorate, city) granularity.
- Real value unlocks at ~3+ integrated carriers and zone-based pricing experiments.

---

## 13. Later — Cross-cutting

Items deliberately left out of the above phases. None block ops today.

| Item | Source roadmap | Trigger |
|---|---|---|
| Bundle / kit products | PRODUCT_MASTER_DATA | First reseller asks for kits |
| Serial / lot tracking | PRODUCT_MASTER_DATA | First regulated SKU lands |
| Marketplace push API | CHANNEL_SKU_AND_MARKETPLACE_MAPPING | First Amazon SP-API contract |
| Percentage-based marketer commissions | MARKETER_PRICING_AND_PROFIT | First marketer asks for non-fixed-margin |
| Scheduled email digest of reports | REPORTING | Ops requests it |
| Saved filter presets per user | REPORTING | Power-user pressure |
| Public marketer-facing KPI dashboard | REPORTING | Marketer self-service phase |
| Real-time carrier webhooks | SHIPPING_AND_LOCATION_ENGINE | First carrier offers webhooks |
| Multi-currency-per-order | MULTI_PAYMENT_AND_COLLECTIONS | First foreign currency customer |
| Per-tier approval thresholds | GOVERNANCE_PERMISSIONS_AND_APPROVALS | Operator pain at "all approvals look the same" |

---

## 14. Dependency graph (visual)

```
Phase 0 (docs)
   │
   ├─→ P-1 (Brand + Channel SKU) ─────┬─→ O-4 (Snapshot extension) ─→ P-3 (Marketer unification)
   │                                  │                                       │
   │                                  └─→ Phase 8 (Approvals + Reports)       │
   │                                                                          │
   ├─→ O-1 (Order Create UX) ────────────────────────────────────→ (independent)
   │
   ├─→ O-2 (Phone normalization) ────────────────────────────────→ (independent)
   │
   ├─→ P-2 (Pricing UX) ─────────────────────────────────────────→ (after O-1 ideally)
   │
   ├─→ O-3 (Districts) ─────────────────────────────────────→ Phase 6 (Shipping engine)
   │
   ├─→ O-5 (Multi-payment) ──────────────────────────────────────→ (independent)
   │
   └─→ P-4 (Country pricing) ────────────────────────────────────→ (Later — only when multi-country)
```

## 15. Sequencing recommendation (calendar)

Assumes one developer + one QA, ~5 effective dev-days per week.

| Week | Phase | Notes |
|---|---|---|
| 1 | Phase 0 (docs) | This phase. |
| 2 | P-1 + O-2 (parallel — independent) | Different files. |
| 3 | O-1 | Order Create UX. |
| 4 | P-2 + O-3 (parallel) | Different files. |
| 5 | O-4 | Snapshot extension. After P-1 lands. |
| 6 | O-5 | Multi-payment. Schedule the backfill on a quiet day. |
| 7 | Phase 8 (approvals + reports) | After O-4. |
| 8 | P-3 (marketer unification) | After Phase 8 has confirmed reports work on snapshots. Quiet window. |
| Later | P-4, Phase 6, all "Later" items | Trigger-driven. |

## 16. Rollback policy per phase

| Risk class | Rollback approach |
|---|---|
| Additive only (P-1, O-3, P-2 UI, O-2 backfill) | Roll back the migration; drop the new column / table. Existing rows unaffected. |
| Touches write paths (O-1, O-4, Phase 8) | Feature-flag the new behavior; rollback flips the flag off; data already written stays. |
| Column drop (P-3) | Pre-migration audit log; rollback migration re-creates the column and rehydrates from the audit log. |
| Finance-adjacent (O-5) | Full DB backup ≤ 24h pre-deploy; rollback = restore the backup + replay non-payment writes from app logs. |

## 17. References

- [README.md](./README.md) — orientation
- [ORDERS_PRODUCTS_ARCHITECTURE_OVERVIEW.md](./ORDERS_PRODUCTS_ARCHITECTURE_OVERVIEW.md) — current vs target architecture
- Every roadmap doc in this folder — referenced from the matching phase above
- [QA_CHECKLIST.md](./QA_CHECKLIST.md) — the manual QA gate each phase passes through
