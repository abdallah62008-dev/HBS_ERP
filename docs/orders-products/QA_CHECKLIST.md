# QA Checklist — Orders & Products

> Status: **DESIGN ONLY.** A living, hand-run checklist for future phases. Each phase's PR must reference the section(s) below that its change touches and confirm the listed checks pass on the test DB before merge.

---

## 0. How to use this document

- Each section corresponds to a user-facing screen or a single observable behavior.
- Items prefixed with **(P-1)**, **(O-1)** etc. only become real once that phase ships. Skip them until then.
- Items without a phase prefix apply today.
- "Pass" = matches expected behavior on the latest commit of `main`, in an incognito browser, on a clean DB seeded with `php artisan db:seed`.
- Negative tests matter as much as positive ones: an action that *should not* succeed must be tried.

---

## 1. Product creation

### Required-field checks
- [ ] Submitting an empty form shows validation errors on `name`, `sku`, `category_id`, `cost_price`, `selling_price`.
- [ ] Submitting with `selling_price` < `cost_price` shows a warning banner but does NOT block.
- [ ] Submitting with `selling_price` < `minimum_selling_price` shows a warning banner but does NOT block.
- [ ] **(P-2)** Submitting with `cost_price` = 0 surfaces a "Missing cost" warning.

### Field behavior
- [ ] SKU uniqueness enforced; duplicate SKU → 422.
- [ ] Cost price field is greyed out for users without `products.edit_cost`.
- [ ] **(P-2)** VAT calculator toggles between inclusive and exclusive cleanly; exclusive → inclusive → exclusive returns the original value within 0.01.
- [ ] **(P-2)** Margin calculator updates live as cost or selling price changes.

### Side effects
- [ ] Product appears in product index list immediately (no cache lag).
- [ ] Quick-category creation from the inline modal succeeds and the new category is selected automatically.
- [ ] Audit log row added with `action = products.created` and old/new values populated.

---

## 2. Product edit

### Permission gates
- [ ] Cost price field is greyed out unless user has `products.edit_cost`.
- [ ] Selling price field is greyed out unless user has `products.edit_price`.
- [ ] **(P-1)** Brand dropdown is greyed out unless user has `products.edit_brand`.
- [ ] **(P-1)** Channel SKU tab is hidden unless user has `products.edit_channel_sku`.
- [ ] VAT fields are greyed out unless user has `products.edit_vat`.

### Price change history
- [ ] Editing the selling price creates a `product_price_history` row with the actor's user_id, old + new value, and a `reason` string.
- [ ] Editing the selling price without a reason → 422.
- [ ] Editing the cost price creates an audit log row (history exists in `audit_logs`, not `product_price_history`).

### Unsaved-changes guard
- [ ] Modifying any input and clicking away → confirmation prompt fires.
- [ ] Modifying any input and clicking Submit → no confirmation prompt; navigation goes through cleanly.

---

## 3. Brand selection (P-1)

**Shipped 2026-05-17** — auto-tested by `tests/Feature/Products/ProductBrandAndChannelSkuTest.php`. Re-run by hand at release time for UX regressions.

- [x] **(P-1)** Brand dropdown lists active brands sorted by `sort_order` then alphabetically (active-only on Create form; all brands on the index filter).
- [x] **(P-1)** Brand filter on the product index list returns matching products only.
- [x] **(P-1)** Editing a product's brand and saving updates the brand on the product row (live FK).
- [ ] **(P-1 → deferred to O-4)** Editing a product's brand AFTER an order has shipped does NOT change the brand on the order item snapshot — this becomes verifiable once O-4 ships `brand_id_snapshot` on `order_items`. P-1 cannot test this because the snapshot column doesn't exist yet.
- [x] **(P-1)** Creating a brand from a quick-create modal works and selects it immediately (JSON path on `POST /brands`).
- [x] **(P-1)** Deleting an active brand does NOT cascade to products; the brand_id is nulled out (ON DELETE SET NULL). This is safer than blocking the delete and matches the design.

---

## 4. Channel SKU entry (P-1)

**Shipped 2026-05-17.**

- [x] **(P-1)** Channel SKUs table on Product Show is grouped by variant; rows display `channel`, `external_sku`, `external_barcode`, `external_url`, `is_active`.
- [x] **(P-1)** Adding a Channel SKU with `(variant_id, channel)` matching an existing row in the SAME form payload → 422 with a field-level error.
- [x] **(P-1)** DB unique constraint blocks the same `(variant_id, channel)` pair across requests (defence-in-depth if the validator is bypassed).
- [x] **(P-1)** A Channel SKU row whose `product_variant_id` belongs to a different product is silently dropped by the controller (defence-in-depth).
- [x] **(P-1)** Channel field accepts only: Internal / Website / Amazon / Noon / Jumia / Supplier / Other (enforced by request validator against `ProductChannelSku::CHANNELS`).
- [x] **(P-1)** "Retire" button on an existing row sets `is_active = false`. The row stays in the database; UI shows it dimmed with a Restore button.
- [x] **(P-1)** Searching the **admin product index** by external SKU or external barcode finds the product (EXISTS sub-query).
- [ ] **(P-1 → deferred)** Order Create product search (`/orders/products/search`) finds products by channel SKU. **Not implemented in P-1** — hot-path; extended in a later phase.

---

## 5. VAT calculator (P-2)

- [ ] **(P-2)** Enter `selling_price_exclusive = 100`, `vat_rate = 14` → display `selling_price_inclusive = 114.00`.
- [ ] **(P-2)** Toggle to inclusive → enter `selling_price_inclusive = 114` → display `selling_price_exclusive = 100.00`.
- [ ] **(P-2)** Change `vat_rate` while inclusive — exclusive auto-recomputes.
- [ ] **(P-2)** Toggle `tax_enabled = false` — calculator disabled, displays "VAT not applied" banner.
- [ ] **(P-2)** Round-trip exclusive → inclusive → exclusive returns input within 0.01 for typical values (10, 99, 1000, 12345).

---

## 6. Margin calculator (P-2)

- [ ] **(P-2)** With `cost = 100`, `selling = 150`, `vat_rate = 14` (exclusive) → display margin = `(150 - 100 - 21) / 150` = `19.33%` and `29.00 EGP`.
- [ ] **(P-2)** With `cost = 0` → margin shows "—" and surfaces the missing-cost warning.
- [ ] **(P-2)** Negative margin (cost > selling) → displayed in red, warning banner.

---

## 7. Order create

### Save variants (O-1)

**Shipped 2026-05-17** — backend redirects auto-tested by `tests/Feature/Orders/OrderCreateSaveActionsTest.php`. Re-run UX checks below by hand at release time.

- [x] **(O-1)** "Save order" submits + redirects to Order Show (default behaviour preserved).
- [x] **(O-1)** "Save & Add New" submits + redirects to a fresh `/orders/create` with success flash.
- [x] **(O-1)** "Save & Duplicate" submits + redirects to `/orders/create?duplicate_from={id}`. New page shows the duplicate-source banner, pre-fills the customer + items + shipping + marketer, and re-runs the duplicate-detection banner on the prefilled data.
- [x] **(O-1)** "Save & Print Label" submits + redirects to `/shipping-labels/{order}/print` when the user has `shipping.print_label`. Without the permission, the button is hidden client-side AND the server-side redirect falls back to Order Show with a flash hint (no 403).
- [ ] **(O-1 → deferred)** "Save as Draft" — adding `Draft` to the orders status enum is out of scope for O-1. The button is intentionally absent and the `save_draft` value is rejected by the request validator (verified by test).

### Validation
- [ ] Submitting without a customer → 422.
- [ ] Submitting without any line items → 422.
- [ ] Submitting a line item with `quantity = 0` → 422.
- [ ] Submitting a line item with `unit_price` below master selling price (no override permission) → 422.
- [ ] Submitting a line item with `unit_price` below `minimum_selling_price` (with override permission) → ApprovalRequest created; order moves to `Pending Approval` state.
- [ ] Submitting with a stock shortage on any line item → 422 with the specific item flagged.

### Warnings (don't block) — O-1

**Shipped 2026-05-17.** All warnings are non-blocking; submitting is always allowed (server-side `ProfitGuardService` keeps its independent block on below-min sales).

- [x] **(O-1)** Low stock: `qty > available` per line → amber row in the aggregate warnings panel + the existing inline red qty-input hint. Works for products added beyond the initial 25-product seed (productCache).
- [x] **(O-1)** Below minimum selling price: `unit_price < minimum_selling_price` → red border on the price input, inline `min X` hint under the input, red row in the aggregate warnings panel.
- [x] **(O-1)** Negative marketer profit per line (when a marketer is attached and the profit preview is loaded) → red row in the warnings panel.
- [x] **(O-1)** Missing cost (when marketer attached and per-line `cost_price <= 0`) → amber row in the warnings panel.
- [x] **(O-1)** Low margin: when marketer attached and per-line margin < 10% (UI-only threshold) → amber row in the warnings panel.
- [ ] **(O-1 → deferred)** Unusually high quantity (> 100) — heuristic threshold deferred until business sign-off.
- [ ] **(O-1 → deferred)** Customer's previous orders show a recent return — depends on a per-customer return rate lookup not yet exposed to the Create page.
- [ ] **(O-1 → deferred)** Missing cost / low margin **without** a marketer attached — would require relaxing the safe-fields contract on the product search endpoint (cost_price is intentionally stripped). Use the marketer profit preview path for now.

### Side effects
- [ ] Stock reserved (`inventory_movements` row of type Reserve) for each non-draft line.
- [ ] `orders.marketer_profit` and related fields populated when a marketer is attached.
- [ ] `display_order_number` accessor reads `order_number-entry_code`.
- [ ] Audit log row added with `action = orders.created`.

---

## 8. Phone validation (O-2)

**Shipped 2026-05-17** — backend auto-tested by `tests/Unit/Services/PhoneNormalizationServiceTest.php` (23 tests) + `tests/Feature/Customers/CustomerPhoneNormalizationTest.php` (8 tests). Re-run UX checks below by hand at release time.

- [x] **(O-2)** Customer Create with EG country code, `local_phone = 1012345678` → accepted, `normalized_phone = +201012345678`.
- [x] **(O-2)** Customer Create with SA country code, `local_phone = 0501234567` → accepted, `normalized_phone = +966501234567`.
- [x] **(O-2)** Customer Create with EG country code, `local_phone = 0312345` → 422 (length out of range).
- [x] **(O-2)** Editing a customer's country code re-runs the validator against the new country's rules (UpdateCustomerRequest mirrors StoreCustomerRequest).
- [x] **(O-2)** `country_code` outside the allow-list (`+20`, `+966`, `+971`, `+964`) → 422.
- [x] **(O-2)** WhatsApp link button on Customer Show opens `https://wa.me/<normalized_phone>` (with the leading `+` stripped) when the customer opted in.
- [x] **(O-2)** WhatsApp link is hidden when `primary_phone_whatsapp = false` (verified via `Customer::whatsappUrl()` returning null).
- [x] **(O-2)** Customer index search by E.164 form (`+201012345678`) matches a customer whose `primary_phone` was typed as `01012345678`.
- [x] **(O-2)** Order create snapshots the customer's `normalized_phone` to `orders.customer_phone_normalized`.
- [x] **(O-2)** `DuplicateDetectionService` flags two orders that normalize to the same E.164 even when typed differently.
- [x] **(O-2)** `php artisan customers:backfill-phones` is idempotent (re-runs produce "No customers to backfill") and supports `--country`, `--dry-run`, `--limit`.
- [ ] **(O-2 → deferred)** Duplicate `normalized_phone` across customers is **blocked at the DB level** — deferred. The unique index is intentionally NOT added in O-2 because pre-existing duplicates would otherwise block inserts. The dedupe service surfaces matches; the merge workflow (Phase 8) is the gate before locking uniqueness down.

---

## 8a. Customer Show quick actions (C-1)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerShowQuickActionsTest.php` (6 tests) + `tests/Feature/Orders/OrderCreateCustomerPrefillTest.php` (6 tests) + `tests/Feature/Orders/OrderIndexCustomerFilterTest.php` (5 tests).

- [x] **(C-1)** Customer Show renders an action bar with: + Add Order / View Orders / Duplicate Last Order / 🟢 WhatsApp / Edit / Delete.
- [x] **(C-1)** Each quick-action button is a `Link` (no POST) — clicking never auto-creates an order.
- [x] **(C-1)** "+ Add Order" links to `/orders/create?customer_id={id}`; Order Create pre-fills the customer slot but items remain empty.
- [x] **(C-1)** Green "Creating order for {customer name}" banner shows on Order Create when arriving via `?customer_id=`.
- [x] **(C-1)** "View Orders" links to `/orders?customer_id={id}` and uses an indexed filter, not phone search.
- [x] **(C-1)** Orders Index renders a "Showing orders for {name} ✕" pill when `customer_id` filter is active; clicking ✕ clears only that filter.
- [x] **(C-1)** "Duplicate Last Order" is hidden when the customer has no orders.
- [x] **(C-1)** "Duplicate Last Order" excludes Cancelled / Need Review orders when picking the latest source order.
- [x] **(C-1)** "Duplicate Last Order" reuses O-1's `?duplicate_from=` flow — cost / profit fields are NOT in the prefill payload.
- [x] **(C-1)** WhatsApp button is hidden when `whatsapp_url` is null (no normalized phone OR `primary_phone_whatsapp = false`).
- [x] **(C-1)** When both `customer_id` and `duplicate_from` are sent to Order Create, the duplicate_from path wins (the prefill_customer prop is null).
- [x] **(C-1)** Soft-deleted customer ids passed via `?customer_id=` render an empty prefill — no leak.
- [x] **(C-1)** Non-numeric / unknown `customer_id` on Orders Index does not crash; filter_customer prop is null.
- [x] **(C-1)** "View all N →" link appears under the recent-orders panel when total_orders > recent count.
- [x] **(C-1 → shipped in C-2 2026-05-17)** Stats cards (total / delivered / returned / cancelled / spent / outstanding / COD success / return rate / AOV / last order).
- [ ] **(C-1 → deferred to C-3)** Customer activity timeline.
- [ ] **(C-1 → deferred to C-4)** Customer notes table + UX.
- [ ] **(C-1 → deferred to C-4)** Address book UX (`customer_addresses` table exists but unused).
- [x] **(C-1 → duplicate ALERT shipped in C-2 2026-05-17; merge still deferred to C-5)** Duplicate customer alert / merge workflow.

---

## 8b. Customer 360 stats + alerts (C-2)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/Customer360StatsTest.php` (9 tests).

- [x] **(C-2)** Customer Show renders a 5-column stats-card grid: Total orders / Delivered / Returned / Cancelled / Last order / Total spent / Estimated outstanding / COD success / Return rate / Avg order value.
- [x] **(C-2)** `total_spent` counts ONLY Delivered orders. Cancelled / Returned / New / etc. do not contribute.
- [x] **(C-2)** `average_order_value` = `total_spent / delivered_orders`. Null when delivered = 0.
- [x] **(C-2)** `cod_success_rate` = (`Collected + Settlement Received`) / (`cod_amount > 0`). Null when no COD orders.
- [x] **(C-2)** `return_rate` = `Returned / (Delivered + Returned)`. Null when both zero.
- [x] **(C-2)** `outstanding_balance` is labeled "Estimated outstanding" in the UI — math is sum of `cod_amount` on open-collection orders; refined when O-5 lands.
- [x] **(C-2)** Customer with no orders renders all-zero stats; null ratios; no NaN / division-by-zero.
- [x] **(C-2)** Duplicate-customer alert renders when another non-deleted customer shares `normalized_phone`. Excludes self. Capped at 5 rows. Each row links to the other customer.
- [x] **(C-2)** Risk panel shows one-line operational recommendation under the score (Low / Medium / High). Order flow is NEVER blocked by the recommendation — it's pure guidance copy.
- [x] **(C-2 → shipped in C-3 2026-05-17)** Customer activity timeline.
- [ ] **(C-2 → deferred to C-4)** Customer notes + Address book UX.
- [ ] **(C-2 → deferred to C-5)** Duplicate customer **merge** workflow (alert is shipped; the actual merge action is C-5).
- [ ] **(C-2 → deferred to O-5)** `outstanding_balance` exactness — accurate splits require the multi-payment model from O-5.

---

## 8c. Customer activity timeline (C-3)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerActivityTimelineTest.php` (12 tests).

- [x] **(C-3)** Customer Show renders an "Activity timeline" panel below the recent-orders table.
- [x] **(C-3)** Always emits a `customer_created` anchor event (even for customers with zero orders).
- [x] **(C-3)** Emits `order_created` for each of the customer's recent orders (capped at 30) with a working link to `/orders/{id}`.
- [x] **(C-3)** Emits `order_status_changed` from `order_status_history` filtered by the customer's recent order ids. Title reads "Order X changed from A to B".
- [x] **(C-3)** Emits `return_created` events from `returns.customer_id` with a working link to `/returns/{id}`.
- [x] **(C-3)** Emits `refund_created` events from `refunds.customer_id`, plus `refund_approved` / `refund_rejected` / `refund_paid` when those indexed timestamps are non-null.
- [x] **(C-3)** Events sort newest-first; the timeline is sliced to 30 events.
- [x] **(C-3)** Other customers' events DO NOT leak into the current customer's timeline.
- [x] **(C-3)** Each event row shows: type chip · coloured dot · title · actor · subtitle · timestamp.
- [x] **(C-3)** Empty state ("No activity yet.") renders cleanly when the timeline is empty.
- [ ] **(C-3 → deferred)** Audit log events — broad IN-list against `audit_logs.record_id` has unclear scale. Revisit after a per-customer audit view spec.
- [ ] **(C-3 → deferred)** Shipment events — order_status_changed covers the shipping lifecycle at the order level for now.
- [ ] **(C-3 → deferred)** Collection events — duplicates order info pre-O-5.
- [x] **(C-3 → shipped in C-4A 2026-05-17)** Customer-note timeline events — `customer_notes` table now exists; events surface in the timeline.

---

## 8d. Customer Notes (C-4A)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerNotesTest.php` (9 tests).

- [x] **(C-4A)** Customer Show renders a "Notes" panel between the duplicate alert and the stats grid.
- [x] **(C-4A)** Add-note inline form (textarea + internal-only checkbox + Save) gated by `customers.edit`.
- [x] **(C-4A)** Empty / whitespace-only notes are rejected with a field-level error.
- [x] **(C-4A)** Each saved note displays: internal/external badge, actor name, timestamp, body (wrapping preserved).
- [x] **(C-4A)** Per-note Delete button visible only when the user holds `customers.delete`; confirmation prompt before destructive action.
- [x] **(C-4A)** Notes are scoped to the correct customer — `customer_notes` panel for customer A never shows customer B's rows.
- [x] **(C-4A)** Delete endpoint refuses to delete a note belonging to a different customer (404 on cross-customer attempts).
- [x] **(C-4A)** C-3 activity timeline picks up `customer_note_added` events with title "Internal note added" / "External note added" + body preview (≤ 80 chars).
- [x] **(C-4A)** Audit log row written on note create and note delete (module = `customers`).
- [x] **(C-4A)** Existing free-text `customer.notes` column still renders in the profile card — untouched by C-4A.
- [x] **(C-4A → shipped in C-4B 2026-05-17)** Address book UX on Customer Show.
- [ ] **(C-4A → deferred to combined C-4B/O-3)** Address selector on Order Create when arriving via `?customer_id=`.
- [ ] **(C-4A → deferred)** Pinned notes / note categories / soft-delete on notes.
- [ ] **(C-4A → deferred to C-5)** Duplicate customer merge workflow.

---

## 8e. Customer Address Book (C-4B)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerAddressBookTest.php` (12 tests). Zero migrations.

- [x] **(C-4B)** Address book panel renders on Customer Show under the Notes panel.
- [x] **(C-4B)** Add-address form (address, city, governorate, country, default checkbox) gated by `customers.edit`.
- [x] **(C-4B)** First saved address becomes the customer's default automatically.
- [x] **(C-4B)** Setting an address as default clears the prior default — single-default invariant holds inside a DB transaction.
- [x] **(C-4B)** Updating an address writes `updated_by`; promoting to default also syncs `customers.default_address` + city/governorate/country.
- [x] **(C-4B)** Inline Edit row swaps the row in place; Cancel restores the read view.
- [x] **(C-4B)** Delete is gated by `customers.delete`; deleting the default promotes the most-recent remaining address.
- [x] **(C-4B)** Deleting the last address preserves the legacy `customers.default_address` value (no auto-clear).
- [x] **(C-4B)** Cross-customer mutation (address belongs to a different customer than the URL) returns 404 on all 4 endpoints.
- [x] **(C-4B)** `php artisan customers:backfill-addresses` is idempotent, supports `--dry-run` and `--limit=<n>`, copies `default_address` + city/governorate/country with `is_default = true` only when the customer has zero existing rows.
- [x] **(C-4B)** Address-added events surface in the C-3 timeline as `customer_address_added`. Title is "Default address added" or "Address added"; subtitle includes city/governorate/country + body preview.
- [ ] **(C-4B → deferred to combined C-4B/O-3 follow-up)** Address selector on Order Create. Order Create still reads `customers.default_address`.
- [ ] **(C-4B → deferred to O-3)** `district_id` / `street` / `landmark` / `label` columns + full address tree UI.
- [ ] **(C-4B → deferred)** Update / default-change events in the timeline. Current schema stores the row only, not its history.

---

## 9. Save & Add New (O-1)

- [ ] **(O-1)** Submitting "Save & Add New" preserves: branch, source, marketer (operator option to keep or reset).
- [ ] **(O-1)** Submitting "Save & Add New" clears: customer, items, totals, notes.
- [ ] **(O-1)** Form focus lands on the customer search input after reload.
- [ ] **(O-1)** Toast confirms `Order #X created` and stays for ≥ 3s.

---

## 10. Product search

- [ ] Searching by product name returns matching products.
- [ ] Searching by master SKU returns matching products.
- [ ] **(P-1)** Searching by Channel SKU returns the product whose variant has that channel SKU.
- [ ] Searching for an inactive product is excluded by default; toggle "Include inactive" includes them.
- [ ] Searching when a brand filter is set narrows to that brand. **(P-1)**
- [ ] Search is debounced (no request fires per keystroke; one request after ~300ms idle).
- [ ] Search uses the `q` query param so refreshing the URL preserves the search term.

---

## 11. Marketer profit preview

- [ ] On Order Create, attaching a marketer + adding a line item shows a profit preview within 1 second (debounced).
- [ ] Profit preview shows per-line: selling, VAT, cost, shipping, profit.
- [ ] Profit preview shows total profit.
- [ ] **(P-2)** Negative profit lines display in red.
- [ ] **(P-2)** A "Source" tag per line reads `marketer_specific`, `tier`, or `product_default`.
- [ ] Preview is hidden for users without `orders.view_profit` permission.
- [ ] Preview matches `MarketerPricingResolver::profitForItem` output exactly when the order is finalized.

---

## 12. Multi-payment (O-5)

- [ ] **(O-5)** Order Show "Payments" section lists all `order_payments` rows for the order.
- [ ] **(O-5)** "Record payment" button creates a new `order_payments` row with `status = Pending`.
- [ ] **(O-5)** "Mark as paid" on a Pending row → `status = Paid`, `paid_at` populated, `cashbox_transactions` row posted.
- [ ] **(O-5)** "Cancel" on a Pending row removes it; no cashbox impact.
- [ ] **(O-5)** Refund on a Paid row creates a negative `order_payments` row + `refunds` row + negative cashbox entry.
- [ ] **(O-5)** Outstanding balance = `total_amount - SUM(order_payments WHERE status IN (Paid, Settlement Received))`.
- [ ] **(O-5)** `orders.cod_amount` reflects `SUM(order_payments WHERE payment_method.type = 'courier_cod')`.
- [ ] **(O-5)** A pre-Phase-O-5 order, post-backfill, has exactly one `order_payments` row of type `courier_cod`.

---

## 13. Order item snapshot (O-4)

- [ ] **(O-4)** Creating an order with a product whose brand is `BrandA` writes `order_items.brand_id_snapshot = BrandA.id`.
- [ ] **(O-4)** Renaming `BrandA` to `BrandRenamed` does NOT change the snapshot value.
- [ ] **(O-4)** Reassigning the product to `BrandB` does NOT change the snapshot value of past orders.
- [ ] **(O-4)** Reports grouping by brand use `brand_id_snapshot`, NOT `products.brand_id`.
- [ ] **(O-4)** A historical-accuracy test: deliver an order with `BrandA`, then change the brand, then run `profitByBrand` for the order's delivery date — the order still groups under `BrandA`.
- [ ] **(O-4)** `vat_rate_snapshot`, `vat_inclusive_flag_snapshot`, `currency_code_snapshot`, `category_id_snapshot`, `supplier_id_snapshot` populated on create.

---

## 14. Reports

### Existing reports (regression)
- [ ] `profit($from, $to)` returns rows only for delivered orders in the date range.
- [ ] `productProfitability($from, $to)` returns ≤ 50 rows ordered by revenue descending.
- [ ] `unprofitableProducts($from, $to)` returns only products with `revenue > 0 AND gross_profit ≤ 0`.
- [ ] `inventory()` returns ≤ 200 active products with on-hand / reserved / available columns populated.
- [ ] `shippingPerformance($from, $to)` shows per-carrier delivery rate and return rate.
- [ ] `collections($from, $to)` totals match `SUM(collections.amount_collected)` for the period.
- [ ] `marketerPerformance($from, $to)` returns per-marketer counts + net_profit.

### New reports (Phase 8)
- [ ] **(P8)** `topSelling($from, $to, orderBy='units')` orders by `SUM(quantity)` descending.
- [ ] **(P8)** `topSelling($from, $to, orderBy='revenue')` orders by revenue descending.
- [ ] **(P8)** `lowMarginProducts($from, $to, marginThreshold)` returns products with `margin% < threshold`.
- [ ] **(P8)** `outOfStockProducts()` returns only products with `available_qty <= 0` and `is_active = true`.
- [ ] **(P8)** `slowMovingProducts($daysSinceLastSale)` returns products with last sale older than `daysSinceLastSale` ago.
- [ ] **(P8)** `profitByBrand($from, $to)` groups by `brand_id_snapshot` (NOT live join — see §13 historical-accuracy test).
- [ ] **(P8)** `confirmationRate($from, $to)` = `count(Confirmed) / count(New + Confirmed)` within the range.
- [ ] **(P8)** `cancellationRate($from, $to)` = `count(Cancelled) / count(Total)`.
- [ ] **(P8)** `avgProfitPerOrder($from, $to)` = `SUM(net_profit) / count(orders)` for delivered orders.
- [ ] **(P8)** `ordersByChannel($from, $to)` groups by channel snapshot on order items.

### UI / UX
- [ ] Date range picker defaults to current month (`15d7543` standardization).
- [ ] CSV / XLSX export downloads with correct headers and 2-decimal money formatting.
- [ ] Reports require their respective `reports.*` permission slug.

---

## 15. Permissions & approvals

- [ ] Routes guarded by `permission:` middleware return 403 when the user lacks the slug.
- [ ] **(P8)** `ApprovalRequest::approved_by` cannot equal `requested_by` (server-side check).
- [ ] **(P8)** "Approve Below-Min Selling" handler applies the override and creates an audit log row.
- [ ] **(P8)** "Cancel After Shipment" handler reverses stock movement only after approval.
- [ ] **(P8)** Rejecting an approval leaves the original action unrolled-out.

---

## 16. Year-end / fiscal-year (regression)

- [ ] Editing an order inside a closed fiscal year → 422.
- [ ] Soft-deleting an order inside a closed fiscal year → blocked.
- [ ] Year-end close requires typed `CLOSE YYYY` token + backup ≤ 24h old.

---

## 17. Inventory invariants (regression — single most important set)

- [ ] `inventory_movements` is append-only; no row is updated or deleted by app code.
- [ ] On-hand quantity = `SUM(qty)` over all movement types for a product/variant/branch.
- [ ] Reserved quantity = `SUM(qty)` over Reserve and Reverse-Reserve movements.
- [ ] Available quantity = on-hand − reserved.
- [ ] Order create → Reserve movement; Order ship → Reserve → Sale (or equivalent); Order cancel pre-ship → Reverse Reserve.
- [ ] No order status transition leaves inventory in a temporarily-invalid state visible to other readers.

---

## 18. Audit log (regression)

- [ ] Every sensitive action (per [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md §2](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)) writes an `audit_logs` row.
- [ ] `old_values` + `new_values` are populated with redacted sensitive keys.
- [ ] `record_type` + `record_id` reference the affected model.
- [ ] `user_id` matches the actor.
- [ ] Approval handlers write a follow-up `audit_logs` row with `action = approvals.approved`.

---

## 19. Cross-cutting smoke tests (every release)

- [ ] `php artisan test` passes (>= 200 tests; CI green).
- [ ] `npm run build` produces no errors and no new console warnings.
- [ ] Admin login works after `php artisan migrate:fresh --seed`.
- [ ] Order Create → Save → Order Show round-trip in ≤ 5 seconds locally.
- [ ] Shipping label PDF renders Arabic correctly (no `?????`, correct letter joining, totals row LTR-clean).
- [ ] Year-end close button visible only to `year_end.manage` holders.
- [ ] Order list "Delete" button visible only to Super Admin.

---

## 20. Per-phase QA gate

When a phase ships, its PR description MUST:

1. List the sections of this checklist that the phase exercises.
2. For each listed section, paste the checked-off list from the QA pass.
3. Note any items deferred (e.g. "Phase O-4 deferred until P-1 ships in production").

This file is intentionally hand-maintained — automating it would let regressions slip through unobserved.

---

## 21. References

- [IMPLEMENTATION_PHASES.md](./IMPLEMENTATION_PHASES.md) — which phase each `(X)` prefix corresponds to
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md) — the sensitivity matrix
- [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md) — the historical-accuracy invariants used in §13
- Existing tests: `tests/Feature/Orders/`, `tests/Feature/Products/`, `tests/Feature/Categories/`
