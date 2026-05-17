# Order Lifecycle & Create UX

> Status: **O-1 shipped 2026-05-17.** Draft order remains design-only.

## 0. O-1 shipped scope (2026-05-17)

- ✅ `submit_action` field on Order Create form. Allowed values: `save`, `save_add_new`, `save_duplicate`, `save_print_label`. Defaults to `save` if absent. Validated by `StoreOrderRequest`.
- ✅ Backend redirect map in `OrdersController::store` → `postSaveRedirect()`:
  - `save` → `orders.show`.
  - `save_add_new` → `orders.create`.
  - `save_duplicate` → `orders.create?duplicate_from={new_order_id}`.
  - `save_print_label` → `shipping-labels.print` if user has `shipping.print_label`; falls back to `orders.show` otherwise (no 403, no lost order).
- ✅ Order Create page renders 4 save buttons (primary "Save order" + 3 secondary). "Save & Print Label" hidden when the user lacks the slug.
- ✅ `?duplicate_from={id}` prefill payload on the Create page — customer summary, items (product_id, qty, unit_price, discount), city/governorate/country, marketer_id, source, shipping_amount. Cost/profit fields are deliberately omitted from the prefill (safe for users without `orders.view_profit`).
- ✅ Duplicate-source banner at the top of the Create form linking back to the source order.
- ✅ Ownership: `authorizeOwnership()` is applied to the duplicate source; a marketer cannot snoop on another marketer's order via `?duplicate_from`.
- ✅ Pre-submit non-blocking warnings panel:
  - **Low stock** (`qty > available`) — uses the `productCache` so it works for products added beyond the initial 25-product seed.
  - **Below minimum selling price** (`unit_price < minimum_selling_price`) — red border + inline "min X" hint on the price input + entry in the aggregate panel.
  - **Negative marketer profit** — when the marketer profit preview is loaded and per-line `profit < 0`.
  - **Missing cost** — when the marketer profit preview is loaded and per-line `cost_price <= 0`.
  - **Low margin** — when the marketer profit preview is loaded and per-line margin < 10% (UI-only heuristic threshold).
- ✅ Existing server-side `ProfitGuardService` is **unchanged**. The new warnings are operator visibility; the server still blocks below-min sales unless an override is approved (per Phase 8 design).
- ⛔ **Deferred:** `Save as Draft`. Adding it would require an `orders.status` enum migration (currently New, Pending Confirmation, …, Need Review — no Draft). Plus branching in `OrderService::createFromPayload` (skip stock reservation + marketer wallet + duplicate detection). Out of scope for a UX phase.
- ⛔ **Deferred:** Per-line "Reason for price override" textarea. Depends on the `orders.override_price` permission slug (not yet seeded — design-only in §11) and the Phase 8 approval workflow.
- ⛔ **Deferred:** Missing-cost / low-margin warnings for orders **without** a marketer attached. The product search response intentionally strips `cost_price` per the safe-fields contract (commit ea3e6e5). Changing that contract is out of scope for O-1; the marketer profit preview path is used instead.

---

## 1. Today's lifecycle (existing)

```
                        ┌────── Cancelled (any time before Shipped)
                        │
                        │       ┌── On Hold (any time)
                        ▼       ▼
   New → Pending Confirmation → Confirmed → Ready to Pack → Packed
                                  │
                                  ├── reserves stock (Reserve movement)
                                  │
                                  ▼
                       Ready to Ship → Shipped → Out for Delivery → Delivered
                                          │            │              │
                                          │            │              └─ unlocks Returns
                                          │            │
                                          │            ├── deducts stock (Sale movement)
                                          │            └── (Reserve auto-released)
                                          │
                                          ▼
                                  (label printed, carrier assigned)
```

Plus:
- **Need Review** — fraud / risk hold; admin must clear.
- **Returned** — after a return cycle; restock movement depends on inspection outcome.

`OrderService::changeStatus()` is the single chokepoint. It applies inventory hooks, audit logs, marketer wallet sync, and timestamp stamps.

## 2. Draft order concept (Phase 2)

Today every order begins as `status = New`. Operators sometimes start an order, get interrupted (customer hangs up, scanner unavailable), and lose the data.

Proposed **Draft** status:

| Status | Meaning |
|---|---|
| `Draft` | Saved but not committed to operational flow. No stock reservation. No marketer wallet entry. Not in default Orders index. Not deliverable. |

Behaviour:

- `Draft → New` requires the operator to "promote" the order (button on Order Show).
- Drafts auto-purge after N days of inactivity (config setting, default 30).
- Drafts are visible to the operator who created them + admins.
- Drafts don't count against shipment or financial reports.

## 3. Save buttons (Phase 2 UI)

Today's Order Create has **one** button: "Create order". Proposed expansion:

| Button | Action | Surface where |
|---|---|---|
| **Save** | Create order, status=New, redirect to Order Show | Always |
| **Save & Add New** | Create order, redirect to fresh Order Create | Always |
| **Save & Duplicate** | Create order, redirect to new Order Create page pre-filled with the same items/customer | Always |
| **Save & Print Label** | Create order, generate label PDF, open it in new tab, redirect to Order Show | Visible when `shipping.print_label` permission |
| **Save as Draft** | Create with status=Draft, redirect to Order Show | Visible when `orders.draft.create` permission |

UI: a primary "Save" button with a dropdown chevron exposing the four variants. Backend distinguishes via a `_save_action` hidden field on the form.

## 4. Validation rules at Save time

| Rule | Severity | Source |
|---|---|---|
| At least one item | **Block** | StoreOrderRequest validation |
| Customer required (existing OR inline) | **Block** | StoreOrderRequest validation |
| All items have positive quantity | **Block** | StoreOrderRequest validation |
| All items have a unit_price | **Block** | StoreOrderRequest validation |
| Unit price < minimum_selling_price (any item) | **Block** unless user has `orders.override_price` | `ProfitGuardService` |
| Total marketer profit < zero (any item, if marketer attached) | **Warning** | `MarketerPricingResolver` |
| Below-min approval required | **Block** until approval (Phase 8) | `ApprovalRequest` of type "Approve Below-Min Selling" |
| Customer phone format invalid (post Phase 2 normalization) | **Block** | StoreOrderRequest validation |
| Duplicate customer warning (same phone + same items + < 1 day) | **Warning only**, requires `duplicate_acknowledged` flag | `DuplicateDetectionService` |
| Stock available for each item | **Warning** (allow over-sell) | `InventoryService` |

## 5. Pre-submit warnings (UI)

Surfaces inline on the Order Create page; do **not** block submission unless explicitly required.

| Warning | When | Display |
|---|---|---|
| **Low stock** | available_qty < quantity | amber badge on the item row: "Only {n} in stock" |
| **Below minimum price** | unit_price < min_selling_price | red border on price input + tooltip; if user has override permission, a reason textarea appears |
| **Missing cost** | product.cost_price is null/0 | amber banner: "Cost missing — profit cannot be computed for this line" |
| **Low margin** | margin < threshold (default 10%) | amber badge: "Margin {n}%" |
| **Negative marketer profit** | only when marketer attached | red badge in the Marketer Profit Preview panel |
| **Duplicate customer** | same phone + name + recent order | amber banner at top of form with "Acknowledge" checkbox |
| **VAT inconsistency** | vat_rate set but tax_enabled false (or vice versa) on a product | amber banner |

## 6. Multi-item order UX (existing + Phase 2 polish)

Existing today:
- Search box (server-side typeahead) — debounced.
- Category filter.
- Scan input (SKU/barcode auto-add).
- Item list with quantity, unit_price, discount edits.
- Profit preview panel (when marketer attached).

Phase 2 additions:
- Per-line "Reason" textarea for price override.
- Per-line stock badge.
- Per-line margin display.
- Inline "Remove item" with confirmation.
- "Duplicate item" action.
- Bulk action: "Apply discount to all".

## 7. Product search by SKU / channel SKU / barcode (Phase 2)

Today's `/orders/products/search` query searches: name, `products.sku`, `products.barcode`, `product_variants.sku`, `product_variants.barcode`.

After Phase 1 (Channel SKUs):

```sql
SELECT p.id, p.name, p.sku, pv.id as variant_id, pv.sku as variant_sku, pcs.channel
FROM products p
LEFT JOIN product_variants pv ON pv.product_id = p.id
LEFT JOIN product_channel_skus pcs ON pcs.product_variant_id = pv.id
WHERE p.status = 'Active'
  AND (
    p.name LIKE ? OR
    p.sku LIKE ? OR
    p.barcode LIKE ? OR
    pv.sku LIKE ? OR
    pv.barcode LIKE ? OR
    pcs.external_sku LIKE ?
  )
LIMIT 50
```

Matched rows surface the matched field as a small tag in the dropdown ("Amazon SKU", "Barcode", etc.).

After Phase 1+ (Brand):
- Additionally search `brands.name`.

After Phase 2 (Arabic-fold normalization):
- The search input lowercases + strips Arabic tashkeel + normalizes alef-hamza variants → all stored product names are normalized at write-time into a `name_search` index column. Bidirectional Arabic queries match cleanly.

## 8. Customer block on Order Create

Three modes (existing):

1. **Pick existing** — server-side typeahead by phone or name.
2. **Inline create** — full customer fields appear; saved with the order.
3. **Quick customer modal** — minimal fields (name + phone); full editing later.

Phase 2 additions:
- **Duplicate detection live**: typing a phone triggers a debounced lookup. If a customer exists, banner appears: "Found {customer name} — use this customer?".
- **Country + governorate + city dropdowns are FK-backed** (Phase 3). Free-text fallback retained for `district` + `street`.
- **WhatsApp checkbox** on the primary phone (already shipped Phase 5.8). Extends to per-order phone snapshot.

## 9. Marketer profit preview (existing — Phase 5.9)

The Order Create page calls `POST /orders/marketer-profit-preview` whenever a marketer is selected AND at least one item is present. Returns per-line profit + total.

Phase 2 polish:
- Show per-line breakdown (selling, VAT, cost, shipping, profit).
- Show negative-profit warning in red.
- Show "below tier min selling price" warning per line.

## 10. Audit & history

Every state-changing action is audit-logged today:

- Order created → `orders.created` audit row.
- Order status changed → `orders.status_changed` with old/new.
- Price overridden → `orders.price_overridden` with reason + line snapshot (Phase 2).
- Order soft-deleted → `orders.soft_deleted` (super-admin only).

## 11. Permissions involved

| Slug | Today / Future | Purpose |
|---|---|---|
| `orders.view` | today | Read order |
| `orders.create` | today | Create order |
| `orders.edit` | today | Edit non-financial fields |
| `orders.change_status` | today | Move through lifecycle |
| `orders.delete` | today (super-admin gated, Phase 5.6) | Soft-delete |
| `orders.draft.create` | **Phase 2** | Save as Draft |
| `orders.override_price` | **Phase 2** | Sell below min OR with explicit unit_price ≠ master price |
| `orders.below_min_approve` | Phase 8 (approval queue) | Approve other users' below-min override requests |

## 12. Do-now / do-later

### Phase O-1 — Must (UI + light backend)
- 5 save-action variants (Save, Save & Add New, Save & Duplicate, Save & Print Label, Save as Draft).
- Pre-submit warnings (low stock, below-min, missing cost, low margin, negative marketer profit, duplicate customer, VAT inconsistency).
- Per-line price-override reason textarea (Phase 2 — depends on `orders.override_price` permission slug + schema).

### Phase O-2 — Should
- Phone normalization for the inline-customer create path (depends on `PHONE_ADDRESS_AND_WHATSAPP_READINESS.md`).

### Phase O-3 — Should
- Product search extends to channel SKU + brand (depends on Phase 1).

### Phase O-4 — Should
- Order item snapshot extension (brand, category, supplier, vat_rate, vat_inclusive_flag).

### Later
- Draft auto-purge job.
- Mobile-first Order Create layout for warehouse handhelds.
- Voice / barcode-only entry mode.

## 13. References

- [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md)
- [PHONE_ADDRESS_AND_WHATSAPP_READINESS.md](./PHONE_ADDRESS_AND_WHATSAPP_READINESS.md)
- [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md)
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)
