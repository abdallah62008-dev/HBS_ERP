# Reporting Roadmap

> Status: **DESIGN ONLY.**

---

## 1. Today's report coverage

`ReportsService` ships 9 methods touching orders / order_items / customers:

| Method | What it does |
|---|---|
| `profit($from, $to)` | Gross/net profit on delivered orders, by day |
| `productProfitability($from, $to)` | Top 50 by revenue; units, revenue, units_delivered, units_returned, return_rate, gross_profit |
| `unprofitableProducts($from, $to)` | Revenue > 0 but gross_profit ≤ 0 |
| `inventory()` | Snapshot on-hand / reserved / available for top 200 active products |
| `stockForecast($days = 30)` | Burn-rate based days-of-stock; top 60 by depletion |
| `shippingPerformance($from, $to)` | Delivery rate, return rate per carrier |
| `collections($from, $to)` | Due vs collected, by status |
| `returns($from, $to)` | Return count, return rate per product; by reason, status, condition |
| `marketerPerformance($from, $to)` | Orders count, returned, net_profit per marketer |
| `sources($from, $to)` | Orders by source channel with counts/revenue/net_profit |
| `cities($from, $to)` | Delivered/returned counts, gross_profit, net_profit per city |
| `aov($from, $to)` | Average order value filtered by status/date |

(Returns + cashflow + finance reports live in their own services — `FinanceReportsService`, plus dedicated returns reporting added recently.)

## 2. Product reports — gaps

Required for the Phase 0 ambition:

| Report | Status | Phase |
|---|---|---|
| **Top selling products** (by units, by revenue) | ⚠️ partial — `productProfitability` is by revenue only | **Phase P-5** add `topSelling($from, $to, $orderBy='units'|'revenue', $limit)` |
| **High return products** (return rate) | ✅ exists inside `productProfitability` | — |
| **Low margin products** | ❌ missing | **Phase P-5** add `lowMarginProducts($from, $to, $marginThreshold)` |
| **Out of stock products** | ⚠️ partial — `inventory()` flags `is_low_stock` but no dedicated OOS list | **Phase P-5** add `outOfStockProducts()` |
| **Slow moving products** | ❌ missing | **Phase P-5** add `slowMovingProducts($daysSinceLastSale)` |
| **Profit by SKU** | ⚠️ partial — `productProfitability` is per-product; SKU breakdown needs variant join | **Phase P-5** extend |
| **Profit by brand** | ❌ missing — depends on Phase 1 brand + Phase O-4 snapshot | **Phase P-5 (after O-4)** add `profitByBrand($from, $to)` |
| **Profit by country** | ❌ missing — depends on Phase 4 | **Phase 4+** add `profitByCountry($from, $to)` |
| **Profit by marketer** | ✅ `marketerPerformance` covers it | — |

## 3. Order reports — gaps

| Report | Status | Phase |
|---|---|---|
| **Confirmation rate** (Confirmed / New ratio) | ❌ missing | **Phase 8** add `confirmationRate($from, $to)` |
| **Cancellation rate** (Cancelled / Total) | ❌ missing | **Phase 8** add `cancellationRate($from, $to)` |
| **Delivery rate** (Delivered / Shipped) | ✅ inside `shippingPerformance` | — |
| **Return rate** (Returned / Delivered) | ✅ inside `productProfitability` and `marketerPerformance` | — |
| **COD collection rate** | ✅ `collections()` covers it | — |
| **AOV** (average order value) | ✅ `aov()` exists | — |
| **Average profit per order** | ❌ missing | **Phase 8** add `avgProfitPerOrder($from, $to)` |
| **Orders by city** | ✅ `cities()` exists | — |
| **Orders by source** | ✅ `sources()` exists | — |
| **Orders by marketer** | ✅ `marketerPerformance()` exists | — |
| **Orders by channel** | ❌ missing — depends on Phase 1 channel SKU + Phase O-4 snapshot of channel | **Phase 8** add `ordersByChannel($from, $to)` |

## 4. New report design rules

### Date-range parameter
Every public report method follows the existing `dateRange($from, $to)` helper. Date-range filters default to current month (matches the just-shipped `15d7543` standardization).

### Use snapshots, not live joins
Reports that group by brand / category / supplier / channel MUST use the snapshot columns on `order_items` (Phase O-4), not the live FKs. This ensures historical accuracy across product renames / recategorizations.

```sql
-- CORRECT (snapshot-based):
SELECT brand_id_snapshot, SUM(quantity * unit_price - discount) AS revenue
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
WHERE o.status = 'Delivered'
  AND o.delivered_at BETWEEN ? AND ?
GROUP BY brand_id_snapshot;

-- WRONG (live join — drifts when brand changes):
SELECT b.id, SUM(...)
FROM order_items oi
JOIN products p ON oi.product_id = p.id
JOIN brands b ON p.brand_id = b.id
...
```

### Brand / category / supplier null handling
Pre-Phase-O-4 rows have NULL snapshots. Reports surface a "Legacy / Unknown" bucket. After backfill (optional), the bucket shrinks.

### Index strategy
Each new snapshot column gets an index (in the Phase O-4 migration):
- `oi_brand_id_snapshot_index`
- `oi_category_id_snapshot_index`
- `oi_supplier_id_snapshot_index`
- (channel SKU goes through a different snapshot mechanism — TBD)

## 5. Report UI shape

Pages live under `Pages/Reports/`. Each follows the existing pattern:

- Header with date range picker (matches `15d7543` standardization).
- Filter bar: status / marketer / brand / source where applicable.
- Table.
- Summary tiles (totals).
- Export to CSV / XLSX (existing `maatwebsite/excel`).

Brand / channel filters appear on relevant pages **only after Phase 1 + Phase O-4** ship. Until then, hide the filter.

## 6. New permission slugs

Existing report slugs cover most cases (`reports.view`, `reports.sales`, `reports.profit`, `reports.marketers`, etc.). Phase P-5 / Phase 8 might add:

| Slug | Purpose |
|---|---|
| `reports.brand` | Brand-scoped reports |
| `reports.channel` | Channel-scoped reports |
| `reports.country` | Country-scoped reports |

Add these only when the corresponding feature ships and the report becomes interesting.

## 7. Do-now / do-later

### Phase O-4 prerequisites
- Snapshot columns on `order_items` (already in [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md)).
- Optional one-time backfill of snapshots from current product state.

### Phase P-5 — Should
- `topSelling`, `lowMarginProducts`, `outOfStockProducts`, `slowMovingProducts`, `profitByBrand` methods.
- New `Pages/Reports/{TopSelling,LowMargin,OOS,SlowMoving,ProfitByBrand}.jsx` pages.

### Phase 8 — Should
- `confirmationRate`, `cancellationRate`, `avgProfitPerOrder`, `ordersByChannel`.
- New report pages.

### Phase 4+ — Later
- `profitByCountry` (depends on `product_country_prices` data).

### Later (cross-cutting)
- Drill-down from any aggregate report to the underlying orders.
- Saved filter presets per user.
- Scheduled email digest (cron job that emails managers daily / weekly KPIs).
- Public KPI dashboards for marketers (their own data only).

## 8. Risks

| Risk | Mitigation |
|---|---|
| Reports using live joins drift after Phase 4 / P-3 changes | Document the "use snapshot, not live join" rule; add a linter or test that catches violations |
| Brand / category aggregates show "Unknown" bucket dominantly for old data | Run the optional backfill; document the bucket in report headers |
| Query performance on `order_items` with new indexes | Composite indexes (`brand_id_snapshot, created_at`) for time-windowed queries |
| Report list grows unwieldy | Group reports into categories: Product, Order, Marketer, Finance, Shipping, Returns. Already partially done via Reports sidebar group |

## 9. References

- [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md) — snapshot fields powering brand/category/channel reports
- [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md) — brand schema
- [CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md](./CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md) — channel data
- Existing: `app/Services/ReportsService.php`, `app/Services/FinanceReportsService.php`, `app/Services/DashboardMetricsService.php`
