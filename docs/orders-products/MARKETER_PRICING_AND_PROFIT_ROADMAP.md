# Marketer Pricing & Profit Roadmap

> Status: **DESIGN ONLY.**

---

## 1. Today's state

| Concept | Where it lives | Status |
|---|---|---|
| Marketers | `marketers` table | Mature |
| Marketer roles + permission slugs | RBAC catalogue | Mature |
| Marketer wallets | `marketer_wallets` (snapshot) + `marketer_transactions` (ledger) | Mature |
| Marketer profit formula | `MarketerPricingResolver::profitForItem` | Mature |
| Marketer price catalogue | `marketer_product_prices` (per group × product × variant) | Mature, extended Phase 5.6 |
| Tier system #1 (legacy) | `marketer_price_groups` rows with `code = NULL` → Bronze / Silver / Gold / VIP | **Carries data** |
| Tier system #2 (Phase 5.6) | `marketer_price_groups` rows with `code` ∈ {A, B, D, E} | **Carries data** |
| Marketer link on order | `orders.marketer_id` (Phase 5.9) | Mature |
| Marketer profit columns on order | `orders.marketer_profit`, `.marketer_cost_total`, `.marketer_shipping_total`, `.marketer_vat_total`, `.marketer_pricing_source` | Mature |
| Marketer profit preview at order create | `/orders/marketer-profit-preview` endpoint, Phase 5.9 | Mature |

## 2. Current formula

```
marketer_profit_per_line = (
    unit_price
    − unit_price × vat_percent / 100
    − marketer_cost_price
    − shipping_cost
) × quantity
```

Where each input is resolved by `MarketerPricingResolver`:

```
1. marketer-specific override for (this marketer, this product, this variant)
2. marketer's tier price for (this marketer's tier, this product, this variant)
3. legacy product/variant default
```

`marketer_pricing_source` on `orders` records which step won — `marketer_specific`, `tier`, or `product_default`.

## 3. Spec vs reality — tier naming

The product spec asks for **Silver / Gold / Platinum / VIP**.

The system today uses **A / B / D / E** (Phase 5.6) plus legacy **Bronze / Silver / Gold / VIP** rows that pre-date the Phase 5.6 work.

| Tier purpose | Today | Spec | Recommendation |
|---|---|---|---|
| Entry-tier marketers | A / Bronze | Silver | **Rename A → Silver** |
| Mid-tier | B / Silver | Gold | **Rename B → Gold** |
| Senior tier | D / Gold | Platinum | **Rename D → Platinum** |
| Elite | E / VIP | VIP | **Rename E → VIP** |

Rationale: Silver/Gold/Platinum/VIP is the agreed business naming; A/B/D/E was placeholder. Rename keeps backward compatibility because tier rows are keyed on `code` and renaming `name` only.

Letter codes `A/B/D/E` remain valid in URL / API / seeder. Display names update.

## 4. Unifying the two parallel tier systems (Phase P-3)

The biggest tech debt in the marketer module. Two parallel systems on `marketers`:

| Column | Source | Use today |
|---|---|---|
| `price_group_id` | Phase 5 legacy | Some marketers still mapped here |
| `marketer_price_tier_id` | Phase 5.7 | Newer marketers mapped here |

Both reference `marketer_price_groups`. `MarketerPricingResolver` checks BOTH chains. Operator confusion risk is real — Admin UI shows two parallel dropdowns.

### Phase P-3 migration plan

1. Audit data: count marketers using each column. Identify any that have BOTH set (data smell → fix manually).
2. Choose `marketer_price_tier_id` as the survivor (newer, clearer naming).
3. For each marketer where `price_group_id` is set but `marketer_price_tier_id` is null:
   - Find the matching tier code (legacy Bronze → new Silver after rename; Silver → Gold; etc.).
   - Set `marketer_price_tier_id` to the new tier id.
4. Verify no marketer lost their pricing context.
5. Drop the `price_group_id` column from `marketers` (a real Phase P-3 migration; first time in this project we drop a column).
6. Simplify `MarketerPricingResolver` to one chain only.
7. Update Admin UI to show one tier dropdown only.

**Risk: high** — this is a data migration, not just additive. Plan ships:
- Full audit log of the move.
- Dry-run mode (logs intent without writing).
- Rollback migration that re-adds `price_group_id` from the audit log if needed.
- Schedule for a low-traffic operational window.

## 5. Product-specific marketer pricing (existing)

`marketer_product_prices` lets ops set per-(price group, product, variant) overrides. Phase 5.6 extended each row with:

- `shipping_cost`
- `vat_percent`
- `collection_cost`
- `return_cost`

These flow through `MarketerPricingResolver` when an override row exists.

## 6. Country-specific marketer pricing (Phase 4 — Later)

When per-country product pricing lands (Phase 4), the resolver chain extends:

```
1. marketer-specific override for (this marketer, this product, this variant)              ← existing
2. marketer's tier price for (this tier, this product, this variant) — country-scoped     ← new
3. variant-country price for (this variant, this country)                                  ← Phase 4
4. legacy product/variant default                                                          ← existing
5. block (no price → cannot place order)
```

Step 2 currently has no country dimension. Phase 4 adds:

```sql
ALTER TABLE marketer_product_prices ADD COLUMN country_id BIGINT UNSIGNED NULL;
ALTER TABLE marketer_product_prices ADD FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE;
-- Existing unique key extends:
DROP INDEX mpp_grp_prod_var_unique ON marketer_product_prices;
CREATE UNIQUE INDEX mpp_grp_prod_var_country_unique ON marketer_product_prices (marketer_price_group_id, product_id, product_variant_id, country_id);
```

`country_id` nullable → existing rows match all countries (legacy fallback). New rows can target a specific country.

## 7. Suggested selling price & minimum selling price (per tier — existing)

`marketer_product_prices.trade_price` is the marketer's cost (what they pay the company). The marketer sets their own selling price to the end customer, subject to `minimum_selling_price` (system-enforced floor).

Per-tier minimum: stored in `marketer_product_prices.minimum_selling_price`. `ProfitGuardService` blocks below-min for marketer-attached orders.

## 8. Commission type / value (Later)

Today: marketer profit = `selling − VAT − cost − shipping` (formula). Implicit fixed margin per product (the difference between trade_price and selling_price).

Future need (Phase 5+): **percentage-based commission** — "give this marketer X% of net revenue on this product". Adds:

```sql
ALTER TABLE marketer_product_prices ADD COLUMN commission_type ENUM('fixed_margin','percentage') NOT NULL DEFAULT 'fixed_margin';
ALTER TABLE marketer_product_prices ADD COLUMN commission_value DECIMAL(8,2) NULL;
```

`MarketerPricingResolver` branches on `commission_type`:
- `fixed_margin` (default, today's behavior): `profit = selling − VAT − trade_price − shipping`
- `percentage`: `profit = (selling − VAT − cost − shipping) × commission_value / 100`

Out of scope for Phase 0–4 unless ops needs it sooner.

## 9. Marketer profit preview in Order Create (existing — Phase 5.9)

Already shipped:

- Live fetch from `/orders/marketer-profit-preview` debounced.
- Per-line breakdown: selling, VAT, cost, shipping, profit.
- Total profit panel.
- Hidden for users without `orders.view_profit` permission.

Phase O-1 polish:
- Negative-profit highlighting (red).
- Below-min selling warning per line.
- "Source" tag per line (`marketer_specific` / `tier` / `product_default`).

## 10. Wallet & transactions (existing — mature)

`marketer_wallets` snapshot columns: `total_expected`, `total_pending`, `total_earned`, `total_paid`, `balance`.

`marketer_transactions` ledger captures the lifecycle:

- `Expected Profit` — order created
- `Pending Profit` — order delivered (before settlement gate)
- `Earned Profit` — settlement gate passed (per marketer's `commission_after_delivery_only` config)
- `Cancelled` — order cancelled
- `Payout` — money paid to marketer
- `Adjustment` — manual correction

Status transitions are driven by order status changes (`OrderService::changeStatus`).

## 11. Settlement cycles

`marketers.settlement_cycle` ENUM(Daily, Weekly, Monthly). Drives the cron job that moves rows from `Pending` → `Earned` once the cycle window elapses (logic in `MarketerWalletService` — needs Phase P-3 review for clarity).

## 12. Do-now / do-later

### Phase P-3 — Should (medium-risk data migration)
- Rename A/B/D/E display labels to Silver/Gold/Platinum/VIP.
- Audit + migrate legacy `price_group_id` users to `marketer_price_tier_id`.
- Drop `marketers.price_group_id`.
- Simplify `MarketerPricingResolver` to one chain.
- Update Admin UI.

### Phase P-2 — Should
- Profit preview UI polish (red negative, below-min warning, source tag).

### Phase 4 — Later
- Country-aware marketer pricing (extend `marketer_product_prices` with `country_id`).
- Resolver chain extends.

### Later
- Percentage-based commissions (`commission_type` enum).
- Per-marketer per-product audit history (today: only aggregate transactions).
- Marketer self-service price override request workflow (`ApprovalRequest`-based).

## 13. Risks of unification (Phase P-3)

| Risk | Mitigation |
|---|---|
| Marketer points to legacy group only → after migration their pricing context resolves to something unexpected | Pre-migration audit script per-marketer; manual review of edge cases before flipping |
| Migration locks `marketers` table on production | Use `ALTER TABLE ... ALGORITHM=INPLACE, LOCK=NONE` where MySQL 8.4 allows it |
| Resolver behaviour changes silently | Add a new audit-log action `marketer.tier_migrated_v2` capturing before/after per marketer |
| Reports break grouping by `price_group_id` | Sweep `ReportsService` for any join on `marketers.price_group_id` before dropping; rewrite to use `marketer_price_tier_id` |
| Rename A → Silver collides with the legacy "Silver" row that already exists | Pre-migration: rename legacy Silver to "Silver (legacy)" first; then rename A to Silver after legacy row's marketers are migrated; finally drop the legacy Silver row |

## 14. References

- [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md)
- [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md) (profit preview)
- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)
- Existing code: `app/Services/MarketerPricingResolver.php`, `app/Services/MarketerWalletService.php`
