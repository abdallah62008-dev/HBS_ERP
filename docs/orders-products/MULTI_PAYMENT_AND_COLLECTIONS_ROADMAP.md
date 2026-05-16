# Multi-Payment & Collections Roadmap

> Status: **DESIGN ONLY.**

---

## 1. Why one order may need multiple payments

Real operational cases that don't fit the current single-payment model:

| Scenario | Today | Tomorrow |
|---|---|---|
| Customer pays 500 EGP by Vodafone Cash deposit at checkout, remaining 1,500 EGP COD | Not modelled — `cod_amount` is single-shot; the deposit lives nowhere | `order_payments` carries two rows |
| Bank transfer of full amount before shipment, no COD | Operator manually marks `collection_status = Collected` on a 0-COD order; deposit isn't recorded as a payment | A single `order_payments` row of type bank transfer |
| Customer asks for refund of half the order; remaining stays | `refunds` ties to one collection; doesn't update an outstanding-balance figure on the order | Refund creates a negative `order_payments` row |
| Marketplace prepayment (Amazon collected, settlement pending) | No model for prepayment | `order_payments` row with status `Pending Settlement` |
| Failed delivery + partial refund | Manual reconciliation | `order_payments` cancel + refund rows |
| Multi-currency edge case | Not supported | One row per currency in `order_payments`; explicit |

Today: `orders.cod_amount` = total. `collections` table is 1:1 per order with `amount_due` and `amount_collected`. This is **fine** for pure-COD orders but breaks anywhere multi-method, split, or pre-paid logic is involved.

## 2. Target: `order_payments` table (Phase O-5)

```sql
CREATE TABLE order_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id BIGINT UNSIGNED NOT NULL,
    payment_method_id BIGINT UNSIGNED NOT NULL,
    cashbox_id BIGINT UNSIGNED NULL,

    amount DECIMAL(12,2) NOT NULL,                  -- positive = inflow, negative = refund
    currency_code CHAR(3) NOT NULL,

    status ENUM(
        'Pending',
        'Paid',
        'Failed',
        'Refunded',
        'Partially Refunded',
        'Settlement Pending',
        'Settlement Received'
    ) NOT NULL DEFAULT 'Pending',

    reference_number VARCHAR(128) NULL,              -- gateway txn id, bank receipt, etc.
    paid_at TIMESTAMP NULL,
    notes TEXT NULL,

    -- Lifecycle metadata
    collection_id BIGINT UNSIGNED NULL,              -- when this payment is the COD leg
    refund_id BIGINT UNSIGNED NULL,                  -- when this row is a refund

    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,

    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
    FOREIGN KEY (cashbox_id) REFERENCES cashboxes(id) ON DELETE SET NULL,
    FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE SET NULL,
    FOREIGN KEY (refund_id) REFERENCES refunds(id) ON DELETE SET NULL,

    INDEX op_order_status_index (order_id, status),
    INDEX op_paid_at_index (paid_at),
    INDEX op_method_index (payment_method_id)
);
```

### Field rationale

- `amount` is signed: positive payments and negative refunds live in the same table for a unified ledger.
- `currency_code` per row: a future order in SAR shouldn't be misread by an EGP report.
- `status` covers the full lifecycle:
  - `Pending` — submitted but not confirmed
  - `Paid` — confirmed received
  - `Failed` — gateway failure / NSF / bounce
  - `Refunded` — fully reversed
  - `Partially Refunded` — partial reversal (negative `order_payments` row added)
  - `Settlement Pending` — marketplace pre-paid, awaiting platform settlement
  - `Settlement Received` — platform settled
- `reference_number` is free-text — gateway txn id, bank receipt, courier waybill number.
- `collection_id` / `refund_id` cross-link to existing models without duplicating data.

## 3. Outstanding balance — computed view

```sql
SELECT
    o.id,
    o.total_amount,
    COALESCE(SUM(
        CASE
            WHEN op.status IN ('Paid', 'Settlement Received') THEN op.amount
            ELSE 0
        END
    ), 0) AS total_paid,
    o.total_amount - COALESCE(SUM(
        CASE
            WHEN op.status IN ('Paid', 'Settlement Received') THEN op.amount
            ELSE 0
        END
    ), 0) AS outstanding_balance
FROM orders o
LEFT JOIN order_payments op ON op.order_id = o.id
GROUP BY o.id;
```

Implemented as a service method `OrderPaymentService::outstandingBalance(Order $order): float`. Surfaced on Order Show + index list.

## 4. Lifecycle of an order_payments row

```
Pending ──┬─→ Paid ──────┬─→ Refunded (full reversal: add negative row)
          │              │
          │              └─→ Partially Refunded (add negative partial row, status stays Paid on original)
          │
          ├─→ Failed (NSF / gateway decline / bounce)
          │
          └─→ Settlement Pending ─→ Settlement Received
```

State machine:
- `Pending → Paid` via `OrderPaymentService::markPaid()`. Records `paid_at`. Posts a `cashbox_transactions` row (cashbox ledger entry).
- `Pending → Failed`. No cashbox impact.
- `Paid → Refunded` via the existing refund flow. Creates an associated `refunds` row + a negative `order_payments` row referencing the refund.
- `Settlement Pending → Settlement Received` for marketplace prepayments. Cashbox entry posted at settlement.

## 5. Integration with existing modules

### Cashboxes (Phase 5A — `cashboxes`, `cashbox_transactions`)
- Every `Paid` or `Settlement Received` payment posts a `cashbox_transactions` row referencing the `order_payments.id`.
- Cashbox daily-close reads `cashbox_transactions` for the period; no direct read of `order_payments`.

### Collections (existing — `collections`)
- A `Paid` payment on a COD-flagged `payment_method` updates the linked `collections` row's `amount_collected`.
- The 1:1 `collections-per-order` relationship is preserved; collections becomes a denormalized "COD bucket" sitting alongside the broader `order_payments` ledger.
- New rule: when `order_payments` carries one and only one row of `payment_method.type = 'courier_cod'`, that row's lifecycle drives `collections.collection_status`.

### Refunds (Phase 5A — `refunds`)
- A refund creates a new `order_payments` row with negative `amount` and the refund's `id`.
- Existing refund flow stays — request → approve → paid. The paid step now also posts a negative `cashbox_transactions` entry and the negative `order_payments` row.

### Finance reports (Phase 5A — `FinanceReportsService`)
- Cash inflows for period: `SUM(order_payments.amount) WHERE status IN ('Paid','Settlement Received') AND paid_at BETWEEN ...`.
- Outstanding receivables: `SUM(orders.total_amount - paid_per_order)`.
- Refunds for period: `SUM(-order_payments.amount) WHERE status = 'Refunded' AND amount < 0`.

## 6. UI shape

### Order Show — "Payments" section (Phase O-5)

```
Payments                                                     [ + Record payment ]
──────────────────────────────────────────────────────────────────────────────
Method            Amount      Status               Ref.           Paid at
──────────────────────────────────────────────────────────────────────────────
Vodafone Cash      500.00     Paid                 VC-2026-987    2026-05-10
Courier COD      1,500.00     Pending              —              —
──────────────────────────────────────────────────────────────────────────────
Total paid          500.00
Total due         2,000.00
Outstanding       1,500.00
```

Each row: edit / cancel / refund / mark paid actions (permission-gated).

### Order Create — initial payment
- Operator may optionally record an upfront payment (bank transfer, deposit) at order create time. Defaults to "single COD payment for `total_amount`" if nothing is entered.

## 7. Order create flow (post-Phase O-5)

```text
1. Items + customer + totals → orders row created
2. OrderPaymentService::seedPayments() inspects payload:
     - If payload has explicit payment rows → insert each as order_payments
     - Else → create one row: payment_method_id = courier_cod, amount = total_amount, status = Pending
3. cod_amount on orders is recalculated from courier_cod rows only (for backwards compat)
4. collections row created for the courier_cod bucket (existing logic)
```

## 8. Backward compatibility

- `orders.cod_amount` stays — computed from `order_payments` of type `courier_cod`. Existing reports reading `cod_amount` continue working.
- `orders.total_paid_amount` (computed/cached column) reflects `SUM(order_payments WHERE status='Paid')`.
- `collections` table stays — the COD bucket remains the operational queue for the courier finance team.

## 9. Permissions involved

| Slug | Granted to | Purpose |
|---|---|---|
| `payments.view` | every role with `orders.view` | Read order payments |
| `payments.record` | Manager+ | Add a payment row |
| `payments.mark_paid` | Manager+ | Move row from Pending → Paid |
| `payments.approve_refund` | Admin+ | Approve a Refund record |
| `payments.cancel` | Admin+ | Cancel a pending payment |

## 10. Reports unlocked by `order_payments`

- Cash-in by payment method × period.
- Outstanding receivables by customer / city / marketer.
- Settlement lag (Settlement Pending → Settlement Received).
- Refund rate by payment method.
- Failed-payment rate by gateway.

Detail in [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md).

## 11. Do-now / do-later

### Phase O-5 — Should
- `order_payments` table.
- `OrderPaymentService` (record, mark paid, refund, cancel, outstanding balance).
- Order Show "Payments" section.
- Order Create payload accepts optional initial payment rows.
- Existing `collections` + `refunds` integration.
- 4 new permission slugs.

### Later
- Per-order multi-currency split (rare; foreign customer paying in USD).
- Payment gateway integrations (Stripe / Paymob / Tabby) — drives `payment_methods` configuration.
- Recurring payment plans (subscription orders).
- Marketplace settlement reconciliation jobs.

## 12. Risks

| Risk | Mitigation |
|---|---|
| Migration of existing single-payment orders | One-time backfill: every existing order → one `order_payments` row with `payment_method = courier_cod`, amount = `total_amount`, status derived from `collections.collection_status` |
| Cashbox daily close drift during cutover | Run the backfill on a quiet day; reconcile against `cashbox_transactions` before going live |
| Refund flow refactor breaks existing approvals | Keep `refunds` table unchanged; only the *consequences* (cashbox + payment row) change |
| `total_paid_amount` cached column goes stale | Either recompute on every order_payments mutation (event listener) or treat as derived (no cache); recommend derived for now |

## 13. References

- [GOVERNANCE_PERMISSIONS_AND_APPROVALS.md](./GOVERNANCE_PERMISSIONS_AND_APPROVALS.md)
- [REPORTING_ROADMAP.md](./REPORTING_ROADMAP.md)
- Existing: `collections`, `refunds`, `cashboxes`, `cashbox_transactions`, `payment_methods`
