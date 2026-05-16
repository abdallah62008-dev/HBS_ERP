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

- [ ] **(P-1)** Brand dropdown lists active brands sorted alphabetically.
- [ ] **(P-1)** Brand filter on the product index list returns matching products only.
- [ ] **(P-1)** Editing a product's brand and saving updates the brand on the product row (live FK).
- [ ] **(P-1)** Editing a product's brand AFTER an order has shipped does NOT change the brand on the order item snapshot (see §13 for the snapshot check that proves this).
- [ ] **(P-1)** Creating a brand from a quick-create modal works and selects it immediately.
- [ ] **(P-1)** Deleting an active brand is blocked if any product references it.

---

## 4. Channel SKU entry (P-1)

- [ ] **(P-1)** Channel SKU tab shows existing rows: `channel`, `sku`, `is_active`.
- [ ] **(P-1)** Adding a Channel SKU with `(variant_id, channel)` matching an existing row → 422 (unique constraint).
- [ ] **(P-1)** Adding a Channel SKU for a non-existent variant → 422.
- [ ] **(P-1)** Channel enum accepts only: Internal / Amazon / Noon / Jumia / Website / Supplier / Other.
- [ ] **(P-1)** Marking a row `is_active = false` hides it from any "find by channel SKU" lookup.
- [ ] **(P-1)** Searching the product index by Channel SKU finds the product.

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
- [ ] **(O-1)** "Save" submits + redirects to Order Show.
- [ ] **(O-1)** "Save & Add New" submits + reloads Order Create with an empty form.
- [ ] **(O-1)** "Save & Duplicate" submits + reloads Order Create with the same customer + line items pre-filled.
- [ ] **(O-1)** "Save & Print Label" submits + opens the shipping label PDF in a new tab.
- [ ] **(O-1)** "Save as Draft" submits with `is_draft = true`, no stock reservation, no inventory movement, redirects to Order Show with a "Draft" badge.

### Validation
- [ ] Submitting without a customer → 422.
- [ ] Submitting without any line items → 422.
- [ ] Submitting a line item with `quantity = 0` → 422.
- [ ] Submitting a line item with `unit_price` below master selling price (no override permission) → 422.
- [ ] Submitting a line item with `unit_price` below `minimum_selling_price` (with override permission) → ApprovalRequest created; order moves to `Pending Approval` state.
- [ ] Submitting with a stock shortage on any line item → 422 with the specific item flagged.

### Warnings (don't block)
- [ ] **(O-1)** Negative marketer profit per line → red highlight + warning banner.
- [ ] **(O-1)** Unusually high quantity (> 100) → warning banner.
- [ ] **(O-1)** Customer's previous orders show a recent return → soft warning.
- [ ] **(O-1)** No `cost_price` on a product line → "Missing cost" warning.

### Side effects
- [ ] Stock reserved (`inventory_movements` row of type Reserve) for each non-draft line.
- [ ] `orders.marketer_profit` and related fields populated when a marketer is attached.
- [ ] `display_order_number` accessor reads `order_number-entry_code`.
- [ ] Audit log row added with `action = orders.created`.

---

## 8. Phone validation (O-2)

- [ ] **(O-2)** Customer Create with EG country code, `local_phone = 1012345678` → accepted, `normalized_phone = +201012345678`.
- [ ] **(O-2)** Customer Create with SA country code, `local_phone = 512345678` → accepted, `normalized_phone = +966512345678`.
- [ ] **(O-2)** Customer Create with EG country code, `local_phone = 123` → 422.
- [ ] **(O-2)** Editing a customer's country code re-validates the local_phone against the new country's rules.
- [ ] **(O-2)** Duplicate `normalized_phone` across customers is blocked (or warns; matches the existing dedupe behaviour).
- [ ] **(O-2)** WhatsApp link button on Customer Show opens `https://wa.me/<normalized_phone>` in a new tab.

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
