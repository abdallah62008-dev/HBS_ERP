# Phone, Address & WhatsApp Readiness

> Status: **DESIGN ONLY.**

---

## 1. Why this matters

Three downstream features need a normalized phone number:

1. **Reliable duplicate-customer detection** — today the dedupe service matches on raw strings, so `01000000001` and `+201000000001` and `0100 000 0001` look like different customers.
2. **WhatsApp automation** — WhatsApp Business API needs E.164 format (`+201000000001`). Without an indexed normalized column, every outgoing message requires runtime parsing.
3. **Carrier integrations** — Aramex / Bosta / Mylerz APIs want country-prefixed numbers in defined formats; failure rates spike on malformed input.

Today every customer phone is a raw string. The Phase 5.8 work added a `primary_phone_whatsapp` boolean flag but didn't normalize the underlying number.

## 2. Target data shape (Phase O-2)

Three new fields on `customers`:

| Field | Type | Source | Indexed |
|---|---|---|---|
| `country_code` | `VARCHAR(4)` | derived from a country picker on the form (`+20`, `+966`, `+971`, `+964`) | No |
| `local_phone` | `VARCHAR(32)` | what the operator typed, raw, retained for audit | No |
| `normalized_phone` | `VARCHAR(20)` | full E.164 (`+201000000001`) | **Yes — unique** |

`customers.primary_phone` (existing) stays untouched as a *display* + *legacy compatibility* field. It is the same value as `normalized_phone` after migration; readers can use either.

The same triple is added to `customers.secondary_phone` mirror fields:
- `secondary_country_code`
- `secondary_local_phone`
- `secondary_normalized_phone`

`orders` already carries a phone snapshot (Phase 5.8). Extend with:
- `customer_phone_normalized` (snapshot of the customer's E.164 at order create time)

## 3. Normalization rules

### Egypt (+20)

```
Operator types          Stored country_code   Stored local_phone   Stored normalized_phone
─────────────────────────────────────────────────────────────────────────────────────────
01000000001             +20                   01000000001          +201000000001
1000000001              +20                   1000000001           +201000000001
+201000000001           +20                   1000000001           +201000000001
0100 000 0001           +20                   01000000001          +201000000001
201000000001            +20                   1000000001           +201000000001
```

Rules:
1. Strip whitespace, dashes, parentheses, dots.
2. If starts with `+`, the next 1-3 digits are the country code; strip them.
3. If starts with `00`, replace with `+` and apply rule 2.
4. If starts with the country's national prefix (Egypt: `0`), strip it.
5. Validate the remaining local-phone length against the country's expected length range.
6. Compose `+{country_code}{stripped_local}` → store as `normalized_phone`.

### Saudi Arabia (+966)

```
Operator types          Stored country_code   Stored local_phone   Stored normalized_phone
─────────────────────────────────────────────────────────────────────────────────────────
0501234567              +966                  0501234567           +966501234567
501234567               +966                  501234567            +966501234567
+966501234567           +966                  501234567            +966501234567
00966501234567          +966                  501234567            +966501234567
```

### UAE (+971)

Same pattern. National prefix `0`. Local length 9 digits after stripping prefix. Mobile starts with `5`.

### Iraq (+964)

National prefix `0`. Local length 10 digits. Mobile starts with `7`.

## 4. Validation rules per country

Per-country rules are seeded into the `countries` table (extend its schema):

```sql
ALTER TABLE countries ADD COLUMN dial_code VARCHAR(8) NULL;
ALTER TABLE countries ADD COLUMN national_prefix VARCHAR(4) NULL;
ALTER TABLE countries ADD COLUMN mobile_min_digits TINYINT UNSIGNED NULL;
ALTER TABLE countries ADD COLUMN mobile_max_digits TINYINT UNSIGNED NULL;
ALTER TABLE countries ADD COLUMN mobile_starts_with VARCHAR(8) NULL;  -- regex-friendly
```

Seed values:

| Country | dial_code | national_prefix | mobile_min | mobile_max | mobile_starts_with |
|---|---|---|---|---|---|
| Egypt | +20 | 0 | 11 | 11 | `^1[0125]` |
| Saudi Arabia | +966 | 0 | 9 | 9 | `^5` |
| UAE | +971 | 0 | 9 | 9 | `^5` |
| Iraq | +964 | 0 | 10 | 10 | `^7` |

Backend service: `PhoneNormalizationService::normalize(raw, country_code)` returns the triple or throws if invalid.

## 5. WhatsApp-ready format

The E.164 form (`+201000000001`) is what WhatsApp Business API expects. Some clients use the form without `+` (`201000000001`):

| Use case | Format |
|---|---|
| Internal storage + dedupe | E.164 with `+`: `+201000000001` |
| WhatsApp Business API call | E.164 without `+`: `201000000001` (derived on read) |
| Display to operator | Local format: `0100 000 0001` (derived from country dial code + local) |

No new column — all three forms derive from `normalized_phone`.

## 6. Duplicate detection (post-Phase 2)

The existing `DuplicateDetectionService` upgrades from string-match to `normalized_phone` match:

```
WHERE customers.normalized_phone = ?
   OR customers.secondary_normalized_phone = ?
```

Speed: indexed lookup; O(1) per customer. Today's fuzzy match is O(N).

False-positive risk after normalization: very low. Whatever the operator typed (`0100000`, `+201 000`, `2010 0000`) → same `normalized_phone` → same dedupe behaviour.

## 7. Migration strategy (Phase O-2)

| Step | Action | Risk |
|---|---|---|
| 1 | Add nullable `country_code`, `local_phone`, `normalized_phone` (and secondary triple) | None — additive |
| 2 | Backfill: parse each existing `primary_phone` using `PhoneNormalizationService`, default to Egypt (`+20`) when unambiguous | Low — read-only on `primary_phone` |
| 3 | Validate backfill — count records where normalization failed; manually fix | Low |
| 4 | Add unique index on `normalized_phone` (allow nulls during transition) | Medium — collisions surface real duplicates that operators must merge |
| 5 | Switch `DuplicateDetectionService` to use `normalized_phone` | Low — only changes the WHERE clause |
| 6 | Switch Customer form + Order Create inline-customer form to use the country picker + local input pair | Low — pure UI |
| 7 | Phase out reads of `primary_phone` after several weeks — keep the column as legacy display | None |

## 8. Address hierarchy

```
country
  └── governorate / region
        └── city
              └── district          ← Phase O-3 — new
                    └── street / building / floor / apartment / landmark   ← free text on customer / order
```

Today: `countries` / `states` / `cities` lookups exist; customers + orders write free text. Phase O-3 plan:

- New `districts` table (FK city, name_ar, name_en, sort_order, is_active).
- New columns on `customers` + `customer_addresses`:
  - `country_id` (FK `countries`)
  - `state_id` (FK `states`)
  - `city_id` (FK `cities`)
  - `district_id` (FK `districts`, nullable)
  - `street_address` (free text)
  - `landmark` (free text, optional)
- Keep existing free-text `country` / `governorate` / `city` columns as legacy display until verified.
- Order snapshot retains the free-text columns AND the resolved IDs.

### Backfill
- Country: match free-text to seeded `countries.name_en` or `name_ar`.
- Governorate: fuzzy-match to seeded `states.name_en` / `name_ar`.
- City: fuzzy-match to seeded `cities.name_en` / `name_ar`.
- District: leave NULL initially; operators add as needed.
- Mismatches: flag for manual review; don't auto-create new states/cities.

## 9. UI shape

### Customer Create form (post-Phase 2)

```
Primary phone
  Country code: [ +20 (Egypt)   ▼ ]   Local number: [ 01000000001 ]   ☑ WhatsApp
                                       ─────────────
                                       (normalized: +201000000001)

Secondary phone (optional)
  Country code: [ +20 (Egypt)   ▼ ]   Local number: [                 ]   ☐ WhatsApp

Address
  Country:        [ Egypt          ▼ ]
  Governorate:    [ Cairo          ▼ ]
  City:           [ New Cairo      ▼ ]
  District:       [ Fifth Settlement ▼ ]   ← Phase O-3
  Street + building: [ ... freeform ... ]
  Landmark (optional): [ ... ]
```

### Order Create inline customer
Same layout, condensed.

## 10. Permissions

| Slug | Purpose |
|---|---|
| `customers.view` | (existing) |
| `customers.edit` | (existing) |
| `customers.merge_duplicate` | **New** — required for the dedupe-merge workflow after normalization surfaces duplicates |

## 11. Do-now / do-later

### Phase O-2 — Must
- New triple `country_code` + `local_phone` + `normalized_phone` columns on `customers`.
- `PhoneNormalizationService`.
- Country dial codes seeded.
- Migration script for backfill.
- Customer form + Order Create form use the new triple.
- DuplicateDetectionService switches to normalized_phone.

### Phase O-3 — Should
- `districts` table.
- FK columns on `customers` + `customer_addresses` + `orders` for country / state / city / district.
- Backfill matcher.
- Free-text legacy columns retained for one Phase before deprecation review.

### Later
- Multi-country city/district seed data acquisition.
- E.164 validation library (e.g. `giggsey/libphonenumber-for-php`) — eval before Phase O-2 if rule complexity grows.
- WhatsApp Business API connector (Phase 7+).

## 12. Risks

| Risk | Mitigation |
|---|---|
| Backfill creates false duplicates (`01000000001` and `+201000000001` resolve to the same `normalized_phone`) | This is the *correct* behaviour — operators surface and merge via the new `customers.merge_duplicate` workflow. Surface a "Pending Duplicate Review" queue. |
| Backfill fails on ambiguous strings | Skip the row, leave `normalized_phone = NULL`, log for manual review. |
| Schema change on `customers` is high-impact | Migration is additive only; nothing dropped in Phase O-2. |
| Future country (e.g. Jordan, Lebanon) requires new dial code + validation rules | Add a row to `countries` and a corresponding entry in `PhoneNormalizationService::COUNTRY_RULES`. |

## 13. References

- [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md)
- [SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md](./SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md)
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)
