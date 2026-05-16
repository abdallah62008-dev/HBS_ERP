# Channel SKU & Marketplace Mapping

> Status: **DESIGN ONLY.**

---

## 1. Why we need this

Today every product has **one** SKU: an internal identifier (`products.sku`, e.g. `UAT-ALPHA-001`). It's used everywhere — order entry, inventory, returns, reports.

But each external marketplace assigns its own SKU to the same physical product:

| Marketplace | Their SKU shape | Example |
|---|---|---|
| Amazon | ASIN — 10-char alphanumeric | `B08N5WRWNW` |
| Noon | NSN — variable string | `N12345678-12345-EG` |
| Jumia | JM SKU — usually URL-friendly | `JU-12345-EG` |
| Website | Internal but separate | `WB-2026-04-001` |
| Supplier | Vendor's own catalogue ID | `SUP-ACME-K42` |

When an order is downloaded from a marketplace (via API or CSV import), it carries the marketplace SKU, not our internal SKU. Without a mapping table, the system can't auto-route the order to the right product. Operations resort to manual matching → slow, error-prone.

## 2. Internal SKU vs Channel SKU

| | Internal SKU | Channel SKU |
|---|---|---|
| Source | We assign | Each marketplace assigns |
| Stored on | `products.sku` / `product_variants.sku` | `product_channel_skus.external_sku` |
| Uniqueness scope | Globally unique | Unique per (variant, channel) |
| Used for | Internal operations, reports, inventory | Marketplace imports + reconciliation |
| Cardinality per variant | 1 | N (one row per marketplace presence) |

A single variant can carry zero, one, or many channel SKU rows. The internal SKU is the spine; channel SKUs are leaves.

## 3. Proposed schema

```sql
CREATE TABLE product_channel_skus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    product_variant_id BIGINT UNSIGNED NOT NULL,
    channel ENUM(
        'Internal',
        'Amazon',
        'Noon',
        'Jumia',
        'Website',
        'Supplier',
        'Other'
    ) NOT NULL,

    external_sku VARCHAR(128) NOT NULL,
    external_url VARCHAR(1024) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    notes TEXT NULL,

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (product_variant_id) REFERENCES product_variants(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,

    UNIQUE KEY pcs_variant_channel_unique (product_variant_id, channel),
    INDEX pcs_channel_extsku_index (channel, external_sku),
    INDEX pcs_extsku_index (external_sku)
);
```

### Field rationale

- `channel` is an enum (not a FK to a `channels` table) because the list is short, well-known, and stable. Easy to extend later.
- `external_sku` `VARCHAR(128)` — generous; some marketplaces have long composite SKUs.
- `external_url` — optional deep link to the marketplace product page (operator can click to verify).
- `is_active` — lets ops disable a stale mapping without deleting history.
- `notes` — operator scratchpad ("Noon delisted us 2026-03-15").
- Unique `(product_variant_id, channel)` — one mapping per channel per variant; supports `ON DUPLICATE KEY UPDATE` for re-imports.
- Index `(channel, external_sku)` — primary lookup at marketplace-order-import time.

## 4. Operator UX (Phase 1)

Inside the Product Edit form, a new tab "Channel SKUs":

```text
Channel SKUs  (one row per variant × marketplace)
─────────────────────────────────────────────────────
Variant: SKU-001 — "Red, Medium"
  ┌─────────────┬─────────────────────┬──────────────────────────┬────────┐
  │ Channel     │ External SKU        │ URL                      │ Active │
  ├─────────────┼─────────────────────┼──────────────────────────┼────────┤
  │ Amazon      │ B08N5WRWNW          │ https://amazon.eg/...    │ ✓      │
  │ Noon        │ N12345678-12345-EG  │ https://noon.com/...     │ ✓      │
  │ Website     │ WB-2026-04-001      │ —                        │ ✓      │
  │ + Add row                                                              │
  └─────────────┴─────────────────────┴──────────────────────────┴────────┘
```

- Each row inline-editable.
- Add row appends; delete row sets `is_active=false` (soft retire).
- Permission: `products.edit_channel_sku`.

## 5. Search implications

Server-side product search at order create time today queries `products.sku` + `products.barcode` + `product_variants.sku` + `product_variants.barcode`.

**After Phase 1**, search also queries `product_channel_skus.external_sku` (left-joined). Result rows include the matched channel for the operator's reference:

```
Search: "B08N5WRWNW"
Result: UAT-ALPHA-001 — "Red Medium" (Amazon SKU B08N5WRWNW)
```

Index: `(channel, external_sku)` makes this fast even at 50K+ mappings.

## 6. Import / export implications

### Order import (future)
- Marketplace CSV / API feeds carry channel SKU.
- Importer's first lookup: `WHERE channel = ? AND external_sku = ?`.
- Match → use that variant for the order item.
- No match → flag the row for manual review; do NOT auto-create products.

### Product export
- Per-channel exports (e.g. "give me a CSV of all Amazon-listed products") = filter by `channel = 'Amazon' AND is_active = true`.

### CSV bulk update
- Operator's CSV columns: `internal_sku`, `channel`, `external_sku`, `is_active`.
- Update logic: `INSERT ... ON DUPLICATE KEY UPDATE` keyed on `(variant_id, channel)`.

## 7. Marketplace integration readiness

This table is the precondition for Phase 6+ work:

1. **API integration** (Amazon / Noon / Jumia order pull) — they push their SKU; we resolve.
2. **Stock sync** (push our on-hand to each marketplace) — we walk each `product_channel_skus` row of `channel = X` and call the marketplace API with their SKU + our on-hand.
3. **Price sync** — same pattern with per-channel prices (Phase 4+ extension).

## 8. Avoiding duplicate / conflicting SKUs

Three protections:

1. **`UNIQUE (product_variant_id, channel)`** — a variant can have at most one row per marketplace.
2. **No global uniqueness on `external_sku`** — two different variants can share an SKU on different marketplaces (rare but possible). We don't enforce cross-channel uniqueness.
3. **Soft validation in UI**: if operator enters an `external_sku` that already exists for *another* variant on the same channel, surface a warning (not block) — could be a deliberate alias.

## 9. Cross-channel duplicates within a variant

A single variant might be listed under different SKUs across channels — that's the entire point of this table. The flow:

```text
Variant UAT-ALPHA-001 = the same physical product
  ├── Channel: Amazon         → external_sku: B08N5WRWNW
  ├── Channel: Noon           → external_sku: N12345678
  ├── Channel: Jumia          → external_sku: JU-12345-EG
  └── Channel: Website        → external_sku: WB-2026-04-001
```

## 10. Do-now / do-later

### Phase 1 — Must
- `product_channel_skus` migration (additive).
- Product Edit UI: "Channel SKUs" tab.
- Server-side product search extends to `external_sku`.
- Permission slug: `products.edit_channel_sku`.

### Phase 6+ — Later
- Marketplace API connectors (Amazon SP-API, Noon, Jumia).
- Stock push.
- Price push (depends on Phase 4 country pricing).
- Order pull + auto-match.

### Out of scope of Phase 0
- WooCommerce / Shopify mirrors.
- POS integrations (we're not a POS).
- B2B EDI feeds.

## 11. References

- [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md)
- [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md) (product search at order create)
- [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md) (Orders by channel)
