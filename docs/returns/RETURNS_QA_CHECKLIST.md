# Returns — Manual QA Checklist

> **Companion to:** [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) · [RETURNS_PERMISSIONS_AND_ROLES.md](RETURNS_PERMISSIONS_AND_ROLES.md)
> **Purpose:** A scripted run-through to perform before any release that touches the Returns module. Seventeen end-to-end journeys covering creation, inspection, refunds, permissions, visibility, and edge cases.

This is a **manual** checklist — automated tests cover the contract, this list covers the operator experience. Run it once before every Returns release. Each journey lists the role to log in as, the steps, and the expected result; the **last column is the smoke result** to record before signing off.

---

## Setup

- **Seeded data:** `php artisan migrate:fresh --seed` against a non-production DB.
- **Test accounts:** `admin@hbs.local / Admin@2026` (super-admin), plus one of each role provisioned with the seeder defaults.
- **Test order pool:** at least three Delivered orders with stock, two with marketers attached, one with a partial refund flow in progress.
- **Browser:** Hard-refresh (`Ctrl+F5`) before starting — guarantees no stale bundle.

---

## 1. Delivered order → Returned with valid reason (atomic flow)

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Order Agent** (post-`ac08d7e`). | | |
| Open a Delivered order at `/orders/{id}`. | The status row reads `Delivered`. No return banner yet. | |
| Click *Change status* → pick `Returned`. | The Return Details block expands inside the modal. Reason / condition / refund amount / shipping loss / notes are visible. | |
| Pick a reason, leave condition as `Unknown`, leave amounts at 0. Click Save. | Redirects to `/returns/{new_id}`. Flash message *"Order returned and return record created."* | |
| Check `/orders/{id}`. | Status now reads `Returned`. The amber "Manage return" button appears in the header. The amber return-context banner is visible directly under the status row, showing return id + status `Pending` + condition `Unknown`. | |
| Check inventory_movements for the order. | One `Return To Stock` row per item, `quantity = +item.quantity`, `reference_type = App\Models\Order`. | |

---

## 2. Returned without reason is blocked

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Manager**. | | |
| Open a Delivered order. Click *Change status* → pick `Returned`. | Return Details block expands. | |
| Leave the *Return reason* select empty. Click Save. | Modal stays open. Inline error appears under the reason field: *"A return reason is required when changing the order to Returned."* | |
| Confirm the order's status did **not** change (still Delivered) and no return row was created. | | |
| Repeat from `/orders/{id}/edit` — Save without reason. | Same outcome — validation prevents the save; no DB change. | |

---

## 3. Duplicate return is blocked

| Step | Expected | ✓ |
|---|---|:--:|
| Use the order created in journey 1 (already has a return). | | |
| Open `/orders/{id}`. | The *Returned* option is HIDDEN from the *Change status* dropdown; the status modal shows the *"already has a return record"* hint. | |
| Try forcing it via `/returns/create?order_id=<id>`. | The page shows *"Order {order_number} already has a return record and cannot be returned again."* The order is **not** pre-selected. The amber notice carries an *"Open existing return →"* link to `/returns/{existing_id}` (Phase 2). | |
| Try the direct POST: `POST /returns` with `order_id` set. | Validation error from `OrderReturnRequest` — duplicate-return rule blocks. Original return is untouched (Phase 2 pinning). | |

---

## 4. Order with return shows *Manage Return*

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as any user with `returns.view`. | | |
| Open the order from journey 1 at `/orders/{id}`. | The amber *Manage return* button is the first action button in the header row, ahead of *Timeline / Edit order / Change status*. | |
| Hover the button. | `title` reads *"Open return #N (status: Pending)"*. | |
| Click the button. | Lands on `/returns/{id}`. | |
| Log out and log in as a user **without** `returns.view`. | Re-open the same order. | The button is hidden; the banner text still appears but its *"Open return →"* link is hidden. | |

---

## 5. Return appears in Active while Pending

| Step | Expected | ✓ |
|---|---|:--:|
| Open `/returns`. | The page subtitle reads *"Active returns · N of M total"*. The `Active` tab is selected (dark fill). | |
| Find the return from journey 1 in the table. | Row visible. Status badge `Pending`, condition `Unknown`. The first column shows the RMA reference `RET-000NNN` instead of `#N` (Phase 2). | |
| Hover the reference. | Tooltip reads *"Return #N"* — confirms the link between the formatted display and the underlying id. | |
| Click the `Resolved` tab. | The return is NOT in the Resolved view. The subtitle changes to *"Resolved returns · X of M total"*. | |
| Click the `All` tab. | The return IS visible in All. The subtitle changes to *"All returns · M"*. | |
| Click the `Pending` per-status chip. | Returns filtered to just `Pending`. The return is visible. | |

---

## 5.bis. Optional Received checkpoint (Phase 3)

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Warehouse Agent**. | | |
| Open a `Pending` return at `/returns/{id}`. | A *"Receive"* card is visible in the action column. The card explains that receive is a lifecycle marker; inventory and refunds are untouched. The card sits ABOVE the inspect form. | |
| Click *"Mark received"*. | Page reloads; return status badge updates to `Received`. Flash success: *"Return RET-000NNN marked as received."* The *Receive* card disappears; the *Inspect* form remains. | |
| Verify the audit trail. | `audit_logs` has a row with `action='received', module='returns', record_type=OrderReturn, record_id=<id>`. | |
| Inspect the same return as Good + restockable. | Succeeds. `return_status` becomes `Restocked`. On-hand stays at the post-Returned level (no extra movement). | |
| Repeat with a different return: skip Receive entirely, inspect directly from Pending. | Succeeds — the fast-path is unchanged. | |
| Log in as **Order Agent** and try to mark a Pending return as received. | Action returns 403. Status stays `Pending`. (Receive is warehouse-side.) | |
| Log in as **Viewer** and try to mark a Pending return as received. | 403. | |
| Try to mark a `Restocked` / `Damaged` / `Closed` return as received via direct POST. | Service rejects with flash error *"Return #N cannot be marked as received from status 'X'."*. Status unchanged. | |

---

## 6. Closed return moves to Resolved / All

| Step | Expected | ✓ |
|---|---|:--:|
| Open the return from journey 1 at `/returns/{id}`. Inspect it (any way) so it leaves Pending. | Status moves to Restocked or Damaged. | |
| Click *Close* (provide an optional note). | Status moves to `Closed`. Inspection / Edit / Close blocks become read-only or disappear. | |
| Navigate back to `/returns`. | The closed return is NOT in the Active view (which is the default). | |
| Switch to `Resolved`. | Closed return is visible. Subtitle reflects "Resolved returns · …". | |
| Switch to `All`. | Closed return is visible. | |
| Switch to the `Closed` per-status chip. | Closed return is visible. | |

---

## 7. Restocked return moves to Resolved / All

| Step | Expected | ✓ |
|---|---|:--:|
| Create a fresh Delivered → Returned (journey 1). Inspect it as Good + restockable. | Status moves to `Restocked`. | |
| Navigate to `/returns`. | The restocked return is NOT in the Active view. | |
| Switch to `Resolved`. | Restocked return is visible. | |
| Switch to the `Restocked` per-status chip. | Restocked return is visible. | |

---

## 8. Good return — stock behaviour verified

| Step | Expected | ✓ |
|---|---|:--:|
| Note the product's on-hand BEFORE creating the order. | E.g. 100. | |
| Create a 3-unit order. Confirm → Ship → Deliver. | On-hand should now be 97 (the `-3` Ship movement). | |
| Mark the order Returned. | On-hand jumps to 100 (the optimistic `+3` `Return To Stock`). | |
| Inspect the return as Good + restockable. | On-hand stays at 100 (no further movement; the optimistic +qty is confirmed). | |
| Close the return. | On-hand stays at 100. | |
| Verify `inventory_movements` rows for the product/order. | Exactly two rows under the order's reference: `Ship -3`, `Return To Stock +3`. No reversal row. | |

---

## 9. Damaged return — stock behaviour verified

| Step | Expected | ✓ |
|---|---|:--:|
| Same setup as journey 8 (Delivered → Returned). After Returned, on-hand is back to 100. | | |
| Inspect the return as `Damaged` (or `Missing Parts` / `Unknown`) — restockable=false. | On-hand drops back to 97 (the reversal `-3` cancels the optimistic restock). | |
| Verify `inventory_movements`. | Three rows: `Ship -3` (reference Order), `Return To Stock +3` (reference Order), `Return To Stock -3` (reference OrderReturn, note: *"Reversal — return inspected as Damaged"*). | |
| Check the return row. | `return_status = Damaged`, `product_condition = Damaged`, `restockable = false`, `inspected_at` set. | |

---

## 10. Refund request from a return

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Order Agent** (`refunds.create` granted by seeder). | | |
| Open an inspected return (`Restocked` or `Damaged`) with `refund_amount > 0`. | The *Request refund* CTA is visible. | |
| Click *Request refund*. Provide an amount ≤ `refund_amount` and a reason. | Redirects to `/refunds` with flash *"Refund #N requested for return #M."* | |
| Open `/refunds`. The new refund is in `requested` status, linked to the return. | | |
| Verify the return's `/returns/{id}` page Refund section. | Shows the new refund row with `requested` status, the requester (you), and the amount. | |

---

## 11. Refund paid from cashbox

| Step | Expected | ✓ |
|---|---|:--:|
| Continuing from journey 10. Log in as **Manager** (`refunds.approve`). | | |
| Open `/refunds`. Approve the requested refund. | Status moves to `approved`. | |
| Log out. Log in as **Accountant** (`refunds.pay`). | | |
| Pay the refund from a cashbox. | Status moves to `paid`. A `cashbox_transactions` row is written: `direction=out`, `source_type=refund`, `reference_type=Refund`, `reference_id=N`. | |
| Verify the cashbox balance dropped by the refund amount. | | |
| Verify the return row was NOT mutated by the refund payment. | `return_status`, `refund_amount`, `notes` unchanged. The linkage is one-way: refund → return, not the other direction. | |

---

## 12. Finance closed period blocks the refund payment

| Step | Expected | ✓ |
|---|---|:--:|
| As **Super Admin**: close the finance period covering today (`/finance/periods`). | | |
| Try to pay a requested refund (journey 11). | Action is blocked with a clear error: period is closed; payment refused. | |
| Verify no cashbox_transactions row was written. | | |
| Verify the refund's status is unchanged (still `approved`). | | |
| Re-open the period. Retry payment. | Succeeds. | |

---

## 13. Order Agent can create return but not approve/pay refund

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Order Agent**. | | |
| Create a return (journey 1). | Succeeds. | |
| Request a refund from the return (journey 10). | Succeeds. | |
| Navigate to `/refunds/{new_refund_id}`. Look for *Approve* / *Pay* buttons. | Both are hidden. | |
| Try forcing them via direct POST. | 403. No DB change. | |

---

## 14. Warehouse Agent can inspect

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Warehouse Agent**. | | |
| Open `/returns`. | Visible (`returns.view` granted). | |
| Open a Pending return. | Inspection block is visible (`returns.inspect` granted). | |
| Inspect as Good + restockable. | Succeeds. Inspector field is set to this user. | |
| Try to *Edit details* or *Close*. | Buttons should NOT be visible (only `returns.create` would allow). | |
| Try direct POST to update / close. | 403. | |

---

## 15. Closed return cannot be edited except allowed fields

| Step | Expected | ✓ |
|---|---|:--:|
| Open a Closed return. | The Edit form block is hidden / disabled (`edit_context.can_edit = false`). | |
| The Close button is hidden. | | |
| Try direct PUT to `/returns/{id}` with refund_amount changes. | Service throws `RuntimeException: Return #N is closed and cannot be edited.` — the controller flashes the error, no DB change. | |
| Try direct POST to `/returns/{id}/inspect`. | Service throws *"Return already inspected. Open a new return record for further changes."* — error flashed, no DB change. | |
| Try direct POST to `/returns/{id}/close`. | Action silently succeeds (idempotent) OR throws — either way, the return remains `Closed`, no audit drift. | |

---

## 16. Filters, counts, and search

| Step | Expected | ✓ |
|---|---|:--:|
| Open `/returns` (default Active view). | Counts on the tabs and chips agree with the underlying data. | |
| Type a partial order number into the search box and press Enter. | The table filters; the counts on the tabs ALSO refresh to reflect just the search hits. | |
| Switch to All while the search is set. | Counts narrowed by the search; everything in the dataset that matches is shown. | |
| Clear the search. | Counts return to full dataset. | |
| Click `Restocked` chip while in Resolved view. | URL becomes `?status=Restocked`. Table shows restocked-only. Subtitle changes accordingly. | |

---

## 17. Returns analytics report (Phase 7)

| Step | Expected | ✓ |
|---|---|:--:|
| Log in as **Manager** (or any role with `reports.profit`). | | |
| Open `/reports` and click *Returns*, OR go directly to `/reports/returns`. | Lands on the Returns analytics page. The page subtitle reads `from → to` for the current date range (defaults to the current month). | |
| First-row cards visible: *Total / Active / Resolved / Damaged*. | All four show numeric counts. Active + Resolved = Total. Each (except Total) shows a percentage hint of its share. | |
| Second-row cards visible: *Refund exposure / Shipping loss / Restocked / Restock rate*. | Refund exposure and shipping loss are **separate cards** (not summed). Restock rate is `Restocked / (Restocked + Damaged)`. | |
| Open the **By status** panel. | All six status values present (zero-filled for empty buckets). Each row shows count + share-of-total + active/resolved bucket label. | |
| Open the **By condition** panel. | All four `product_condition` values present (zero-filled). | |
| Open the **By reason** panel. | One row per used `return_reason`. Sorted by count descending. | |
| Open the **Top returned products** panel. | Up to 10 SKUs with the most distinct returns in the period. Returns count + unit count, descending. | |
| Use the *Last 7d / 30d / 90d* preset chips. | URL updates with `?from=...&to=...`. Every metric (totals, breakdowns, top products) recomputes for the new range. | |
| Set a custom `from` and `to` date and click *Apply*. | Same recompute as the presets. | |
| Log in as a user with **`reports.view` only** (no `reports.profit`). | `/reports/returns` returns 403 — the financial-exposure totals stay hidden from non-finance roles. | |
| Log in as a user with **no `reports.*` slugs** (e.g. order-agent). | Cannot reach `/reports` at all (parent-group 403). | |
| Spot-check: the count of `Pending` rows on `/reports/returns` (status filter chip) equals the *Pending* row on `/returns` (per-status chip). | Numbers agree. The two pages share the same `return_status` source. | |

---

## 18. Audit / history remains traceable

| Step | Expected | ✓ |
|---|---|:--:|
| Pick a return that has gone through `Pending → Restocked → Closed`. | | |
| Query `audit_logs WHERE record_type = OrderReturn AND record_id = N`. | At least three rows: `created`, `inspected`, `closed`. Each carries `user_id`, `action`, `old_values`/`new_values`, `module=returns`. | |
| Query `audit_logs WHERE record_type = Order AND record_id = <order_id>`. | A `status_change` row for the `Delivered → Returned` transition, plus any earlier transitions. `module=orders`. | |
| Query `inventory_movements WHERE reference_type IN (Order, OrderReturn) AND reference_id …`. | The full +qty / -qty story per the inventory rules. Notes are human-readable. | |
| Spot-check the joined picture. | The full forensic history of "this order was returned, the goods were assessed Good and restocked, the return was closed, here's the audit trail" is reconstructible from the three tables. | |

---

## Pass / fail criteria

A Returns release is **release-ready** only when:

- All 17 journeys above run green on a fresh DB.
- The full `php artisan test` suite passes (the contract is automated).
- `npm run build` is clean.
- A hard-refresh in the testing browser is verified after every JS change.

A failure on any single journey is a block — Returns is a low-traffic but high-trust surface, and a regression in either the inventory side or the refund side is operationally expensive.
