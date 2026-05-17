# Product Master Data — Roadmap

> Status: **DESIGN ONLY.** No tables or columns have been added yet.

---

## 1. Today's state

| Concept | Table / Field today | Notes |
|---|---|---|
| Product | `products` | Single row per product; carries cost / selling / marketer_trade / minimum_selling, tax_enabled, tax_rate, status, sku, barcode |
| Variant | `product_variants` | Mirrors `products` pricing per variant |
| Category | `categories` (self-tree) | `parent_id` self-FK; status enum (Active / Inactive) |
| Brand | **missing** | No brand table; brand is implied in product name text |
| Family | **missing** | "Product family" (e.g. "iPhone 15 family" covering 15, 15 Plus, 15 Pro, 15 Pro Max) doesn't exist |
| Model | **missing as a separate field** | Product name carries it freeform |
| Channel SKU | **missing** | Internal SKU only; no Amazon / Noon / Jumia mapping |
| Barcode | `products.barcode`, `product_variants.barcode` | One per row, indexed |
| Status | `products.status` enum | `Active / Inactive / Out of Stock / Discontinued` |
| Country settings | **missing** | Single global pricing only |
| Cost | `products.cost_price` | Single global cost |
| Marketer trade price | `products.marketer_trade_price` | Single global; per-marketer overrides exist in `marketer_product_prices` |
| Tax / VAT | `products.tax_enabled` + `tax_rate` | Single rate per product; no per-country |
| Suggested retail price | **missing** | No "SRP" or "MAP" (minimum advertised price) |
| Media / images | `products.image_url` | One URL only |
| SEO fields | **missing** | Not relevant for internal ERP today |

## 2. Target master data shape

| Concept | Target table / field | Phase |
|---|---|---|
| **Brand** | New `brands` table + `products.brand_id` (nullable FK) | **P-1 — Must** |
| **Channel SKU** | New `product_channel_skus` (variant_id, channel, external_sku, external_url, is_active) | **P-1 — Must** |
| Category | Existing `categories` (no change) | — |
| Variant | Existing `product_variants` (no change) | — |
| Status | Existing enum on `products` (and add on variants in P-1: `is_active` boolean for marketplace visibility) | — |
| **Country pricing** | New `product_country_prices` (variant_id, country_id, cost, selling, min, vat, currency, is_active) | **P-4 — Should** |
| **Quantity tiers** | New `product_price_tiers` (variant_id, min_qty, max_qty, price) | **P-5 — Should** |
| Family | New `product_families` (self-FK parent) | **Later** |
| Model | Treat as `products.model` string column | Later |
| Media | Lift `image_url` → separate `product_media` table (multiple images, primary flag) | Later |
| SEO | Per-channel SEO fields if needed for a future PIM phase | Later / out of scope |

## 3. Proposed `brands` schema

```sql
CREATE TABLE brands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    logo_url VARCHAR(1024) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order SMALLINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    INDEX brands_active_sort_index (is_active, sort_order)
);

ALTER TABLE products ADD COLUMN brand_id BIGINT UNSIGNED NULL AFTER category_id;
ALTER TABLE products ADD CONSTRAINT products_brand_id_foreign FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL;
ALTER TABLE products ADD INDEX products_brand_id_index (brand_id);
```

**Notes**
- `slug` for URLs and search.
- `brand_id` nullable on `products` — existing products migrate as "no brand" until the operator backfills.
- `logo_url` reserved for later marketplace exports; not required at P-1 launch.
- Permission: `products.edit_brand` (separate from `products.edit`).

## 4. Proposed `product_channel_skus` schema

See [CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md](./CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md) for the full design. Summary:

```sql
CREATE TABLE product_channel_skus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    channel ENUM('Internal','Amazon','Noon','Jumia','Website','Supplier','Other') NOT NULL,
    external_sku VARCHAR(128) NOT NULL,
    external_url VARCHAR(1024) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    UNIQUE KEY pcs_variant_channel_unique (product_variant_id, channel),
    INDEX pcs_channel_extsku_index (channel, external_sku)
);
```

## 5. Proposed `product_country_prices` (Phase 4 — Should)

See [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md) and the prior architecture-review report for the per-country price model. Key shape:

```sql
CREATE TABLE product_country_prices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    country_id BIGINT UNSIGNED NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    cost_price DECIMAL(12,2) NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL,
    min_selling_price DECIMAL(12,2) NOT NULL,
    suggested_retail_price DECIMAL(12,2) NULL,
    vat_rate DECIMAL(5,2) NULL,
    vat_inclusive_flag BOOLEAN NOT NULL DEFAULT FALSE,
    currency CHAR(3) NOT NULL,
    -- Optional per-country marketer fields can be added in P-3 if needed.
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE,
    UNIQUE KEY pcp_variant_country_unique (product_variant_id, country_id)
);
```

The OrderService pricing chain (post-Phase 4):

```
marketer-specific override (marketer_product_prices)
    → marketer's tier price (marketer_product_prices on tier group)
    → variant-country price (product_country_prices)
    → product/variant default (legacy global columns)
    → hard block
```

## 6. Proposed `product_price_tiers` (Phase 5)

```sql
CREATE TABLE product_price_tiers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_variant_id BIGINT UNSIGNED NOT NULL,
    min_quantity INT UNSIGNED NOT NULL,
    max_quantity INT UNSIGNED NULL,
    price DECIMAL(12,2) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE
);
```

## 7. Do-now / Do-later

### P-1 — Shipped (2026-05-17)
- ✅ `brands` table with `name`, `slug`, `description`, `image_url`, `is_active`, `sort_order` (migration `2026_05_17_100000_create_brands_table.php`).
- ✅ `products.brand_id` nullable FK with `ON DELETE SET NULL` (migration `2026_05_17_100001_add_brand_id_to_products_table.php`).
- ✅ `product_channel_skus` table — variant FK, channel string, external_sku/barcode/url, is_active, notes; unique `(product_variant_id, channel)` (migration `2026_05_17_100002_create_product_channel_skus_table.php`). One extension on the design doc: `external_barcode` is added as a nullable column because some marketplaces print their own barcode and ops needs both for reconciliation.
- ✅ `App\Models\Brand` + `App\Models\ProductChannelSku` with relationships wired into `Product` and `ProductVariant`.
- ✅ Brand admin CRUD page at `/brands` (single-page modelled on Categories admin).
- ✅ Quick-Brand modal inside Product Form (JSON path on `POST /brands`).
- ✅ Product Create/Edit UI — Brand dropdown after Category.
- ✅ Product Edit UI — Channel SKU repeater (variant selector, channel dropdown, external SKU/barcode/URL, active toggle, notes; soft-retire only — no hard delete).
- ✅ Product Show — Brand badge + Channel SKUs table grouped by variant.
- ✅ Product Index — Brand filter dropdown + Brand column.
- ✅ Server-side product index search extended to match by variant SKU/barcode and channel SKU/barcode via EXISTS sub-queries.
- ✅ Tests — `tests/Feature/Products/ProductBrandAndChannelSkuTest.php` (22 tests).
- ⛔ **Deferred from doc spec:** `products.edit_brand` / `products.edit_channel_sku` permission slugs not added. P-1 piggybacks on existing `products.create` / `products.edit` per the brief. Add slugs in a follow-up phase only when separation-of-duties demand surfaces.
- ⛔ **Deferred from doc spec:** Order Create product search (`/orders/products/search`) is NOT extended to channel SKUs in P-1. The hot-path query is left on the existing (name/sku/barcode) contract; the extension lives in a follow-up phase.
- 📌 **Backfill:** No bulk backfill needed. Existing products keep `brand_id = NULL`; operators tag at their own pace.

### Should have soon (Phase 2–4)
- Two-way VAT calculator + margin previewer in Product Edit (Phase 2)
- Pre-submit validation warnings: below-min, low-margin, missing-cost (Phase 2)
- Marketer tier unification (Phase 3)
- `product_country_prices` schema + Product Edit "Country Pricing" tab (Phase 4)
- Order create resolves country pricing first

### Later
- `product_families`
- `product_price_tiers`
- `product_media` (multi-image)
- Per-channel SEO fields
- Bundles / kits
- Serial / lot tracking

## 8. Migration strategy

Every schema change in this roadmap is **additive only**:

| Phase | Migration | Risk |
|---|---|---|
| P-1 | Add `brands` table; add `products.brand_id` nullable | None — null backfill works |
| P-1 | Add `product_channel_skus` table | None — additive |
| P-3 | Migrate marketer tier mapping | **Medium** — needs data migration script + audit log |
| P-4 | Add `product_country_prices` table; OrderService chain extends | Medium — touches order create hot path; feature-flag the cutover |
| P-5 | Add `product_price_tiers` | None — additive |
| Later | `product_families`, `product_media` | None |

Legacy columns (`products.cost_price`, `products.selling_price`, `products.marketer_trade_price`, `products.minimum_selling_price`, `products.tax_*`) are **NOT dropped** in this roadmap. They remain as fallbacks during Phase 4 cutover. A future Phase will decide whether to drop them.

## 9. Risks

| Risk | Mitigation |
|---|---|
| Existing products without brand can't be filtered by brand | Operator pre-populates `brands` and back-tags top SKUs before P-1 launch |
| Channel SKU uniqueness across marketplaces | UNIQUE (variant_id, channel) — same variant can have one SKU per channel |
| Brand renaming breaks reports | Reports group by `brand_id`, not `brand_name`; renaming is fine |
| Per-country pricing breaks marketer profit chain | Phase 4 ships a feature flag; cut over after backfill verified |

## 10. References

- [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md)
- [CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md](./CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md)
- [IMPLEMENTATION_PHASES.md](./IMPLEMENTATION_PHASES.md)
