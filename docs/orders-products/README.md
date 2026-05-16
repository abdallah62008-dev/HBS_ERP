# Orders & Products — Phase 0 Documentation

> Status: **DESIGN ONLY**. This folder is the agreed reference for the next round of Orders + Products upgrades. No code in this phase. Schema and feature changes described here are *planned*; nothing in `main` has been altered as part of Phase 0.

---

## 1. Why this upgrade

Orders and Returns have been the operational priority for the past several months. Returns + finance + refunds are in good shape. The remaining gaps are in the **upstream** modules — Products and Order Create — and in the **historical-accuracy guarantees** of `order_items`.

The system's product master data is still simple/single-country, single-channel:

- One `selling_price` / `cost_price` per product — no per-country, per-marketplace prices.
- No brand, family, or channel-SKU tables — you can't tie a Noon order to your internal SKU.
- Customer phones are raw strings — duplicate-customer detection is fuzzy, WhatsApp automation later will require an E.164 field.
- `order_items` snapshot prices but **not** brand, category, supplier — historical reports drift if a product is renamed or recategorized.
- A single "Create order" button (no Save & Add New / Save & Print Label / draft mode).

These all become easier to fix while there is one operational team and a small data set. Defer past that, and they will require migrations against large production tables.

## 2. Scope

| Module | In scope for Phase 0 docs |
|---|---|
| Products & variants | ✅ master data, brand, channel SKUs, pricing UX, VAT, marketer pricing, country pricing |
| Orders & order_items | ✅ create UX, save variants, snapshot policy, multi-item, search, validation |
| Customers | ✅ phone normalization, WhatsApp readiness, address hierarchy, duplicate detection |
| Payments | ✅ multi-payment order_payments model, cashbox + collections + refunds integration |
| Shipping | ✅ countries / governorates / cities / districts / shipping zones — light coverage; full plan in a later phase |
| Marketers | ✅ pricing tier consolidation, country-aware pricing |
| Reports | ✅ product + order analytics roadmap |
| Permissions | ✅ governance + approval handlers |

## 3. What's intentionally deferred

- **Bundles / kits** — adds composite-SKU logic; mid-priority for retail. Not Phase 0–4.
- **Serial / lot tracking** — needed only for high-value SKUs (electronics, cosmetics with expiry). Not Phase 0–4.
- **Marketplace integrations (Bosta / Aramex / Mylerz APIs)** — depends on shipping zones; deferred to a later Phase.
- **WhatsApp automation** — planned but unimplemented. Phase 0–2 work (phone normalization) is the precondition.
- **Tax compliance — e-invoicing (الفاتورة الإلكترونية)** — Egypt requirement. Out of Phase 0 scope; will need its own document.
- **Multi-currency conversion** — explicitly NOT in scope. Each country stores its own currency; we never convert across.

## 4. Document map

| Doc | Subject |
|---|---|
| [README.md](./README.md) | This document |
| [ORDERS_PRODUCTS_ARCHITECTURE_OVERVIEW.md](./ORDERS_PRODUCTS_ARCHITECTURE_OVERVIEW.md) | Current vs target architecture; integration map |
| [PRODUCT_MASTER_DATA_ROADMAP.md](./PRODUCT_MASTER_DATA_ROADMAP.md) | Brand, family, variant, channel SKU model |
| [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md) | Cost/sale/min, VAT inclusive vs exclusive, calculators |
| [CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md](./CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md) | Internal SKU vs marketplace SKU mapping table |
| [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md) | Order statuses, draft mode, save variants, warnings |
| [PHONE_ADDRESS_AND_WHATSAPP_READINESS.md](./PHONE_ADDRESS_AND_WHATSAPP_READINESS.md) | E.164 phone normalization, country-coded validation |
| [ORDER_FINANCIAL_SNAPSHOT_POLICY.md](./ORDER_FINANCIAL_SNAPSHOT_POLICY.md) | What `order_items` must snapshot; append-only rule |
| [MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md](./MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md) | `order_payments` model; integration with cashboxes / refunds / finance |
| [MARKETER_PRICING_AND_PROFIT_ROADMAP.md](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md) | Marketer tier consolidation; per-country marketer pricing |
| [SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md](./SHIPPING_AND_LOCATION_ENGINE_ROADMAP.md) | Country → governorate → city → district; shipping zones |
| [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md) | Sensitive-action gating, approval handlers |
| [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md) | Product + order analytics |
| [IMPLEMENTATION_PHASES.md](./IMPLEMENTATION_PHASES.md) | Phased delivery plan (Phase 0 → P-1 → O-1 → …) |
| [QA_CHECKLIST.md](./QA_CHECKLIST.md) | Manual QA checklist for each phase |

## 5. Reading order

For a new contributor, read in this order:

1. This README.
2. `ORDERS_PRODUCTS_ARCHITECTURE_OVERVIEW.md` — what we have, what we want.
3. `IMPLEMENTATION_PHASES.md` — the plan.
4. Whichever phase doc applies to your current task.

## 6. Recommended first coding phase

After Phase 0 docs are merged: **P-1 — Brand + Channel SKU Foundation**. Reasons:

- Lowest risk: pure additive schema (new tables + nullable FK on `products`).
- Highest downstream value: unlocks reports by brand, marketplace integration, and SKU disambiguation.
- Doesn't touch the order create / pricing hot paths.

Detail in [IMPLEMENTATION_PHASES.md](./IMPLEMENTATION_PHASES.md).

## 7. Non-negotiable invariants (carry over from Phase 0–5 of the wider system)

These hold regardless of any change in this document set:

1. **Movement-based inventory** — no stored `quantity` column.
2. **`OrderService::computeTotals` is the sole writer** of order profit math.
3. **Order item-level edits are locked post-create**; changes go through `ApprovalRequest`.
4. **`firstOrNew(marketer_id, order_id)` profit-row uniqueness**.
5. **`display_order_number` is an accessor**, never persisted.
6. **Audit logging is mandatory** on every entity mutation.
7. **Marketer ownership scope is fail-closed**.
8. **`order_items` snapshots are append-only** — they never refresh when the underlying product changes (this doc set tightens that policy in `ORDER_FINANCIAL_SNAPSHOT_POLICY.md`).

## 8. Status banner

| | |
|---|---|
| Phase | **0 — Documentation** |
| Date | 2026-05-11 |
| Author | Engineering + Operations |
| Code changed | **None** |
| Schema changed | **None** |
| Reviewers | TBD |
| Sign-off needed for | All decisions in this folder before P-1 begins |
