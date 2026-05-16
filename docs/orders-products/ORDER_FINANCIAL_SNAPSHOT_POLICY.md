# Order Financial Snapshot Policy

> Status: **DESIGN ONLY.**

---

## 1. Core rule

**Once an `order_items` row is created, its snapshot fields must never be rewritten from the live product/variant rows.** Historical orders are forever accurate even if the underlying product is renamed, recategorized, repriced, deleted, or moved between brands.

This invariant carries forward from Phase 5 Decision D-004 (order item-level edits are LOCKED post-create; changes go through `ApprovalRequest`). This document tightens the snapshot scope.

## 2. Why snapshots matter

Without snapshots, the following queries silently drift:

- "What was the cost of SKU UAT-ALPHA in last month's orders?" → broken if `products.cost_price` changed since.
- "What brand owned the most revenue in March?" → broken if the brand FK changed.
- "Which marketer earned the most last quarter?" → broken if the tier mapping changed.
- "Profit by category in 2025" → broken if the category was renamed or its `parent_id` flipped.

The accounting trail must be **self-contained** in `order_items`. Joins to live products are for display only, never for financial aggregation.

## 3. Current snapshot fields (Phase 5.9)

| Field | Captured at create? | Used in |
|---|---|---|
| `product_id` | yes | reference (live) |
| `product_variant_id` | yes (nullable) | reference (live) |
| `sku` | yes | snapshot — printed on label, used in reports |
| `product_name` | yes | snapshot — display |
| `quantity` | yes | snapshot |
| `unit_price` | yes | snapshot — sale price |
| `discount_amount` | yes | snapshot |
| `tax_amount` | yes | snapshot |
| `total_price` | yes | snapshot (computed = qty × unit_price − discount + tax) |
| `unit_cost` | yes | snapshot — landed cost at sale time |
| `marketer_trade_price` | yes (when marketer attached) | snapshot |
| `marketer_shipping_cost` | yes (Phase 5.9) | snapshot |
| `marketer_vat_percent` | yes (Phase 5.9) | snapshot |

## 4. Snapshot gaps to fix (Phase O-4)

The following fields **must** be added to `order_items` and populated at create time:

| Field | Type | Source | Purpose |
|---|---|---|---|
| `brand_snapshot` | `VARCHAR(255) NULL` | `products.brand.name` (Phase 1 brand) | Profit-by-brand reports |
| `brand_id_snapshot` | `BIGINT UNSIGNED NULL` | `products.brand_id` | Same, FK-style |
| `category_snapshot` | `VARCHAR(255) NULL` | `products.category.name` | Profit-by-category reports |
| `category_id_snapshot` | `BIGINT UNSIGNED NULL` | `products.category_id` | Same |
| `supplier_snapshot` | `VARCHAR(255) NULL` | `products.supplier.name` | Cost / margin by supplier reports |
| `supplier_id_snapshot` | `BIGINT UNSIGNED NULL` | `products.supplier_id` | Same |
| `product_status_snapshot` | `ENUM('Active','Inactive','Out of Stock','Discontinued')` | `products.status` | Tells reports the product was active when sold |
| `vat_rate_snapshot` | `DECIMAL(5,2) NULL` | `products.vat_rate` (or Phase 4 per-country override) | Audit + per-VAT-rate reports |
| `vat_inclusive_flag_snapshot` | `BOOLEAN NULL` | `products.vat_inclusive_flag` (Phase 2) | Audit clarity |
| `currency_code_snapshot` | `CHAR(3) NULL` | `orders.currency_code` (or Phase 4 per-country) | Multi-country safety |

These are **all nullable** during the migration window. Existing rows stay NULL; new rows populate fully. Reports that group by snapshot field treat NULL as "unknown" (legacy data bucket).

## 5. Snapshot write policy

Implementation rule: `OrderService::buildItemRows` is the **sole place** that writes snapshots.

```php
// Pseudocode of the policy
foreach ($lineInput as $row) {
    $product = Product::with(['brand', 'category', 'supplier'])->find($row['product_id']);
    $variant = $row['product_variant_id'] ? ProductVariant::find($row['product_variant_id']) : null;

    $orderItem = OrderItem::create([
        // Live FKs (informational, joins for display only)
        'product_id'             => $product->id,
        'product_variant_id'     => $variant?->id,

        // Existing snapshots
        'sku'                    => $variant?->sku ?? $product->sku,
        'product_name'           => $product->name,
        'unit_price'             => $row['unit_price'],
        'unit_cost'              => $variant?->cost_price ?? $product->cost_price,
        'marketer_trade_price'   => $resolverResult->tradePrice,
        'marketer_shipping_cost' => $resolverResult->shipping,
        'marketer_vat_percent'   => $resolverResult->vatPercent,
        // ...

        // NEW Phase O-4 snapshots
        'brand_snapshot'             => $product->brand?->name,
        'brand_id_snapshot'          => $product->brand?->id,
        'category_snapshot'          => $product->category?->name,
        'category_id_snapshot'       => $product->category?->id,
        'supplier_snapshot'          => $product->supplier?->name,
        'supplier_id_snapshot'       => $product->supplier?->id,
        'product_status_snapshot'    => $product->status,
        'vat_rate_snapshot'          => $product->vat_rate,
        'vat_inclusive_flag_snapshot'=> $product->vat_inclusive_flag,
        'currency_code_snapshot'     => $order->currency_code,
    ]);
}
```

## 6. What must NOT happen

- **Never** update an `order_items` snapshot in response to a product change. If a brand is renamed in `brands`, only future orders pick up the new name; existing orders keep the old `brand_snapshot`.
- **Never** delete an `order_items` row in response to a product delete. Use soft-delete / status changes on `products`, but the snapshot survives independently.
- **Never** compute a financial report by joining `order_items.product_id → products.brand`. Always group by `order_items.brand_snapshot` (or `brand_id_snapshot`).

## 7. Read patterns

| Need | Query |
|---|---|
| Display the item on Order Show | Read the snapshot fields directly. Optionally also fetch `products.image_url` for a thumbnail (live join — display-only, never financial). |
| Reports: revenue by brand last quarter | `GROUP BY brand_id_snapshot` (fallback `'unknown'` when NULL). |
| Reports: cost-by-supplier | `GROUP BY supplier_id_snapshot`. |
| Audit "what did this order item look like at creation?" | All snapshot fields — display them as a frozen card. |

## 8. Schema migration (Phase O-4)

```sql
ALTER TABLE order_items
    ADD COLUMN brand_snapshot VARCHAR(255) NULL AFTER product_name,
    ADD COLUMN brand_id_snapshot BIGINT UNSIGNED NULL,
    ADD COLUMN category_snapshot VARCHAR(255) NULL,
    ADD COLUMN category_id_snapshot BIGINT UNSIGNED NULL,
    ADD COLUMN supplier_snapshot VARCHAR(255) NULL,
    ADD COLUMN supplier_id_snapshot BIGINT UNSIGNED NULL,
    ADD COLUMN product_status_snapshot ENUM('Active','Inactive','Out of Stock','Discontinued') NULL,
    ADD COLUMN vat_rate_snapshot DECIMAL(5,2) NULL,
    ADD COLUMN vat_inclusive_flag_snapshot BOOLEAN NULL,
    ADD COLUMN currency_code_snapshot CHAR(3) NULL;

CREATE INDEX oi_brand_id_snapshot_index ON order_items(brand_id_snapshot);
CREATE INDEX oi_category_id_snapshot_index ON order_items(category_id_snapshot);
CREATE INDEX oi_supplier_id_snapshot_index ON order_items(supplier_id_snapshot);
```

Additive only. Existing rows: all new fields NULL. The OrderService change to populate them is shipped in the same migration window.

## 9. Optional one-time backfill

For the existing ~N orders, a backfill script can populate snapshots from the **current** product/brand/category/supplier values. This is **lossy** — it sets the snapshot to today's value, not the historical truth — but it lets dashboards aggregate by brand/category immediately.

The script is optional. If skipped, reports show old orders as `NULL` brand, and post-Phase O-4 orders show the correct brand.

Recommendation: **run the backfill once** after Phase 1 ships brands (so brand values are populated and the backfill has data to copy). Mark every backfilled row with a `snapshot_backfilled_at` timestamp so future audits can distinguish "snapshot at creation" from "snapshot from backfill".

## 10. Profit estimate field — separate consideration

A `profit_estimate` field per order item:

```
profit_estimate = (unit_price − vat_amount) − unit_cost − (allocated_shipping_per_item)
```

Per the architecture review, this is **derived** in most reads. Storing it as a snapshot adds redundancy. Two stances:

| Stance | Pro | Con |
|---|---|---|
| **Don't store** | Single source of truth (compute on read) | Reports compute per-line on every read |
| **Store** | Reports just sum a column | Profit formula changes silently break old aggregates |

**Recommendation: don't store at the line level.** The order-level `gross_profit` and `net_profit` ARE stored (existing). Line-level can be computed when needed. Keep `order_items` as the authoritative input, `orders` as the authoritative output.

## 11. Permissions involved

| Slug | Purpose |
|---|---|
| `orders.edit` | Edit non-financial fields (no impact on snapshots) |
| `orders.edit_financial` (new) | Required to ever edit a snapshot — and even then, must go through `ApprovalRequest` of type "Edit Confirmed Order Price" or similar. Practically: never used; legacy escape hatch only. |

## 12. Risks

| Risk | Mitigation |
|---|---|
| OrderService doesn't always eager-load brand/category/supplier → N+1 at create time | Migration ships a `Product::scopeForOrderCreate` that always eager-loads the trio |
| Migration window: new rows populate, old rows are NULL → mixed reports | Document NULL = "pre-snapshot legacy"; run the optional backfill to homogenize |
| Future schema change wants a new snapshot field | Add new nullable column; existing rows keep NULL; no rewrite of historical rows |
| Backfill mis-populates with today's values | Mark `snapshot_backfilled_at` on backfilled rows so reports can flag them as approximate |

## 13. Cross-references

- Decision D-004 — order item-level edits LOCKED post-create
- [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md) (brand_id added in Phase 1)
- [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md)
- [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md) (reports that depend on these snapshots)
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md) (`orders.edit_financial`)
