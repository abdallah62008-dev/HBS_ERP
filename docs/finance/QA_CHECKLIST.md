# Finance Module — Manual QA Checklist

> Use this checklist to validate the Finance module against real user flows before any new feature (Order Price Override, Supplier Payments, Bank Reconciliation, etc.) is started.
>
> The automated test suite (277 passing) covers a lot of ground but manual QA on a staging copy of production data finds the bugs automation misses — especially UX issues, role/permission edge cases, and multi-day workflow assumptions.
>
> Recommended execution: 1–2 weeks of operator time spread across the 9 journeys, performed by users who match the role being tested (Accountant for cashbox + refunds + payouts, Manager for approvals, Admin for period reopen).

---

## How to use this document

1. Set up a clean staging environment with `php artisan db:seed --class=PermissionsSeeder` and `RolesSeeder` so all 27 finance permissions are present.
2. Create one user per finance role (Admin, Manager, Accountant, Order Agent, Warehouse Agent, Viewer, Marketer).
3. Walk through each Journey below in order — they roughly build on each other.
4. After each step, fill the **Pass / Fail** column. If Fail, file a bug with the **Notes** column populated.
5. When all 9 journeys are Pass, the module is QA-signed.

Legend for the **Pass / Fail** column:
- ✅ Pass
- ❌ Fail (file a bug)
- ⚪ Not yet executed
- ⏭️ Skipped (with reason in Notes)

---

## Journey 1 — Cashbox lifecycle (opening balance → adjustment → balance check)

**Preconditions:**
- Logged in as **Accountant**.
- No closed finance period covers today's date.

| # | Step | Expected | Pass/Fail | Notes / screenshots |
|---|---|---|:-:|---|
| 1.1 | Navigate to `/cashboxes` | List loads; "+ New cashbox" button visible | ⚪ | |
| 1.2 | Click "+ New cashbox". Fill name "QA Main Cash", type `cash`, currency `EGP`, opening balance `1000`. Save. | Redirect to index, success flash, new cashbox visible with balance `EGP 1,000.00` | ⚪ | |
| 1.3 | Open the new cashbox → confirm one `opening_balance` transaction exists in the statement | Statement shows one IN row of `EGP 1,000.00`, source type "opening balance" | ⚪ | |
| 1.4 | From the cashbox statement page, record an `IN` adjustment of `EGP 250` with notes "QA test" | Success flash; statement now shows 2 rows; balance `EGP 1,250.00` | ⚪ | |
| 1.5 | Record an `OUT` adjustment of `EGP 100` with notes "QA outflow" | Statement shows 3 rows; balance `EGP 1,150.00` | ⚪ | |
| 1.6 | As **Viewer**, try to navigate to `/cashboxes` | 403 Forbidden | ⚪ | |
| 1.7 | As **Viewer** with `cashboxes.view` granted, navigate to `/cashboxes` | List loads; no "+ New cashbox" button | ⚪ | |
| 1.8 | Open a cashbox statement, try to filter by source type `marketer_payout` from the dropdown | Dropdown contains all 7 source types (opening_balance, adjustment, transfer, collection, expense, refund, marketer_payout) — Phase 5E fix | ⚪ | |

---

## Journey 2 — Cashbox transfer (two cashboxes, same currency)

**Preconditions:**
- Two active cashboxes in `EGP`, both with non-zero balance (Journey 1's "QA Main Cash" + a second "QA Bank").
- Logged in as **Accountant**.

| # | Step | Expected | Pass/Fail | Notes |
|---|---|---|:-:|---|
| 2.1 | Navigate to `/cashbox-transfers/create` | Form loads with both cashboxes selectable | ⚪ | |
| 2.2 | Transfer `EGP 200` from "QA Main Cash" to "QA Bank". Save. | Success flash; transfer listed in index; both cashbox balances updated (+200 / -200) | ⚪ | |
| 2.3 | Open "QA Main Cash" statement → verify an OUT transaction with source `transfer` | Row visible: amount `-200`, source `transfer` | ⚪ | |
| 2.4 | Open "QA Bank" statement → verify a mirroring IN transaction with source `transfer` | Row visible: amount `+200`, source `transfer` | ⚪ | |
| 2.5 | Attempt to transfer between two cashboxes of different currencies (if one exists) | Error: "Cross-currency transfers are not supported" | ⚪ | |
| 2.6 | Attempt to transfer from a cashbox with `allow_negative_balance=false` an amount larger than its balance | Error: "insufficient balance"; no transfer rows written | ⚪ | |

---

## Journey 3 — Collection posting + double-post block

**Preconditions:**
- One delivered order with `collection_status='Collected'` and an `amount_collected > 0`.
- An active cashbox in the same currency.
- Logged in as **Accountant**.

| # | Step | Expected | Pass/Fail | Notes |
|---|---|---|:-:|---|
| 3.1 | Navigate to the collection's detail page | Page shows "Post to cashbox" form (Accountant role) | ⚪ | |
| 3.2 | Post the collection to the cashbox using a payment method | Success flash; collection shows posted at + cashbox name | ⚪ | |
| 3.3 | Confirm cashbox statement now has a `+amount` row with source `collection` | New IN row visible | ⚪ | |
| 3.4 | Confirm cashbox balance increased by the collection amount | Balance += amount_collected | ⚪ | |
| 3.5 | Try to post the same collection again (refresh page, click post) | Error: "Collection #X is already posted to a cashbox" — no duplicate cashbox tx | ⚪ | |
| 3.6 | Try posting a collection whose status is `Not Collected` | Error: "Collection status … not eligible for posting" | ⚪ | |
| 3.7 | As **Order Agent**, try posting any collection | 403 Forbidden | ⚪ | |

---

## Journey 4 — Expense posting + delete-after-post block

**Preconditions:**
- An expense category exists.
- An active cashbox with sufficient balance (or `allow_negative_balance=true`).
- Logged in as **Accountant**.

| # | Step | Expected | Pass/Fail | Notes |
|---|---|---|:-:|---|
| 4.1 | Create a new expense: category, title "QA Rent", amount `EGP 300`, date today, no cashbox yet | Expense saved; status "Not posted" | ⚪ | |
| 4.2 | Post the expense to a cashbox + payment method | Success flash; expense shows posted_at; cashbox balance dropped by `300` | ⚪ | |
| 4.3 | Confirm cashbox statement now has a `-300` row with source `expense` | New OUT row visible | ⚪ | |
| 4.4 | Try to delete the posted expense | Error: "Expense #X is posted to a cashbox and cannot be deleted. Use a reversal flow." | ⚪ | |
| 4.5 | Try posting an expense to a cashbox whose `allow_negative_balance=false` for more than its balance | Error: "Cashbox … has insufficient balance" | ⚪ | |
| 4.6 | As **Viewer** (no expense permissions), try to navigate to `/expenses` | 403 Forbidden | ⚪ | |

---

## Journey 5 — Refund lifecycle: requested → approved → paid

**Preconditions:**
- One delivered order with a collection of `amount_collected > 200`.
- Logged in switches between **Order Agent → Manager → Accountant** per step.

| # | Step | Acting as | Expected | Pass/Fail | Notes |
|---|---|---|---|:-:|---|
| 5.1 | Navigate to `/refunds/create`, fill amount `EGP 50` and a reason, link to the order's collection | Order Agent | Refund created in `requested` status; list shows it with the amber chip | ⚪ | |
| 5.2 | Try to request a second refund of `EGP 200` against the same collection (total would exceed `amount_collected`) | Order Agent | Validation error: "Cumulative active refunds … would exceed the collected amount" | ⚪ | |
| 5.3 | Approve the first refund | Manager | Refund moves to `approved` (emerald chip); approved_by + approved_at populated | ⚪ | |
| 5.4 | Try to approve again | Manager | Error: "Refund #X cannot be approved (status: approved)" | ⚪ | |
| 5.5 | Pay the approved refund — pick a cashbox + payment method | Accountant | Success flash; refund is `paid` (indigo chip); cashbox balance decreased by `50` | ⚪ | |
| 5.6 | Open the cashbox statement → confirm a `-50` row with source `refund` | Accountant | New OUT row visible | ⚪ | |
| 5.7 | Try to pay the same refund again | Accountant | Error: "Refund #X cannot be paid (status: paid)"; no second cashbox tx | ⚪ | |
| 5.8 | Try to delete the paid refund | Accountant | Either a flash error from the controller OR a hard `RuntimeException` from the model — both acceptable, but the row must remain | ⚪ | |
| 5.9 | As **Warehouse Agent**, navigate to `/refunds` | Warehouse | List loads (view-only); no Approve / Pay / Delete buttons visible | ⚪ | |

---

## Journey 6 — Refund from return path (Phase 5C)

**Preconditions:**
- One delivered order with a customer return inspected (`return_status='Inspected'`, `refund_amount > 0`).
- Logged in as **Order Agent** then **Manager** then **Accountant**.

| # | Step | Acting as | Expected | Pass/Fail | Notes |
|---|---|---|---|:-:|---|
| 6.1 | Navigate to `/returns/{id}` | Order Agent | Page shows the return + a "Request refund" form because `canRequestRefund` is true | ⚪ | |
| 6.2 | Submit the form (amount defaulted, optional reason) | Order Agent | Redirect to `/refunds`; new refund is `requested` and linked to `order_return_id` | ⚪ | |
| 6.3 | Confirm the new refund row carries `order_return_id` (visible in refund detail / drill-down) | — | Linked return ID surfaced | ⚪ | |
| 6.4 | Approve + pay the refund through the normal lifecycle | Manager → Accountant | Refund becomes paid; cashbox OUT row written | ⚪ | |
| 6.5 | Verify NO `MarketerTransaction(Adjustment)` reversal row was written for this refund | — | `marketer_transactions WHERE source_type='refund' AND source_id={refund.id}` returns 0 rows — return-linked refunds defer profit cancellation to the return path | ⚪ | |
| 6.6 | Try to request a second refund against the same return for the full return refund_amount again | Order Agent | Validation error: "Cumulative active refunds for return #X would exceed the return's refund_amount" | ⚪ | |
| 6.7 | Try to request a refund against a return whose status is `Pending` (not yet inspected) | Order Agent | Validation error: "is not eligible to request a refund" | ⚪ | |

---

## Journey 7 — Marketer payout lifecycle (requested → approved → paid)

**Preconditions:**
- An active marketer with a positive wallet balance.
- An active cashbox with sufficient balance.
- Logged in switches between **Accountant → Manager → Accountant** per step.

| # | Step | Acting as | Expected | Pass/Fail | Notes |
|---|---|---|---|:-:|---|
| 7.1 | Navigate to `/marketer-payouts/create`, pick marketer, enter amount | Accountant | Payout created in `requested` (amber chip) | ⚪ | |
| 7.2 | Approve the payout | Manager | Status `approved` (emerald chip); approved_by + approved_at populated | ⚪ | |
| 7.3 | Try to pay it as **Manager** | Manager | 403 Forbidden — manager has approve but not pay (separation of duties) | ⚪ | |
| 7.4 | Pay the approved payout, choose cashbox + payment method | Accountant | Success flash; payout is `paid` (indigo chip) | ⚪ | |
| 7.5 | Confirm cashbox statement shows a `-amount` row with source `marketer_payout` | Accountant | New OUT row visible | ⚪ | |
| 7.6 | Confirm a mirror row in marketer_transactions exists (type='Payout', status='Paid') linked to the payout | — | Wallet `total_paid` increased by the payout amount; balance decreased | ⚪ | |
| 7.7 | Try to pay the payout again | Accountant | Error: "cannot be paid (status: paid)"; no second cashbox tx | ⚪ | |
| 7.8 | Open the marketer's Wallet page — confirm balance reflects the payout | Accountant | Total paid and balance updated | ⚪ | |
| 7.9 | As **Order Agent**, navigate to `/marketer-payouts` | Order Agent | 403 Forbidden | ⚪ | |

---

## Journey 8 — Closing a finance period blocks postings inside the range

**Preconditions:**
- All previous journeys completed (so there are real cashbox transactions to interact with).
- Logged in as **Accountant** then **Admin**.

| # | Step | Acting as | Expected | Pass/Fail | Notes |
|---|---|---|---|:-:|---|
| 8.1 | Navigate to `/finance/periods/create`. Create a period "QA Test May 2026" covering `2026-05-01 → 2026-05-31` | Accountant | Period created, status `open` | ⚪ | |
| 8.2 | Try to create a second period overlapping with the first | Accountant | Error: "overlaps existing period" — no second row | ⚪ | |
| 8.3 | Close "QA Test May 2026" | Accountant | Status `closed`; closed_by + closed_at populated. **Pinned banner explains what blocking applies.** | ⚪ | |
| 8.4 | Try to record a cashbox adjustment dated `2026-05-15` | Accountant | **Flash error** (not a 500): "Finance period … is closed …" — Phase 5F.1 fix | ⚪ | |
| 8.5 | Try to post an expense dated `2026-05-20` to a cashbox | Accountant | Flash error: closed period | ⚪ | |
| 8.6 | Try to post a collection with `cashbox_posted_at = 2026-05-10` (or a collection whose settlement_date is in May) | Accountant | Flash error: closed period | ⚪ | |
| 8.7 | Try a cashbox transfer with `occurred_at = 2026-05-12` | Accountant | Flash error: closed period | ⚪ | |
| 8.8 | Try to pay a pending refund with `occurred_at = 2026-05-15` | Accountant | Flash error: closed period; refund stays `approved` | ⚪ | |
| 8.9 | Try to pay an approved marketer payout with `occurred_at = 2026-05-18` | Accountant | Flash error: closed period; payout stays `approved` | ⚪ | |
| 8.10 | Try to create a NEW cashbox with a non-zero opening balance (default `occurred_at = today`) IF today falls inside `2026-05-*` | Accountant | Flash error from Phase 5F.1 try/catch — not a 500 | ⚪ | |
| 8.11 | Try to edit the closed period | Accountant | Redirect with error: "is closed and cannot be edited" | ⚪ | |
| 8.12 | As **Manager**, try to reopen the period | Manager | 403 Forbidden — reopen is Admin-only | ⚪ | |
| 8.13 | Navigate to ANY finance report (`/finance/reports/...`) and confirm closed-period data is still visible | Accountant | Reports load normally; closed-period transactions visible | ⚪ | |

---

## Journey 9 — Reopen period and confirm writes work again

**Preconditions:** Journey 8 completed with the "QA Test May 2026" period closed.

| # | Step | Acting as | Expected | Pass/Fail | Notes |
|---|---|---|---|:-:|---|
| 9.1 | Reopen "QA Test May 2026" | Admin | Status flips to `open`; reopened_by + reopened_at populated | ⚪ | |
| 9.2 | Confirm the period's audit log has three rows: `finance_period_created`, `finance_period_closed`, `finance_period_reopened` | Admin | All three audit rows present | ⚪ | |
| 9.3 | Retry the adjustment from step 8.4 (`occurred_at = 2026-05-15`) | Accountant | Success — adjustment recorded; cashbox balance updates | ⚪ | |
| 9.4 | Verify the previously-blocked refund payment from 8.8 can now be paid with `occurred_at = 2026-05-15` | Accountant | Refund moves to `paid`; cashbox OUT row written | ⚪ | |
| 9.5 | Confirm no historical (pre-Journey-8) cashbox transactions were modified during close/reopen | Admin | `cashbox_transactions` row count + sums unchanged from pre-Journey-8 baseline | ⚪ | |
| 9.6 | Close the period again | Accountant | Status `closed`; audit log gains another `finance_period_closed` row | ⚪ | |
| 9.7 | Verify a date outside the closed range (e.g. `2026-04-15` or `2026-06-15`) is still freely writeable | Accountant | Cashbox adjustment with `occurred_at = 2026-06-15` succeeds | ⚪ | |

---

## Sign-off

| Journey | Pass/Fail | Tested by | Date | Bugs filed |
|---|:-:|---|---|---|
| 1 — Cashbox lifecycle | ⚪ | | | |
| 2 — Cashbox transfer | ⚪ | | | |
| 3 — Collection posting | ⚪ | | | |
| 4 — Expense posting | ⚪ | | | |
| 5 — Refund lifecycle | ⚪ | | | |
| 6 — Refund from return | ⚪ | | | |
| 7 — Marketer payout | ⚪ | | | |
| 8 — Close period blocks | ⚪ | | | |
| 9 — Reopen + writes work | ⚪ | | | |

**Overall status:**

- [ ] All 9 journeys passed → finance module signed off for production use; safe to start the next feature phase (Order Price Override or other deferred items in `RELEASE_NOTES.md`).
- [ ] Some journeys failed → bugs filed, follow-up phase needed before any new feature work.

---

## Notes for QA testers

- **Take screenshots of every error message and success state.** They double as documentation for `FINANCE_MODULE_FINAL_OVERVIEW.md`.
- **Test on a staging copy of production data**, not an empty database. Real data exposes edge cases (legacy expenses without cashbox, partially-collected COD, multi-marketer orders).
- **Don't skip the permission-denial steps.** UX-blocking via hidden buttons is not the same as server-side blocking via middleware — every denial step verifies both.
- **If you find a UX bug** (like 5F.1's 500-instead-of-flash), file it small and atomic. Don't bundle multiple unrelated findings into one bug report.
- **The closed-period date in journeys 8/9 (`2026-05-01 → 2026-05-31`) is illustrative.** Adjust the range to match staging's calendar — but pick a range that contains at least one of every operational record type (collection, expense, refund-paid, payout-paid, transfer).
