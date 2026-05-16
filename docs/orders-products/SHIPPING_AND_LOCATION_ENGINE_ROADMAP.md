# Shipping & Location Engine Roadmap

> Status: **DESIGN ONLY.** Light coverage — full shipping plan is a separate phase.

---

## 1. Today's state

| Concept | Present? | Notes |
|---|---|---|
| `countries` | ✅ | Seeded: Egypt, Saudi Arabia |
| `states` (governorates) | ✅ | Seeded for Egypt; partial for Saudi |
| `cities` | ✅ | Seeded for the major cities |
| `districts` | ❌ | Not modelled |
| `shipping_zones` | ❌ | Not modelled |
| `shipping_companies` | ✅ | Carrier master |
| `shipping_rates` | ✅ | Per (carrier, country, governorate, city); unique `(company, country, governorate, city)` |
| Per-district rates | ❌ | Granularity stops at city |
| COD support | ✅ | `shipping_rates.cod_fee`, `collections` table |
| Carrier suggestion | ❌ | Operator picks manually |
| Failed delivery handling | ⚠️ partial | Order status `Returned`, return inspection |
| Shipment tracking sync | ❌ | Manual `tracking_number` entry |
| Shipping labels | ✅ | mPDF, Phase 6.5 |

Free-text `country` / `governorate` / `city` columns on `customers` and `orders` carry the display strings (not FK), which means shipping-rate lookups today match on string equality — case-sensitive and typo-fragile.

## 2. Target — full address tree

```
country (Egypt, Saudi Arabia, UAE, Iraq, ...)
   └── state / governorate (Cairo, Riyadh, Dubai, Baghdad)
         └── city (New Cairo, Riyadh-North, Dubai-Marina, Baghdad-Karkh)
               └── district (Fifth Settlement, Hittin, JBR, Mansour)   ← Phase O-3
                     └── street / building / floor / apartment / landmark   ← free text
```

## 3. Proposed `districts` table (Phase O-3)

```sql
CREATE TABLE districts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    city_id BIGINT UNSIGNED NOT NULL,
    name_ar VARCHAR(255) NOT NULL,
    name_en VARCHAR(255) NOT NULL,
    sort_order SMALLINT UNSIGNED NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    INDEX d_city_active_sort_index (city_id, is_active, sort_order)
);
```

District seed: deferred — operators add districts as needed; bulk seed for top cities later.

## 4. Proposed `shipping_zones` table (Phase 6 — Later)

The next-level abstraction above per-rate lookups. A zone groups districts/cities by shipping logistics:

```sql
CREATE TABLE shipping_zones (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    country_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE
);

CREATE TABLE shipping_zone_members (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    shipping_zone_id BIGINT UNSIGNED NOT NULL,
    city_id BIGINT UNSIGNED NULL,
    district_id BIGINT UNSIGNED NULL,
    FOREIGN KEY (shipping_zone_id) REFERENCES shipping_zones(id) ON DELETE CASCADE,
    FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE,
    FOREIGN KEY (district_id) REFERENCES districts(id) ON DELETE CASCADE
);
```

Example: a "Cairo Central" zone includes [Downtown, Maadi, Zamalek, Heliopolis]. A "Cairo Outskirts" zone covers [6th of October, New Cairo Fifth Settlement, Sheikh Zayed]. Different rates per zone.

`shipping_rates` extends to optionally reference a zone instead of (country, governorate, city) tuple:

```sql
ALTER TABLE shipping_rates ADD COLUMN shipping_zone_id BIGINT UNSIGNED NULL;
ALTER TABLE shipping_rates ADD FOREIGN KEY (shipping_zone_id) REFERENCES shipping_zones(id) ON DELETE CASCADE;
```

Lookup priority: zone-specific rate first; fall back to (country, governorate, city); fall back to country default.

## 5. Carrier suggestion (Phase 6 — Later)

Auto-suggest the cheapest / fastest / most-reliable carrier per route. Inputs:

- Destination (resolved to a zone)
- Order weight (future — needs `products.weight_kg`)
- Cash-on-delivery flag
- Historical performance: delivered rate, return rate, average lead time

Output: ranked carrier list; operator can override.

This is a Phase 6 feature — not part of Phase 0–4. Needs the full address tree + at least 3 carriers integrated to be useful.

## 6. Failed delivery handling (partial — improve in Phase 6)

Today:
- Order can move to `Returned` after delivery attempt.
- Return intake captures `return_reason_id` from `return_reasons` lookup.
- Inspection determines restockable or damaged.

Gaps:
- No carrier-side failure code mapping (no auto-import from carrier APIs).
- No "second delivery attempt" workflow.
- No "RTV" (return to vendor) explicit flow.

Phase 6 plan:
- Add `shipments.delivery_attempts` integer.
- Add `shipments.last_failure_reason` enum (Customer Refused / Wrong Address / Customer Unavailable / Damaged in Transit / Other).
- Auto-retry workflow with operator approval.
- Carrier API integration for status sync.

## 7. Shipment tracking sync (Later)

Pull carrier statuses periodically:
- Cron job hits carrier API per active shipment.
- Updates `shipments.shipping_status` and writes a `shipment_status_history` row.
- Notifies operator (Phase 7 notifications) on status change.

Requires per-carrier API client. Not Phase 0–4.

## 8. Link to orders, returns, and order statuses

```
Order create
  └── Shipping rate lookup by destination
        └── Shipping fee added to order totals

Order Confirmed
  └── Stock reserved

Order Shipped
  └── Shipment row created → tracking number → shipping label PDF (mPDF)
  └── Reserve movement → Sale movement (stock decremented)
  └── Collection row updated (COD bucket)

Order Delivered
  └── shipments.delivered_at stamped
  └── Marketer wallet: Pending → Earned (depending on settlement cycle)
  └── (No automatic restock)

Order Returned (after delivery)
  └── Return intake → inspection
        ├── Good condition → Return To Stock movement
        └── Damaged → Damaged movement; refund or write-off

Failed delivery (Phase 6)
  └── shipments.delivery_attempts++
  └── Retry vs Return decision
```

## 9. Do-now / do-later

### Phase O-3 — Should
- `districts` table.
- FK columns `country_id` / `state_id` / `city_id` / `district_id` on `customers` + `customer_addresses` + `orders`.
- Customer Create form: dropdowns for country → governorate → city → district.
- Order Create form: dropdowns mirror the same hierarchy.
- Backfill matcher: free-text → FK ids; flag mismatches.
- Free-text columns retained for legacy display.

### Phase 6 — Later (Shipping engine)
- `shipping_zones` + `shipping_zone_members`.
- Extend `shipping_rates` to optionally reference zone.
- Carrier suggestion engine.
- `shipments.delivery_attempts` + `last_failure_reason`.
- Carrier API integrations.

### Out of scope of Phase 0
- Returns logistics (RMA pickup labels, dropoff points).
- Carrier zone import (manual seed for now).
- Real-time tracking webhook listener.

## 10. Risks

| Risk | Mitigation |
|---|---|
| District seed is partial → operators block on missing districts | Allow free-text fallback during transition; flag for backfill |
| Existing orders fail to match new FK structure | Keep free-text columns; FK lookups become enrichment, not requirement |
| Shipping rates lose specificity if we move to zones too early | Zones are additive in Phase 6; current (country, governorate, city) rates remain the fallback |
| Multi-country expansion creates 10K+ districts | District table is fine at 100K rows; seed only the needed ones per country launch |

## 11. References

- [PHONE_ADDRESS_AND_WHATSAPP_READINESS.md](./PHONE_ADDRESS_AND_WHATSAPP_READINESS.md) (address hierarchy details)
- [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md) (where shipping fee is computed)
- [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md) (shipping performance reports)
