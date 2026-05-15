# Returns — Refunds & Finance Boundaries

> **Companion to:** [RETURNS_ARCHITECTURE_OVERVIEW.md](RETURNS_ARCHITECTURE_OVERVIEW.md) · [docs/finance/](../finance/)
> **Purpose:** Pin the wall between the Returns module and the Finance module. A return is a *lifecycle record*; a refund is a *money movement*. They are linked but separate, with different lifecycles, different permissions, and different period guards.

---

## 1. The rule, in one sentence

> **A return can exist without a refund. A refund cannot exist without explicit user action. Closing a return does NOT pay anything.**

This is the single most important rule in this module. Everything below is a clarification of it.

---

## 2. Why separate them

A return and a refund answer different questions:

| Concern | Return answers | Refund answers |
|---|---|---|
| Did the customer send goods back? | yes / no | irrelevant |
| What condition? | Good / Damaged / Missing / Unknown | irrelevant |
| Can stock be reused? | yes / no | irrelevant |
| How much is the customer owed? | the *intent* (`refund_amount`) | the *commitment* (`amount`) and the *payment* (a cashbox OUT) |
| When is it final? | when `return_status = Closed` | when `refund.status = paid` |
| Who decides? | warehouse / order agent | accountant + manager (separation of duties) |
| Where is the money? | nowhere — returns are paperwork | a `cashbox_transactions` row with `source_type='refund'` |

A damaged return that the company is **not** going to refund (because the damage is the courier's fault, or because the customer accepts a replacement instead) is a perfectly valid record. The return row carries the lifecycle; no refund row is created.

A refund that does **not** stem from a return (e.g. a goodwill refund, a billing-mistake refund) is also valid — the `refunds.order_return_id` is nullable.

---

## 3. The `refund_amount` field on a return — what it is and what it isn't

`OrderReturn.refund_amount` is a **decimal:2** field that stores the *intended refund value*. It is **not**:

- A posted refund.
- A commitment.
- A debt in any accounting sense.

It is the inspector's note — "if we end up refunding the customer for this return, this is the number we're targeting." It can change (within rules) until a real refund is created against the return:

- Reductions are bounded: the new `refund_amount` cannot drop below the sum of *active* refunds already linked to this return. Active = `requested | approved | paid`. This is enforced in `ReturnService::updateDetails`.
- Increases are unbounded (no upper cap from the Returns side; the Finance side has its own caps).
- After the return is `Closed`, the field is locked entirely (`updateDetails` throws).

The frontend (Returns Show page) calls this field "Refund amount" and pairs it with `Shipping loss` and `Notes` in a small Edit form. Operators reading the page must understand that this field represents *intent*, not a payment promise.

---

## 4. Lifecycle separation — visualised

```
        ┌──────────────────────────────────────────────────────┐
        │                  RETURN LIFECYCLE                    │
        │                                                      │
        │   Pending → Inspected → (Restocked | Damaged) →      │
        │                                              Closed  │
        │                                                      │
        │   Owns: condition, inventory effects, refund INTENT  │
        │   Permission slugs: returns.* + refunds.create       │
        └────────────────────┬─────────────────────────────────┘
                             │
                             │ ReturnsController::requestRefund
                             │ (one explicit user action per refund)
                             ▼
        ┌──────────────────────────────────────────────────────┐
        │                  REFUND LIFECYCLE                    │
        │                                                      │
        │   requested → approved → paid                        │
        │             ↘ rejected                               │
        │                                                      │
        │   Owns: refund commitment, cashbox OUT posting,      │
        │         fiscal-period guard, signed approval         │
        │   Permission slugs: refunds.{view,create,approve,    │
        │                              reject,pay}             │
        └────────────────────┬─────────────────────────────────┘
                             │ Refund::pay()
                             ▼
        ┌──────────────────────────────────────────────────────┐
        │              CASHBOX TRANSACTION                     │
        │                                                      │
        │   source_type = 'refund'                             │
        │   direction = 'out'                                  │
        │   reference = Refund::class + refund.id              │
        └──────────────────────────────────────────────────────┘
```

Three independent state machines. The arrows are **one-way explicit transitions** triggered by named user actions — there is no auto-payment.

---

## 5. The cross-module endpoint

`ReturnsController::requestRefund` is the single bridge:

```
POST /returns/{return}/request-refund
        │
        │  permission: refunds.create
        │  validates: amount > 0, reason ≤ 2000 chars
        ▼
RefundService::createFromReturn($return, $user, $data)
        │
        │  guards: return.canRequestRefund() — must be in REFUND_ELIGIBLE_STATUSES
        │          + refund_amount remaining > 0
        │  creates: Refund row in `requested`
        │  links:   refund.order_return_id = return.id
        │  audit:   audit_logs row written
        ▼
Redirect to /refunds (Finance page) with flash message
```

After this endpoint runs, the Returns module's job is done. The refund moves through the Finance pipeline (`refunds.approve` then `refunds.pay`), at which point a `cashbox_transactions` row is written. Neither approval nor payment touches the return row.

---

## 6. What MUST NOT happen

These are anti-rules. Any code change that breaks any of them is wrong by definition:

| Rule | Why |
|---|---|
| Creating a return MUST NOT auto-create a refund. | The decision to refund is an explicit operations choice that may differ from the inspection outcome (e.g. a damaged return that the company writes off without refunding). |
| Creating a return MUST NOT post to any cashbox. | Cashboxes are append-only; only the Finance module posts to them. |
| Closing a return MUST NOT auto-pay a refund. | Closure is finalisation of lifecycle, not of money. |
| Closing a return MUST NOT change the order's status. | The order remains `Returned` after the return is closed (its "did this order end in a return" marker doesn't go away). |
| Editing `refund_amount` MUST NOT change any cashbox balance. | The field is intent, not money. |
| A return MUST NOT be auto-closed when its refund is paid. | The two lifecycles are independent. A refund can be fully paid while the return is still in `Restocked`; the operator decides when to close the return. |
| A user with only `returns.create` MUST NOT be able to pay a refund. | Separation of duties — see [RETURNS_PERMISSIONS_AND_ROLES.md](RETURNS_PERMISSIONS_AND_ROLES.md). |

---

## 7. Permission separation — concrete

| Action | Required permission(s) |
|---|---|
| Create a return on an existing order (atomic flow) | `orders.change_status` **AND** `returns.create` (plus `fiscal_year_lock` middleware) |
| Create a return directly (no order status change) | `returns.create` |
| Update return details (refund intent / shipping loss / notes) | `returns.create` (current rule — Phase 3 may tighten to `returns.update`) |
| Inspect a return | `returns.inspect` |
| Close a return | `returns.create` (current rule — Phase 3 may tighten to `returns.close`) |
| Request a refund from a return | `refunds.create` |
| Approve a refund | `refunds.approve` |
| Reject a refund | `refunds.reject` |
| Pay a refund (cashbox OUT) | `refunds.pay` |

By design, **`order-agent` has `returns.create` and `refunds.create`** (after commit `ac08d7e`) but **does NOT** have `refunds.approve` or `refunds.pay`. Order agents create the paperwork; finance roles execute the money movement. This is the standard separation-of-duties pattern.

---

## 8. Finance-period guard — where it lives

`FinancePeriodService` (Finance Phase 5F) maintains the period-close state. The guard fires on:

- `Refund::pay()` — the cashbox OUT posting is blocked if the relevant period is closed.
- Cashbox-transaction creation in general.

The guard does **NOT** fire on:

- Creating a return.
- Updating a return's refund_amount.
- Closing a return.
- Creating a refund record in `requested` status (the refund row is created without touching a cashbox).

This split is deliberate: the *finance commitment* (the `requested` refund) can be opened in any period because it's paperwork; the *finance posting* (the `paid` step) is what hits the books and so must respect the close.

If a return-related refund is requested in period N but the period closes before approval/payment, the refund stays in `requested`/`approved` and `pay()` will throw when invoked. The accountant must either re-open the period (admin-only) or void the refund.

---

## 9. Reading the joined picture — practical

To answer the question *"is this return resolved for the customer?"* in code, you need **both** sides:

```php
$returnResolved = in_array($return->return_status, ['Restocked', 'Closed'], true);
$refundsAtLeastDecided = $return->refunds()
    ->whereIn('status', ['paid', 'rejected'])
    ->exists();

// Resolution from the customer's POV — common interpretation
$customerResolved = $returnResolved
    && ((float) $return->refund_amount === 0.0  // no refund intended
        || $refundsAtLeastDecided);              // OR every intended refund decided
```

The Returns module today **does not enforce** this combination — a return can be `Closed` while a refund is still `requested`. That is a deliberate looseness in the current design (it preserves operator flexibility); see Phase 5 in the roadmap for the discussion on whether to tighten it.

---

## 10. Reporting implications

Reports that mix Returns and Finance must clearly distinguish:

- **Returns metrics** — count of returns, return rate, condition breakdown, reasons-frequency. Source: `returns` table.
- **Refund exposure** — sum of `refund_amount` on **open** returns, sum of `amount` on **active** refunds. Source: `returns` for intent, `refunds` for active commitment.
- **Refund cash impact** — sum of paid refunds within a period. Source: `refunds` where `status='paid'`, joined to `cashbox_transactions` for the actual cash row.

These three are distinct figures and will diverge — a return's `refund_amount` is the intent, the linked refund's `amount` is the commitment (after manager approval), and the cashbox transaction is the realised cash. Reports must not collapse them.

---

## 11. Audit trail — what gets logged where

| Event | Module | `audit_logs.module` | Record type |
|---|---|---|---|
| Return opened | Returns | `returns` | `OrderReturn` |
| Return updated (detail fields) | Returns | `returns` | `OrderReturn` |
| Return inspected | Returns | `returns` | `OrderReturn` |
| Return closed | Returns | `returns` | `OrderReturn` |
| Refund requested (from return) | Finance | `refunds` (per Finance Phase 5C) | `Refund` |
| Refund approved | Finance | `refunds` | `Refund` |
| Refund rejected | Finance | `refunds` | `Refund` |
| Refund paid (cashbox OUT) | Finance | `refunds` + `cashbox_transactions` | `Refund` + `CashboxTransaction` |
| Order status → Returned | Orders | `orders` | `Order` |
| Inventory movement (Return To Stock) | Inventory | (no audit_log row — the `inventory_movements` table IS the audit trail for stock) | `InventoryMovement` |

Reading a return's full forensic history requires joining `audit_logs` filtered by `record_type=OrderReturn, record_id=<id>` with the order-side `audit_logs` for the same order's status changes, plus the related `refunds` and their own audit rows.
