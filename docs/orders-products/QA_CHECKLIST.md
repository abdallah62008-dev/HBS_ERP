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

## 8f. Duplicate Merge Preview (C-5A — read-only)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/DuplicateMergePreviewTest.php` (11 tests). Zero migrations. Zero writes.

- [x] **(C-5A)** Customer Show duplicate alert renders a "Review →" link per duplicate row, pointing to `/customers/{source}/duplicates/{target}/preview`.
- [x] **(C-5A)** Preview page renders with side-by-side comparison: profile / phones / address / risk / WhatsApp opt-in / orders count / latest order date for both sides.
- [x] **(C-5A)** Affected-records box on each side counts orders / returns / refunds / customer_notes / customer_addresses / customer_tags.
- [x] **(C-5A)** Conflicts strip shows ONLY fields where both sides have differing non-null values. Empty/missing values silenced.
- [x] **(C-5A)** Recommended survivor heuristic: more orders → wins; tie → older `created_at`; final tie → URL `target` parameter. Highlighted with an emerald border + chip.
- [x] **(C-5A)** Warnings panel surfaces: different normalized phones (HIGH), source has active orders (MEDIUM), source has outstanding COD (MEDIUM), source has open returns (MEDIUM), source has open refunds (MEDIUM), target high risk (MEDIUM), target Blacklist/Watchlist (HIGH).
- [x] **(C-5A)** Swap source ↔ target link inverts the URL parameters — preview re-renders without any side effect.
- [x] **(C-5A)** Source = target → redirect with error message (no preview rendered).
- [x] **(C-5A)** Soft-deleted source or target → redirect to customers index with error message.
- [x] **(C-5A)** Permission gate: `customers.view` allows preview; users without it get 403.
- [x] **(C-5A)** Zero-write verification: counts on `customers`, `orders`, `returns`, `refunds`, `customer_notes`, `customer_addresses`, `customer_tags` are identical before and after the preview GET. Pinned by `preview_does_not_write_anything` test.
- [x] **(C-5A)** Footer placeholder reads "Merge execution will be available in C-5B" — no execute form rendered, no submit endpoint exists.
- [ ] **(C-5A → deferred to C-5B)** Actual merge execution — reassignment of orders/returns/refunds/notes/addresses/tags + source-row marking + audit log + timeline event on target.
- [ ] **(C-5A → deferred to C-5C)** Approval workflow on merges.
- [ ] **(C-5A → deferred)** Rollback command (depends on C-5B's `customer_merges.payload`).
- [x] **(C-5A → shipped in C-5B 2026-05-17)** Filter `merged_into_customer_id` out of the C-2 duplicate detector.
- [ ] **(C-5A → deferred until C-5B duplicates are resolved in production)** Unique constraint on `customers.normalized_phone`.

---

## 8g. Duplicate Merge Execution (C-5B)

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerMergeExecutionTest.php` (16 tests). Full regression: 580 / 580.

- [x] **(C-5B)** Merge endpoint reassigns `orders.customer_id`, `returns.customer_id`, `refunds.customer_id`, `customer_notes.customer_id`, `customer_addresses.customer_id`, `customer_tags.customer_id` to the target.
- [x] **(C-5B)** **Order snapshot columns NEVER touched** — `customer_name`, `customer_phone`, `customer_phone_secondary`, `customer_phone_whatsapp`, `customer_phone_normalized`, `customer_address`, `city`, `governorate`, `country` on `orders` retain their historical values. Pinned by the `merge_does_not_touch_order_snapshot_columns` test.
- [x] **(C-5B)** Tag union de-dups overlapping strings (no duplicate `(customer_id, tag)` pairs after merge).
- [x] **(C-5B)** Address book single-default invariant holds on target (only one `is_default=true` per customer).
- [x] **(C-5B)** Source row marked `merged_into_customer_id`, `merged_at`, `merged_by`. **NOT soft-deleted.**
- [x] **(C-5B)** `customer_merges` row persisted with affected counts + `payload` JSON (source profile snapshot + lists of affected ids per table).
- [x] **(C-5B)** Audit-log rows on both sides: `merged_out` on source, `merged_in` on target. Both reference the merge id.
- [x] **(C-5B)** Already-merged source rejected at execute (422 / session errors).
- [x] **(C-5B)** Reason shorter than 10 characters rejected at execute.
- [x] **(C-5B)** Confirmation phrase other than `MERGE` (exact case) rejected at execute.
- [x] **(C-5B)** Permission gate `customers.merge` enforced — users with only view/edit/delete are forbidden.
- [x] **(C-5B)** Cross-phone merge (source.normalized_phone ≠ target.normalized_phone) requires super-admin. Non-super-admin operators see a blocked form in the preview.
- [x] **(C-5B)** Merge runs inside a single DB transaction. Any internal failure rolls back the entire merge.
- [x] **(C-5B)** Concurrent merges on the same pair are serialized via `SELECT FOR UPDATE` on source + target (sorted id order to avoid deadlock).
- [x] **(C-5B)** C-2 duplicate detector filters `merged_into_customer_id IS NULL` so a merged-out source never resurfaces as a duplicate.
- [x] **(C-5B)** C-3 timeline emits `customer_merged_in` on the target and `customer_merged_out` on the source.
- [x] **(C-5B)** Customer Show on a merged source renders a tombstone banner pointing at the surviving customer; create-order / duplicate-last-order / edit / delete actions are suppressed.
- [x] **(C-5B)** Target's `risk_score` is recomputed after the merge via `CustomerRiskService`.
- [ ] **(C-5B → deferred to C-5C)** Approval workflow on merges.
- [ ] **(C-5B → deferred)** `php artisan customers:rollback-merge {merge_id}` command — payload is already in place.
- [ ] **(C-5B → deferred)** Cascading merge re-targeting (if A was merged into B and an operator picks A again, the service rejects rather than auto-resolving to B).
- [ ] **(C-5B → deferred until production duplicates resolved)** UNIQUE constraint on `customers.normalized_phone`.

---

## 8h. C-5B Must-Fix (M1–M6) — shipped 2026-05-17

**Shipped 2026-05-17.** Auto-tested by `tests/Feature/Customers/CustomerMergeMustFixTest.php` (25 tests).

### M1 — Write leaks on merged sources blocked
- [x] **(M1)** `POST /customers/{merged}/notes` redirects to surviving customer + audit row.
- [x] **(M1)** `POST/PUT/PATCH/DELETE /customers/{merged}/addresses/*` redirects to surviving customer.
- [x] **(M1)** `PUT /customers/{merged}` (customer edit) redirects to surviving customer.
- [x] **(M1)** `GET /orders/create?customer_id={merged}` redirects to `?customer_id={target}` with flash.
- [x] **(M1)** `POST /orders` with `customer_id = merged` returns 422 with field error on `customer_id`.
- [x] **(M1)** Every blocked write writes a `write_blocked_merged_source` audit log row with the attempted URL + HTTP method.

### M2 — Observer-skip mitigation
- [x] **(M2)** `App\Events\CustomerRecordsReassigned` event dispatched after the merge transaction commits.
- [x] **(M2)** Event carries source/target ids, merge id, affected-id lists per table (orders/returns/refunds/notes/addresses/tags), actor id.
- [x] **(M2)** Fires OUTSIDE the transaction so listeners see committed state.
- [ ] **(M2 → deferred until first subscriber lands)** Concrete listener (marketer wallet recompute / search index / n8n webhook). The event is the canonical hook for when modules need it.

### M3a — Feature flag enforcement
- [x] **(M3a)** `customer_merge_enabled` setting (boolean, default false) ships with the migration.
- [x] **(M3a)** Controller `executeMerge` rejects with audit when flag is off.
- [x] **(M3a)** Service `merge()` rejects with `RuntimeException` when flag is off (defence-in-depth).
- [x] **(M3a)** Preview page renders an info banner when flag is off; execute form is suppressed via `can_execute_merge = false`.
- [x] **(M3a)** Toggling flag without redeploy: `App\Services\SettingsService::set('customer_merge_enabled', true, 'customers', 'boolean')`.

### M3b — Field-merge policies on target
- [x] **(M3b)** `secondary_phone` (+ all normalized triple columns) copied from source when target's slot is empty.
- [x] **(M3b)** `email` copied from source when target's slot is empty.
- [x] **(M3b)** `customer_type` promoted on target when source is more restrictive (Blacklist > Watchlist > VIP > Normal). Never demoted.
- [x] **(M3b)** `risk_level` promoted on target when source is higher (High > Medium > Low). Never demoted. Numeric `risk_score` recomputed from orders after reassignment.
- [x] **(M3b)** Source's legacy `customers.notes` text converted to a `customer_notes` row on target tagged "Imported from merged customer #X".
- [x] **(M3b)** Pre-merge target profile + the applied patch persisted in `customer_merges.payload.target_profile_pre_merge` + `target_patch_applied`. Foundation for rollback.

### M4 — Tombstone stats from merge log
- [x] **(M4)** Customer Show on a merged customer sources `stats` from the latest `customer_merges` row instead of live queries.
- [x] **(M4)** Stats prop carries `from_merge_log = true`, `merge_id`, `merge_at` so the UI can switch to "Counts at time of merge" labelling.
- [x] **(M4)** UI hides cards we can't reconstruct (delivered split / COD math / AOV / outstanding) rather than rendering them as "—".

### M5 — Wrong-direction acknowledge
- [x] **(M5)** Preview ships `wrong_direction = true` when `recommended_target_id !== URL target.id`.
- [x] **(M5)** Execute form renders a prominent amber warning + an acknowledgement checkbox above the Execute button.
- [x] **(M5)** Server-side `executeMerge` rejects with field error on `wrong_direction_ack` when the checkbox wasn't ticked.
- [x] **(M5)** Rejection writes a `merge_rejected` audit row with `reason_code = wrong_direction_unacknowledged`.

### M6 — Rejected attempt auditing
- [x] **(M6)** Every rejection path writes an `audit_logs` row with `action = merge_rejected` + classified `reason_code`. Reason codes pin-list:
  - `feature_flag_off`, `wrong_confirmation`, `wrong_direction_unacknowledged`,
  - `source_equals_target`, `soft_deleted`,
  - `already_merged_source`, `already_merged_target`,
  - `short_reason`, `cross_phone_non_super_admin`, `unknown`.

---

## 8i. Order Status Transition DAG (R-11)

**Shipped 2026-05-20.** Auto-tested by `tests/Feature/Orders/OrderTransitionDagTest.php` (33 tests) + `tests/Feature/Orders/OrderShowAllowedTransitionsTest.php` (5 tests). Full regression: 643 / 643.

### Enforcement gate (commit 50bc165)
- [x] **(R-11)** `Order::ALLOWED_TRANSITIONS` declares a legal-edge set for all 13 `Order::STATUSES`.
- [x] **(R-11)** `OrderService::changeStatus` rejects an illegal jump (e.g. `New → Delivered`) with `IllegalOrderTransitionException`.
- [x] **(R-11)** The DAG gate runs BEFORE any side-effect — a rejected transition writes no `order_status_history` row, no audit log, no inventory movement.
- [x] **(R-11)** Legal transitions still pass: `Confirmed → Shipped` fast-forward, pre-ship `Confirmed / Packed → Returned`, `Shipped → Delivered`, `On Hold → Confirmed` resume.
- [x] **(R-11)** `Returned` and `Cancelled` are terminal — every outgoing transition is rejected.
- [x] **(R-11)** An unknown status (not in `STATUSES`) still throws the original `RuntimeException` — pre-R-11 behaviour preserved.
- [x] **(R-11)** `IllegalOrderTransitionException` extends `RuntimeException`, so existing controller `catch (RuntimeException | Throwable)` blocks absorb it — no 500s.

### Frontend dropdown (commit 2ff838b)
- [x] **(R-11)** `OrdersController::show` ships an `allowed_transitions` Inertia prop = `Order::ALLOWED_TRANSITIONS[$order->status] ?? []`.
- [x] **(R-11)** The `Orders/Show` Change Status dropdown renders only legal targets + the current status (no-op baseline); illegal jumps never appear.
- [x] **(R-11)** The `allowed_transitions` prop never contains an illegal jump (pinned across 6 statuses) and never contains the current status.
- [x] **(R-11)** A terminal order (`Returned`) → `allowed_transitions = []` → the dropdown shows only the current status.
- [x] **(R-11)** The existing `Returned` permission / one-return-per-order filter is preserved on top of the DAG filter.

### Legacy audit & follow-ups
- [x] **(R-11 PR-3)** Dev `order_status_history` audited — 11 pre-DAG illegal rows (dev test data) accepted as historic noise; the gate is forward-only and never replays history.
- [x] **(R-11)** `ShippingController` verified R-11-safe — guards `=== 'Confirmed'` + `catch (Throwable)`; no change required.
- [ ] **(R-11 → deferred)** Audit-log rejected transition attempts (`action = status_change_rejected`).
- [ ] **(R-11 → deferred)** Filter the `Orders/Edit` status dropdown (the server gate already covers it).
- [ ] **(R-11 → deferred)** Explicit backward / corrective transitions (the DAG is forward-only).
- [ ] **(R-11 → re-run on production)** PR-3 audit before enabling R-11 in production.

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
