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
- ⛔ Customer activity timeline — **C-3**.
- ⛔ Customer notes (new table) — **C-4**.
- ⛔ Address book UX — **C-4** (`customer_addresses` table exists; unused in app code).
- ⛔ Duplicate merge workflow — **C-5**.
- ⛔ WhatsApp message templates / n8n automation — **Phase 7**.
- ⛔ Visual risk colour-bar — punted until ops asks; the text chip is enough for now.

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
