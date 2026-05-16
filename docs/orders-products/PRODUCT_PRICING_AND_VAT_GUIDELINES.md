# Product Pricing & VAT Guidelines

> Status: **DESIGN ONLY.**

---

## 1. Pricing fields

| Field | Storage | Required | Purpose |
|---|---|---|---|
| `cost_price` | products / variants | Yes | Landed cost in primary currency (Phase 4: per-country) |
| `selling_price` | products / variants | Yes | Default sale price to end customer |
| `marketer_trade_price` | products / variants | Yes | Default base for marketer-resold orders |
| `minimum_selling_price` | products / variants | Yes | **Profit guard floor.** Orders cannot sell below this. |
| `suggested_retail_price` (SRP) | *Phase 4* `product_country_prices` | No | Reference price; not enforced |
| `vat_rate` | products | Yes | VAT % for this product |
| `tax_enabled` | products | Yes | Whether VAT applies (some categories are exempt) |
| `vat_inclusive_flag` | *Phase 2* products | No | Whether `selling_price` already includes VAT |

## 2. VAT-inclusive vs VAT-exclusive

The system today **assumes VAT-exclusive**: `selling_price` is the pre-tax amount and VAT is added on top.

We will add an explicit `vat_inclusive_flag` boolean to `products` in Phase 2 to remove ambiguity:

| Flag | `selling_price` is interpreted as | Display formula |
|---|---|---|
| `false` (exclusive) — default | pre-tax | customer pays `selling_price × (1 + vat_rate/100)` |
| `true` (inclusive) | tax-included | customer pays `selling_price`; pre-tax derived as `selling_price ÷ (1 + vat_rate/100)` |

The flag is purely a labeling decision — both representations are mathematically equivalent. The flag tells the UI which interpretation to show.

## 3. Egypt VAT example

```text
VAT rate            = 14 %
Pre-tax price       = 1,000.00 EGP
VAT amount          = 1,000.00 × 0.14         = 140.00 EGP
Inclusive price     = 1,000.00 + 140.00       = 1,140.00 EGP
```

Reverse direction (inclusive → exclusive):

```text
Inclusive price     = 1,140.00 EGP
VAT rate            = 14 %
Pre-tax price       = 1,140.00 ÷ 1.14         = 1,000.00 EGP
VAT amount          = 1,140.00 − 1,000.00     = 140.00 EGP
```

## 4. Two-way VAT calculator (Phase 2 UI)

A small calculator widget on the Product Edit page:

```text
Direction:    [ Exclusive → Inclusive ] [ Inclusive → Exclusive ]
VAT rate:     [ 14.00 ] %
Input:        [ 1,000.00 ]
Output:
    Pre-tax     1,000.00
    VAT amount    140.00
    Inclusive   1,140.00
```

Behavioural rules:

- VAT rate prefills from `products.vat_rate`; user can override for what-if math without saving.
- Output recomputes on every keystroke (debounced 200ms).
- Pressing "Apply" copies the output back into `selling_price` (which one becomes selling depends on `vat_inclusive_flag`).
- No DB writes from the calculator itself; only "Save product" persists.

## 5. Margin calculator (Phase 2 UI)

Next to the VAT calculator:

```text
Selling price        1,000.00 EGP
VAT                    140.00 EGP   (14 %)
Net selling          1,000.00 EGP   ← pre-tax
Cost price             400.00 EGP
Shipping est           50.00 EGP    (operator-entered or per-route)
Platform / marketing   30.00 EGP    (operator-entered, optional)
─────────────────────────────────
Net profit             520.00 EGP
Margin               52.0 %
```

Where:

- **Net profit** = `net_selling − cost_price − shipping − platform_fee`
- **Margin** = `net_profit ÷ net_selling × 100`

For marketer orders, the formula uses `marketer_trade_price` instead of `cost_price` and follows the Phase 5.9 chain (`MarketerPricingResolver`).

## 6. Validation warnings (Phase 2)

The Product Edit and Order Create pages should surface warnings — not block — when:

| Condition | Warning | Surface where |
|---|---|---|
| `selling_price < minimum_selling_price` | "Selling below minimum" (block, never allow save) | Product Edit + Order Create |
| `margin < threshold` (default 10 %) | "Low margin: {n} %" | Product Edit, Order Create per line |
| `cost_price` is null or 0 | "Cost price missing — profit cannot be computed" | Product Edit |
| `vat_rate` set but `tax_enabled = false` | "VAT rate is set but tax is disabled — inconsistent" | Product Edit |
| `vat_inclusive_flag = true` and `vat_rate = 0` | "Inclusive flag is on but VAT rate is 0" | Product Edit |
| Sum of marketer cost + shipping + VAT ≥ selling price | "Marketer would earn ≤ 0 on this product" | Marketer tier table in Product Edit, Order Create |

Threshold values (min-margin %, etc.) come from `settings` table; default values shipped in `SettingsSeeder`.

## 7. Minimum selling price — hard rule

`minimum_selling_price` is enforced by `ProfitGuardService` at order-create time. Below-minimum behaviour:

| Permission | Behaviour |
|---|---|
| User without `orders.override_price` | **Hard block** with 422 error. |
| User with `orders.override_price` | UI warns, requires `price_override_reason` (min 5 chars), creates an audit row. Order proceeds. |
| (Future) `orders.below_min_approve` | Creates an `ApprovalRequest` of type "Approve Below-Min Selling"; manager approves before the order is finalised. |

Detail in [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md).

## 8. Country pricing (Phase 4 — Should)

When `product_country_prices` lands, the Product Edit gets a new tab:

| Country | Currency | Cost | Selling | Min Selling | VAT % | Active |
|---|---|---|---|---|---|---|
| Egypt | EGP | 400 | 1,000 | 700 | 14.00 | ✓ |
| Saudi Arabia | SAR | 50 | 130 | 90 | 15.00 | ✓ |
| UAE | AED | 50 | 110 | 70 | 5.00 | — |
| Iraq | IQD | 60,000 | 130,000 | 90,000 | 0.00 | — |

OrderService resolves the row matching the order's country (computed from customer address). When no row matches an active country, the order falls back to the global product/variant prices.

## 9. Channel pricing (Phase 4+ — Later)

Distinct from country pricing — per-marketplace markup logic. Not part of Phase 0 doc; will be a future doc once we have a marketplace integration target.

## 10. Marketer pricing interplay

The marketer pricing chain is independent of the country pricing chain, but the resolver consults them in this order at order create time:

```
1. marketer-specific override for (this marketer, this product, this variant)
2. marketer's tier price for (this tier, this product, this variant)
3. country price for (this variant, this country)             — Phase 4
4. global product/variant default
5. block (no price → cannot place order)
```

Detail: [MARKETER_PRICING_AND_PROFIT_ROADMAP.md](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md).

## 11. Permission slugs (additions)

| Slug | Granted to | Purpose |
|---|---|---|
| `products.edit_price` | Manager+ | Edit selling / marketer_trade / min_selling |
| `products.edit_cost` | Admin+ | Edit cost_price (more sensitive than sale price) |
| `products.edit_vat` | Admin+ | Change vat_rate / tax_enabled / vat_inclusive_flag |
| `products.edit_country_price` | Admin+ | Phase 4 — edit per-country price rows |
| `orders.override_price` | Admin+ | Sell at a custom unit_price on a line; require reason |
| `orders.below_min_approve` | Admin (rare) | Manager-approved sell below min |

## 12. Do-now / do-later

### Phase 2 — Must (UI only, no schema)
- VAT in/exclusive calculator (computed client-side).
- Margin previewer.
- Warnings: below-min, low-margin, missing cost, VAT inconsistency.

### Phase 2 — Should (schema)
- `products.vat_inclusive_flag` boolean (nullable default false).

### Phase 4 — Should (per-country pricing)
- `product_country_prices` table.
- OrderService chain extends to consult it.

### Later
- Channel pricing (per marketplace markup).
- Suggested retail price (SRP) UI surface.
- Promotional pricing windows (start_at / end_at).

## 13. References

- [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md)
- [MARKETER_PRICING_AND_PROFIT_ROADMAP.md](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md)
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)
- [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md)
