# Finance Module — Final Overview (as shipped through Phase 5F.1)

> Authoritative architectural reference for the Finance module as it exists on `main` today.
>
> If anything in `PHASE_0_*.md` conflicts with this document, **this document wins**. The Phase 0 files are kept for historical planning context.

---

## 1. Source of truth — one rule

**Actual cash movement = `cashbox_transactions`.**

- Append-only (model `booted` hook blocks UPDATE + DELETE).
- Signed amount (positive = IN, negative = OUT).
- `source_type` distinguishes what generated the row (one of: `opening_balance`, `adjustment`, `transfer`, `collection`, `expense`, `refund`, `marketer_payout`).
- Balance is **always** `SUM(amount) WHERE cashbox_id=?`. Never stored. Never cached.

Every report that answers "where is the cash?" reads from this table. Every service that writes money writes one row here.

Operational tables (`collections`, `expenses`, `refunds`, `marketer_payouts`, `cashbox_transfers`) carry workflow + drill-down detail and link back via `cashbox_transaction_id`.

---

## 2. Models (10 finance models)

| Model | Purpose | Immutability guard |
|---|---|---|
| `Cashbox` | Named place where money lives | `currency_code` + `opening_balance` immutable after first tx; no hard delete (retire via `is_active=false`) |
| `CashboxTransaction` | Append-only ledger row | UPDATE + DELETE both throw |
| `CashboxTransfer` | Workflow envelope pairing two cashbox transactions | (no delete UI) |
| `PaymentMethod` | Seeded list of payment methods | retire via `is_active=false` |
| `Collection` | COD / settlement workflow | `cashbox_transaction_id` is the posting marker; double-post blocked at service layer |
| `Expense` | Outgoing payment workflow | `deleting` hook blocks delete on posted expenses |
| `Refund` | Refund lifecycle (requested → approved/rejected → paid) | `deleting` hook restricts delete to `requested` only |
| `MarketerPayout` | Payout lifecycle (requested → approved/rejected → paid) | `deleting` hook restricts delete to `requested` only |
| `MarketerTransaction` | Marketer profit/payout ledger (legacy + new mirror rows) | (existing) |
| `FinancePeriod` | Closed-period lock window | `deleting` hook always throws |

---

## 3. Services (10 finance services)

| Service | Public entry points | Race protection | Closed-period guard |
|---|---|---|---|
| `CashboxService` | `createCashbox`, `updateCashbox`, `deactivate/reactivateCashbox`, `createOpeningBalanceTransaction`, `createAdjustmentTransaction` | `DB::transaction` wraps create+opening-balance | ✓ on opening balance + adjustment |
| `CashboxTransferService` | `createTransfer` | Dual `lockForUpdate` (by id ascending) | ✓ |
| `CollectionCashboxService` | `postCollectionToCashbox` | `lockForUpdate` on collection | ✓ |
| `ExpenseCashboxService` | `postExpenseToCashbox` | `lockForUpdate` on expense + cashbox | ✓ |
| `RefundService` | `approve`, `reject`, `pay`, `createFromReturn`, `assertRefundableAmount`, `assertReturnRefundableAmount` | `lockForUpdate` on refund + cashbox + return | ✓ on pay |
| `MarketerPayoutService` | `requestPayout`, `approve`, `reject`, `pay` | `lockForUpdate` on payout + cashbox | ✓ on pay |
| `MarketerWalletService` (legacy) | `syncFromOrder`, `payout` (quick), `adjust`, `recalculateWallet`, `ensureWallet` | (no cashbox writes — out of guard scope) | ✗ legacy, not guarded |
| `MarketerProfitReversalService` | `reverseFromPaidRefund` | (idempotent by source_type+source_id) | n/a (best-effort, doesn't write cashbox tx) |
| `FinanceReportsService` | 9 read-only report methods | n/a (read-only) | n/a |
| `FinancePeriodService` | `assertDateIsOpen`, `isDateClosed`, `findClosedPeriodForDate`, `createPeriod`, `updatePeriod`, `closePeriod`, `reopenPeriod` | `lockForUpdate` on close/reopen | self (the guard) |

---

## 4. Permission matrix (separation of duties)

| Permission group | Slugs | Super Admin | Admin | Manager | Accountant | Order Agent / Warehouse / Shipping / Viewer / Marketer |
|---|---|:-:|:-:|:-:|:-:|:-:|
| Cashboxes | view, create, edit, deactivate | ✓ | ✓ | view | view, create, edit | view (Viewer); — others |
| Cashbox transactions | view, create | ✓ | ✓ | view | view, create | — |
| Cashbox transfers | view, create | ✓ | ✓ | view | view, create | — |
| Payment methods | view, create, edit | ✓ | ✓ | view | view, create, edit | — |
| Collections (finance scope) | assign_cashbox, reconcile_settlement | ✓ | ✓ | — | ✓ | shipping_agent: yes; others — |
| Expenses (finance scope) | assign_cashbox, post_to_cashbox | ✓ | ✓ | — | ✓ | — |
| Refunds | view, create, approve, reject, pay | ✓ | ✓ | view, create, approve, reject | view, create, reject, pay | order_agent: view+create; warehouse: view only |
| Marketer payouts | view, create, approve, reject, pay | ✓ | ✓ | view, approve, reject | view, create, reject, pay | — |
| Finance reports | view | ✓ | ✓ | ✓ | ✓ | — |
| Finance periods | view, create, update, close, reopen | ✓ | ✓ | view, close | view, create, update, close | — |

Key separation-of-duties decisions:

- **Manager approves refunds + payouts; Accountant pays them.** No single role can both approve and execute payment.
- **Reopen finance period is Admin-only.** Re-opening a period lets historical movements be added inside it — high-trust action.
- **Accountant cannot approve refunds or payouts.** They can request, reject, and execute payment, but not approve.

---

## 5. Key workflows

### 5.1 Collection cash-in (COD or direct collection)

```
Order shipped → courier delivers → operator marks collected →
Accountant posts collection to cashbox →
  CollectionCashboxService::postCollectionToCashbox
    ├─ DB::transaction
    │   ├─ Collection row locked (lockForUpdate)
    │   ├─ Double-post + eligibility + currency guards
    │   ├─ FinancePeriodService::assertDateIsOpen(occurred_at)
    │   ├─ INSERT cashbox_transactions (direction=in, source_type=collection)
    │   └─ Stamp collection.cashbox_transaction_id + cashbox_posted_at
    └─ Audit log: cashbox_transaction.created
```

### 5.2 Expense cash-out

Same shape as 5.1 but `direction=out`, `amount<0`, plus a balance-check guard when `allow_negative_balance=false`.

### 5.3 Refund lifecycle

```
Order Agent requests refund            (status=requested)
  → RefundService::approve            (status=approved)   [audit]
     OR RefundService::reject         (status=rejected)   [audit, terminal]
  → RefundService::pay                (status=paid)        [audit]
        ├─ lockForUpdate(refund) + lockForUpdate(cashbox)
        ├─ Cashbox active + sufficient balance + over-refund guard
        ├─ FinancePeriodService::assertDateIsOpen(occurred_at)
        ├─ INSERT cashbox_transactions (out, source_type=refund)
        ├─ Stamp refund.paid_* and refund.cashbox_transaction_id
        └─ MarketerProfitReversalService::reverseFromPaidRefund(refund)
             (skips if return-linked, order already Returned/Cancelled,
              or no marketer/profit/total_amount; otherwise writes one
              Adjustment row with source_type='refund' + source_id=refund.id)
```

### 5.4 Refund from return (Phase 5C)

```
Operator inspects return →
  ReturnsController::requestRefund →
    RefundService::createFromReturn
      ├─ lockForUpdate(return)
      ├─ Eligibility check (status in Inspected/Restocked/Damaged/Closed + refund_amount>0)
      ├─ Over-return guard + collection-level over-refund guard
      └─ INSERT refund row with order_return_id (status=requested)

Then continues through approve → pay (as 5.3, but profit reversal is skipped
because order_return_id is non-null — the return's order-status flip will
trigger MarketerWalletService::syncFromOrder which handles the cancellation).
```

### 5.5 Marketer payout lifecycle

```
Accountant requests payout                (status=requested)
  → MarketerPayoutService::approve         (status=approved)   [audit]
     OR MarketerPayoutService::reject      (status=rejected)   [audit, terminal]
  → MarketerPayoutService::pay             (status=paid)        [audit]
        ├─ lockForUpdate(payout) + lockForUpdate(cashbox)
        ├─ Cashbox active + sufficient balance + canBePaid (no cashbox_transaction_id yet)
        ├─ FinancePeriodService::assertDateIsOpen(occurred_at)
        ├─ INSERT cashbox_transactions (out, source_type=marketer_payout)
        ├─ INSERT marketer_transactions (Payout/Paid, source_type=marketer_payout, source_id=payout.id)
        ├─ Stamp payout.paid_* + .cashbox_transaction_id + .marketer_transaction_id
        └─ MarketerWalletService::recalculateWallet(marketer)
```

### 5.6 Closed-period guard

```
Accountant closes May 2026                  finance_periods row status=closed
   │
   ▼
ANY of the 7 cash-impacting write paths (collection post, expense post,
refund pay, marketer payout pay, cashbox adjustment, cashbox transfer,
cashbox opening balance) called with an occurred_at inside May 2026
   │
   ▼
FinancePeriodService::assertDateIsOpen(occurred_at) → RuntimeException
   │
   ▼
Controller catches → back()->with('error', message)
(All 7 controllers wrapping these services now have the try/catch — fixed
finally in Phase 5F.1.)
```

---

## 6. Audit + controls summary

| Concern | Control | Where |
|---|---|---|
| No hard delete on finance tables | Model `deleting` hooks + no DELETE routes | CashboxTransaction, FinancePeriod (always); Cashbox (retire instead); Refund / MarketerPayout / Expense (status-gated) |
| Append-only ledger | `CashboxTransaction::booted` blocks UPDATE + DELETE | model layer |
| Immutable opening balance + currency | `Cashbox::booted` after first tx | model layer |
| No double-post of collection / expense | `cashbox_transaction_id IS NULL` check | service layer + DB-level uniqueness via the column being set |
| No double-pay of refund / payout | `canBePaid()` requires status=approved + cashbox_transaction_id IS NULL + paid_at IS NULL | service layer, re-checked under `lockForUpdate` |
| Concurrent posting race | `DB::transaction` + `lockForUpdate` | 18 lock sites across 6 services |
| Over-refund / over-return | `assertRefundableAmount` + `assertReturnRefundableAmount` | RefundService |
| Negative cashbox blocked | `assertSufficientBalanceIfNeeded` when `allow_negative_balance=false` | every OUT path |
| Single-currency invariant | `validateSameCurrency` / `assertSameCurrency` | CollectionCashboxService, ExpenseCashboxService, CashboxTransferService |
| Closed-period guard | `FinancePeriodService::assertDateIsOpen` | all 6 cash-impacting services |
| Marketer profit double-reversal | Skip if return-linked + skip if order already Returned/Cancelled | MarketerProfitReversalService (R-19) |
| Audit log on every state change | `AuditLogService::log` + `::logModelChange` | 28 call sites across finance services |
| Permission separation of duties | Phase 0 matrix encoded in `RolesSeeder` | seeders + route middleware |

---

## 7. Test coverage

| Test file | Tests | What it pins |
|---|---:|---|
| `CashboxTest.php` | 18 | Lifecycle, opening balance, adjustment, immutability, currency lock |
| `CashboxTransferTest.php` | 13 | Same-currency, both-active, sufficient balance, dual-lock |
| `PaymentMethodTest.php` | 12 | Seed presence + permission gating |
| `CollectionCashboxTest.php` | 13 | Posting + double-post + status eligibility |
| `ExpenseCashboxTest.php` | 15 | Posting + delete-after-post block |
| `HardeningTest.php` | 13 | Phase 4.5 race + immutability proven |
| `RefundTest.php` | 39 | Lifecycle, over-refund, double-pay, audit, paid immutability |
| `ReturnRefundTest.php` | 18 | Eligibility, over-return, refund context surfacing |
| `MarketerPayoutTest.php` | 28 | Lifecycle, mirror row, balance rules, double-reversal protection |
| `FinanceReportsTest.php` | 17 | All 9 reports + read-only assertion |
| `FinancePeriodTest.php` | 25 | Lifecycle + guard from all 6 services + 5F.1 UX tests |

**Total: 209 finance tests / 277 overall test suite / 1225 assertions.**

---

## 8. UI sitemap (Finance)

```
/cashboxes/                     Index
/cashboxes/create               Create
/cashboxes/{id}                 Statement (with source_type filter — Phase 5E fix)
/cashboxes/{id}/edit            Edit
/cashboxes/{id}/transactions    POST adjustment (Phase 5F.1 try/catch)

/cashbox-transfers/             Index
/cashbox-transfers/create       Create

/payment-methods/               Index
/payment-methods/create         Create
/payment-methods/{id}/edit      Edit

/collections/                   Index
/collections/{id}               Show (with posting form)

/expenses/                      Index
/expenses/create                Create
/expenses/{id}/edit             Edit

/refunds/                       Index (with Pay modal)
/refunds/create                 Create
/refunds/{id}/edit              Edit (requested only)

/returns/{id}                   Show (with Request Refund form — Phase 5C)

/marketer-payouts/              Index (with Pay modal)
/marketer-payouts/create        Create
/marketer-payouts/{id}/edit     Edit (requested only)

/finance/reports/               Overview (summary cards)
/finance/reports/cashboxes      Per-cashbox totals
/finance/reports/movements      Ledger
/finance/reports/collections    Posted/unposted collections
/finance/reports/expenses       Posted/unposted expenses
/finance/reports/refunds        Refund lifecycle
/finance/reports/marketer-payouts  Payout lifecycle
/finance/reports/transfers      Transfers
/finance/reports/cash-flow      By source type

/finance/periods/               Index
/finance/periods/create         Create
/finance/periods/{id}/edit      Edit (open only)
```

---

## 9. Known limitations / deferred follow-ups

See `RELEASE_NOTES.md` § "Known limitations / deferred follow-ups" for the full list. Short version:

- Legacy `MarketerWalletService::payout()` quick-pay path is NOT closed-period guarded (writes only to marketer_transactions, not cashbox).
- Finance reports CSV/Excel exports not implemented.
- Supplier payments aren't part of the cashbox ledger.
- Bank reconciliation not implemented.
- Order Price Override deferred indefinitely.

None of these block production use of the Finance module today.

---

## 10. Glossary

| Term | Meaning |
|---|---|
| **Cashbox** | A named place where money lives — cash drawer, bank account, digital wallet, marketplace wallet, or courier-COD pending pool. |
| **Cashbox transaction** | Append-only signed-amount row in `cashbox_transactions`. The canonical money-moved event. |
| **Source type** | One of `opening_balance / adjustment / transfer / collection / expense / refund / marketer_payout`. Tells the reader what generated the cashbox transaction. |
| **Posted (collection / expense)** | Has a non-null `cashbox_transaction_id`. Money has hit a real cashbox. |
| **Paid (refund / payout)** | Status = `paid`. Has cashbox + payment_method + cashbox_transaction_id stamped. |
| **Finance period** | A date range marked `open` or `closed`. Closed periods block cashbox writes whose `occurred_at` falls inside. |
| **Marketer wallet** | Snapshot of `total_expected / total_pending / total_earned / total_paid / balance` derived from `marketer_transactions`. Not authoritative — recomputed via `recalculateWallet`. |
| **Mirror row** | When a marketer payout is paid via the new lifecycle, the cashbox OUT transaction is *mirrored* by a `marketer_transactions(Payout/Paid)` row so the wallet recompute stays accurate. |
