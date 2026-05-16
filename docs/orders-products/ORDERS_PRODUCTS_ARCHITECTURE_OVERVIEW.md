# Orders & Products — Architecture Overview

> Status: **DESIGN ONLY.**

---

## 1. Current architecture (what's in `main` today)

### Products
- Single `products` table — global pricing (cost / selling / marketer_trade / minimum_selling), tax flag + rate, reorder level, status, SKU + barcode, supplier FK, category FK.
- `product_variants` mirrors the same price columns; one row per SKU variant.
- `categories` is a `parent_id` self-tree.
- `product_price_history` tracks old/new of cost/selling/trade.
- `marketer_product_prices` (extended Phase 5.6) carries per-(price group, product, variant) overrides + shipping/VAT/collection/return cost.
- `MarketerPricingResolver` chains: specific → tier → product default.
- **No** brand table, **no** family table, **no** channel/marketplace SKU mapping, **no** per-country pricing, **no** quantity tiers.

### Orders
- `orders` table — 60+ columns. Three status dimensions (`status`, `collection_status`, `shipping_status`), customer snapshot strings (`customer_name`/`customer_phone`/`customer_address`/`city`/`governorate`/`country`), Phase 5.8 phone+WhatsApp fields, Phase 5.9 marketer profit columns, soft-delete, audit columns.
- `order_items` — snapshots `product_name`, `sku`, `unit_price`, `unit_cost`, `marketer_trade_price`, Phase 5.9 `marketer_shipping_cost` + `marketer_vat_percent`. **Does NOT** snapshot brand, category, supplier, product_status, vat_rate-at-time-of-sale, vat_inclusive_flag.
- `OrderService::buildItemRows` writes the snapshot at create.
- `OrderService::computeTotals` is the **sole writer** of profit math.
- `OrderService::changeStatus` centralises lifecycle + inventory hooks (reserve / release / ship / restock-on-return).
- `ProfitGuardService` checks min-selling and min-profit at create-time; throws or flags for approval.

### Customers
- `customers` — `primary_phone` (raw string, indexed), `secondary_phone`, Phase 5.8 `primary_phone_whatsapp` flag.
- `customer_addresses` — multi-address, `is_default` flag.
- `DuplicateDetectionService` — phone+name+city+address fuzzy match.

### Payments + finance
- `cashboxes` (Phase 5A), `payment_methods` (lookup), `cashbox_transactions`.
- `collections` — 1:1 per order for COD, `amount_due` / `amount_collected` / `collection_status`.
- `refunds` (Phase 5A) — request → approve → paid flow (partial — paid step not built).
- **No** `order_payments` table → can't split a single order across multiple payment methods.

### Addresses
- `countries` / `states` / `cities` lookups exist (Egypt + Saudi Arabia seeded).
- Customer + order tables carry **free-text** country/governorate/city strings — FK not enforced.
- **No** districts, **no** shipping zones.

### Shipping
- `shipments`, `shipping_companies`, `shipping_rates` (country/governorate/city granularity), `shipping_labels`.
- `ShippingService::generateLabelPdf` produces a 4×6 PDF (mPDF, Phase 6.5).
- Order status workflow: New → Confirmed → Packed → Shipped → Delivered.

### Marketers
- `marketers`, `marketer_price_groups`, `marketer_product_prices`, `marketer_wallets`, `marketer_transactions`.
- Two parallel tier systems on `marketers`: legacy `price_group_id` (Bronze/Silver/Gold/VIP) and Phase 5.7 `marketer_price_tier_id` (A/B/D/E).
- `MarketerPricingResolver` chain runs through both.

### Reports
- `ReportsService` — 9 order/product methods (profit, productProfitability, returns, marketerPerformance, sources, cities, AOV, shippingPerformance, collections).
- `FinanceReportsService` (Phase 5A) — finance-side.

## 2. Target architecture (where Phase 0 docs point us)

```
                     ┌───────────────────────────┐
                     │         PRODUCTS          │
                     │                           │
                     │  products + variants      │
                     │     + brand_id            │  ⭐ Phase 1
                     │     + product_status      │
                     │                           │
                     │  product_channel_skus     │  ⭐ Phase 1
                     │  (per variant × channel)  │
                     │                           │
                     │  product_country_prices   │  ◯ Phase 4
                     │  (per variant × country)  │
                     │                           │
                     │  product_price_tiers      │  ◯ Phase 5
                     │  (quantity-based)         │
                     │                           │
                     │  marketer_product_prices  │  (existing, unified Phase 3)
                     │                           │
                     │  product_price_history    │  (existing)
                     └────────────┬──────────────┘
                                  │
                       snapshot at order create
                       (append-only, never refreshes)
                                  │
                                  ▼
                  ┌───────────────────────────────┐
                  │            ORDERS             │
                  │                               │
                  │  orders                       │
                  │   ├─ status ───────────────┐  │
                  │   ├─ collection_status     │  │
                  │   ├─ shipping_status       │  │
                  │   └─ marketer_profit       │  │
                  │                            │  │
                  │  order_items               │  │
                  │   ├─ product_id            │  │
                  │   ├─ brand_snapshot        ⭐ │
                  │   ├─ category_snapshot     ⭐ │
                  │   ├─ supplier_snapshot     ⭐ │
                  │   ├─ vat_rate_snapshot     ⭐ │
                  │   ├─ vat_inclusive_flag    ⭐ │
                  │   ├─ unit_price (existing)    │
                  │   ├─ unit_cost (existing)     │
                  │   └─ marketer_* (existing)    │
                  │                               │
                  │  order_payments  ⭐ Phase 5   │
                  │                               │
                  └────────────┬──────────────────┘
                               │
       ┌───────────────────────┼─────────────────────────────┐
       │                       │                             │
       ▼                       ▼                             ▼
┌──────────────┐       ┌──────────────┐             ┌──────────────┐
│  INVENTORY   │       │   RETURNS    │             │ MARKETER     │
│ movements    │       │ items+reason │             │ wallets +    │
│ reservations │       │ conditions   │             │ transactions │
└──────┬───────┘       └──────┬───────┘             └──────┬───────┘
       │                      │                             │
       │                      ▼                             │
       │              ┌──────────────┐                      │
       │              │   REFUNDS    │                      │
       │              └──────┬───────┘                      │
       │                     │                              │
       │                     ▼                              │
       │              ┌──────────────┐                      │
       └──────────────▶│   FINANCE   │◀─────────────────────┘
                      │ + cashboxes  │
                      │ + collections│
                      │ + reports    │
                      └──────────────┘
                              │
                              ▼
                      ┌──────────────┐
                      │   REPORTS    │
                      │  + dashboard │
                      └──────────────┘

                              │
                              ▼
                      ┌──────────────┐
                      │   SHIPPING   │
                      │ + zones      │  ◯ Phase 6
                      │ + districts  │  ◯ Phase 3
                      └──────────────┘

                              │
                              ▼
                      ┌──────────────┐
                      │   WHATSAPP   │
                      │  automation  │  ◯ Phase 7+
                      └──────────────┘

LEGEND:  ⭐ = added/extended in Phase 1–5    ◯ = later phases
```

## 3. Module-by-module integration

### Orders ↔ Inventory
- Confirmed status → `Reserve` movement.
- Shipped status → `Sale` movement (consumes the reservation).
- Returned + good inspection → `Return To Stock` movement.
- Returned + damaged → `Damaged` movement; no restock.

### Orders ↔ Returns
- 1:1 (or many returns per order in legacy data; new returns blocked by `OrderReturnRequest` validation per Phase 5.6B).
- Return moves through Intake → Inspection → Close.
- Inspection result drives Inventory + Refund integrations.

### Orders ↔ Refunds (Phase 5A)
- Approved refund references the order; can split refund destination (cashbox vs original payment method).
- Refund amount flows back to the customer; updates `orders.total_paid_amount` (planned in `order_payments` work).

### Orders ↔ Finance (Phase 5A)
- `FinanceReportsService` aggregates order revenue, costs, marketer profit, refunds, expenses.
- Cashbox reconciliation: daily / weekly close.

### Orders ↔ Cashboxes
- Today: implicit via collections.
- After `order_payments` (Phase 5): each payment row points to a cashbox, becomes a `cashbox_transaction` for the cashbox ledger.

### Orders ↔ Marketers
- Order may carry `marketer_id` (Phase 5.9).
- `MarketerPricingResolver` resolves cost / shipping / VAT for the line.
- Wallet transaction created when the order moves through statuses.

### Orders ↔ Shipping
- Each order may have N `shipments` (split shipments rare).
- Shipping rate looked up by (carrier, country, governorate, city).
- `shipping_label_pdf` generated on demand.

### Orders ↔ Reports
- All summary reports filter or group on order fields (`status`, `marketer_id`, `source`, `city`, `created_at`, `delivered_at`).

### Orders / Customers ↔ WhatsApp automation (future)
- Requires phone normalization to E.164 (Phase 2).
- Order status changes trigger WhatsApp template messages.
- WhatsApp inbound becomes a Customer Service Ticket (existing Phase 7 schema).

## 4. Cross-cutting invariants

1. **Snapshot is append-only**. `order_items` rows never refresh from `products` after creation. (Detail in `ORDER_FINANCIAL_SNAPSHOT_POLICY.md`.)
2. **Single source of truth for order profit**: `OrderService::computeTotals`. Other services compute *reads* off the persisted columns.
3. **Marketer ownership scope fail-closed**: a user marked marketer-role with no `marketer` row sees zero orders, never every order.
4. **Backend-enforced RBAC**: every sensitive route carries `permission:` middleware; UI hiding is sugar only.
5. **Currency is per row** (per order, per country price); never auto-converted.
6. **Phone is normalized** (post-Phase 2). Raw input is kept for audit; `normalized_phone` is what duplicate detection + WhatsApp use.

## 5. Cross-references

- Roadmap: [IMPLEMENTATION_PHASES.md](./IMPLEMENTATION_PHASES.md)
- Snapshot policy: [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md)
- Pricing: [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md)
- Master data: [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md)
