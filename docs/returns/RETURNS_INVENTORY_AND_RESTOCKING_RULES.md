# Returns — Inventory & Restocking Rules

> **Companion to:** [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) · [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md) (Phase 4)
> **Purpose:** Document the current inventory side-effects of the Returns lifecycle.
>
> ⚠️ **This module crosses into Inventory.** Any change here is a Phase 4 change and **needs explicit business approval.**
>
> 📌 **Phase 4B shipped (commit pending review)** — switched from optimistic-restock-on-Returned to restock-after-good-inspection. The pre-Phase-4B "current behaviour" sections below describe the **historical** model; the live behaviour is described in §1 and §12.

---

## 1. Current as-built behaviour (Phase 4B policy)

| Step | Inventory side-effect |
|---|---|
| `ReturnService::open()` — return record created (`Pending`) | **None.** Creating the return row does not touch inventory. |
| `OrderService::changeStatus($order, 'Returned')` for a **post-ship** order (Shipped / Out for Delivery / Delivered → Returned) | **None.** Stock is NOT optimistically restored. On-hand stays at the post-Ship level until inspection. *(Phase 4B change: previously wrote `+qty Return To Stock` referenced to the Order.)* |
| `OrderService::changeStatus($order, 'Returned')` for a **pre-ship** order | **None.** Pre-ship → Returned writes nothing — there is no on-hand to restore. |
| `ReturnService::markReceived()` *(Phase 3)* | **None.** Pure lifecycle marker. |
| `ReturnService::inspect($return, 'Good', restockable: true)` | **Writes one `inventory_movement` per item.** `movement_type = 'Return To Stock'`, `quantity = +item.quantity`, `reference_type = OrderReturn::class`, `reference_id = return.id`. Notes: *"Return inspected as Good — restocked"*. **This is the single legitimate moment a return restocks stock.** |
| `ReturnService::inspect($return, anything-else)` (Damaged / Missing Parts / Unknown / restockable=false) | **None.** The Ship -qty stays as the write-off baseline. *(Phase 4B change: previously wrote a `-qty Return To Stock` reversal of the optimistic +qty; there is no optimistic +qty to reverse under the new policy.)* |
| `ReturnService::close()` | **None.** Closure writes no inventory rows. |
| `ReturnService::updateDetails()` (refund_amount / shipping_loss / notes edit) | **None.** Limited details edit is finance-intent only. |

### Concrete walkthrough — order with 3 units of one SKU (Phase 4B)

```
Order placed                             on_hand: 100  reserved: 0
Order → Confirmed                        on_hand: 100  reserved: 3   (reserve movement)
Order → Shipped                          on_hand:  97  reserved: 0   (ship movement: -3, releases reservation)
Order → Delivered                        on_hand:  97  reserved: 0   (no movement)
Order → Returned                         on_hand:  97  reserved: 0   (NO movement — stays at post-Ship)
ReturnService::markReceived              on_hand:  97  reserved: 0   (no-op)
ReturnService::inspect Good+restockable  on_hand: 100  reserved: 0   (+3 Return To Stock written here)
ReturnService::close                     on_hand: 100  reserved: 0   (no-op)
```

vs. the same order inspected as Damaged:

```
Order placed                             on_hand: 100  reserved: 0
… same up to Returned …                  on_hand:  97  reserved: 0
ReturnService::markReceived              on_hand:  97  reserved: 0   (no-op)
ReturnService::inspect Damaged           on_hand:  97  reserved: 0   (NO movement — Ship -3 is the write-off)
ReturnService::close                     on_hand:  97  reserved: 0   (no-op)
```

**The on-hand is now accurate at every step** — including during the receive-and-inspect window. The only legitimate moment a return restocks goods is `inspect(Good, restockable=true)`.

---

## 2. Pre-Phase-4B: Why "optimistic restock"? *(historical)*

> 📌 The section below describes the **pre-Phase-4B** rationale for reference. The live model is described in §1. See §12 for the migration record.

The historical model wrote the restock movement *immediately* when the operator marked the order Returned, before any physical inspection. The rationale (then encoded in the comment on `OrderService::applyInventoryForTransition`):

> *"Write the optimistic Return To Stock movement so on-hand reflects goods back in the warehouse. ReturnService::inspect later either keeps this (Good+restockable) or reverses it (Damaged)."*

Three design intents drove this:

1. **Stock becomes available fast.** As soon as the operator marks the order Returned (typically when the courier reports the goods are coming back), the SKU appears as in-stock so other orders can use it. The alternative — wait until the goods are physically inspected — can be hours or days later.
2. **Fewer code paths.** The Order status transition owns the inventory side-effect. `ReturnService::inspect()` only writes a *reversal* in the negative case; it doesn't have to know the order's prior state.
3. **Symmetry with the Ship transition.** The Ship transition writes a `-qty` movement at the moment the order is marked Shipped (not at the moment the courier physically picks up). Returns mirror that.

---

## 3. Trade-off

### Cost of optimism (the operational risk)

Between the `Order → Returned` transition and the inspection verdict, on-hand is **inflated** by the returned quantity. If another order is taken in that window, it can be over-promised against stock that is either:

- Still in transit back to the warehouse, or
- Sitting in the receive bay pending inspection, or
- Already inspected and condemned (Damaged).

For high-velocity SKUs the inflation window can be material. For low-velocity SKUs it almost never matters.

### Benefit of optimism (the operational gain)

Inventory is visibly recovered immediately. Operators don't have to explain to sales "we got 50 units returned yesterday but they don't count as stock until tomorrow because nobody has inspected them yet." Fewer phantom out-of-stock situations.

---

## 4. The alternative — restock-on-inspection (Option B)

A more conservative model:

- **`Order → Returned` writes NO inventory movement.** The order's status is changed, the return row is created, but stock stays decremented.
- **`ReturnService::inspect(Good, restockable: true)` writes the `+qty` `Return To Stock` movement.** This is the moment the goods physically re-enter sellable inventory.
- **`ReturnService::inspect(anything-else)` writes nothing.** There's nothing to reverse.

Concrete walkthrough — same 3-unit Good return under Option B:

```
Order → Returned                         on_hand:  97   (NO movement)
ReturnService::inspect Good              on_hand: 100   (Return To Stock: +3)
ReturnService::close                     on_hand: 100   (no-op)
```

And the Damaged path:

```
Order → Returned                         on_hand:  97   (no movement)
ReturnService::inspect Damaged           on_hand:  97   (no movement — goods are write-off)
ReturnService::close                     on_hand:  97   (no-op)
```

| Trait | Option A (current — optimistic) | Option B (restock-on-inspection) |
|---|---|---|
| Inventory accuracy between Returned and Inspected | inflated by returned qty | accurate |
| Number of inventory movements per Good return | 1 (the +qty at Returned) | 1 (the +qty at Inspected) |
| Number of inventory movements per Damaged return | 2 (the +qty at Returned, then -qty reversal at Inspected) | 0 |
| Audit story for Damaged returns | two movements (positive then negative) — must be read together | zero movements — the absence is the story |
| Speed at which stock comes back online | immediately on Returned | only after physical inspection |
| Risk of over-selling | non-zero in the inflation window | zero |
| Operator load | inspection is a soft confirmation | inspection is the legitimising action |
| Mental model fidelity | "Returned ⇒ stock is back" (wrong for Damaged; corrected later) | "Inspected Good ⇒ stock is back" (always accurate) |
| Migration cost from current state | n/a (current) | mid — needs careful code change + test rewrite + business comms |

---

## 5. Recommendation

**Short-term (no behaviour change):** keep Option A. It is deliberately the current behaviour, it is fully covered by tests (`ReturnInventoryTest`), and the inflation-window risk has not, as far as we know, caused a real over-sell. Documenting it is the priority — most of the value of this document is just that the operations team now knows the rule.

**Long-term:** Phase 4 should re-open the question with operations. The relevant input from operations is:

- What's the average elapsed time between `Order → Returned` and the inspection verdict?
- How many high-velocity SKUs are involved?
- Has a real over-sell ever happened because of a returned-but-not-inspected unit?
- Are stock counts done frequently enough to catch a discrepancy?

If the gap is hours and high-velocity SKUs are involved, **Option B is the correct long-term answer.** If the gap is minutes (inspection happens within the same shift, before next-day orders are taken), Option A is fine.

**Until Phase 4 lands, the system MUST NOT mix the two models.** Specifically, do not change `OrderService::applyInventoryForTransition` to skip the optimistic restock without also changing `ReturnService::inspect` to no longer write a reversal — that combination would leave on-hand decremented and never recover.

---

## 6. The damaged-stock write-off

Today there is **no explicit damaged-stock account**. When an inspection concludes Damaged:

- The optimistic +qty is reversed (so on-hand is back to the post-ship level — i.e. stock is *not* counted as available).
- The `returns` row carries `product_condition = 'Damaged'`, `return_status = 'Damaged'`.
- No `damaged_stock` ledger entry is written anywhere.

The write-off is captured **in the return row + audit log**, not in a parallel stock account. This is intentional for the current scope — the company has not asked for a damaged-stock register — but it has implications:

- A future "what is my damaged-stock total" report has to JOIN orders + returns + items, not just read from one table.
- There's no UI to dispose of damaged stock — it simply exists as a damaged-return record forever.

A future phase could introduce a `DamagedStockMovement` type. **Not on the current roadmap.**

---

## 7. Closing a return — explicitly zero inventory side-effect

This is worth a separate callout because it is a common confusion. **`ReturnService::close()` writes NO inventory movement.** Closure is a lifecycle finalisation marker only. The reasons:

- By the time a return is closeable, inspection has already happened and inventory is correct (either the optimistic +qty stands or it has been reversed).
- Closure doesn't physically move anything — the goods are already wherever inspection put them.
- Writing an inventory row on close would be double-counting whatever the inspection did.

The single inventory-relevant question at close-time is "did the inspection happen?". The answer is "yes — `return_status` is `Restocked` or `Damaged`, both of which are reachable only through `inspect()`."

---

## 8. Warehouse selection — current rule

`ReturnService::inspect` uses `InventoryService::defaultWarehouse()` to choose where the movement is written. This is a system-wide singleton — there is no per-return warehouse routing today. Implications:

- Multi-warehouse companies that physically receive returns at a different location than the default cannot reflect that today.
- The `inventory_movements.warehouse_id` will always be the default for return-restocks.

Phase 6 (or a separate multi-warehouse phase) would introduce per-return warehouse routing.

---

## 9. Audit trail — what is recorded

Every inventory movement written by a return path includes:

- `reference_type` — either `App\Models\Order` (for the optimistic +qty written at order-status-→-Returned) or `App\Models\OrderReturn` (for the reversal written by `inspect()`).
- `reference_id` — the order id or the return id, respectively.
- `notes` — `"Order {order_number} returned"` for the optimistic +qty; `"Reversal — return inspected as <condition>"` for the reversal.

Combined with the `audit_logs` rows written by `AuditLogService`, this gives a full forensic trail: WHO did WHAT WHEN, plus exactly which inventory_movements row matches each lifecycle step. **Do not regress this** — any future change that writes a return-related movement must keep both the `reference_type+reference_id` linkage and a human-readable `notes` field.

---

## 10. Tests pinning the current behaviour

Located at `tests/Feature/Returns/ReturnInventoryTest.php`:

| Test | What it pins |
|---|---|
| `ReturnInventoryTest::bug_a_delivered_to_returned_writes_return_to_stock` | Optimistic +qty is written on Delivered → Returned. |
| `ReturnInventoryTest::bug_a_pre_ship_to_returned_does_not_phantom_restock` | Pre-ship → Returned writes NO movement (no phantom +qty). |
| `ReturnInventoryTest::good_return_inspection_restores_full_on_hand` | Good + restockable inspection writes no further movement; on-hand stays at post-Returned level. |
| `ReturnInventoryTest::bug_b_damaged_return_does_not_double_decrement` | Damaged inspection writes exactly one −qty reversal; net change from post-ship baseline is zero. |
| `ReturnInventoryTest::bug_c_change_status_returns_flash_error_not_500` | The status transition surfaces errors as flash messages instead of 500. |
| `ReturnInventoryTest::closing_return_does_not_create_inventory_movement` *(Phase 4A pin)* | `close()` writes zero inventory rows AND leaves on-hand unchanged. Pure lifecycle marker. |
| `ReturnInspectionWorkflowTest::mark_received_does_not_create_inventory_movement` *(Phase 3)* | `markReceived()` writes zero inventory rows. |
| `ReturnInspectionWorkflowTest::inspect_from_received_path_still_writes_correct_inventory_on_damaged` *(Phase 3)* | Damaged-after-Received writes exactly the same −qty reversal as Damaged-after-Pending. |
| `ReturnInspectionWorkflowTest::inspect_from_received_path_writes_no_extra_movement_on_good_restockable` *(Phase 3)* | Good-after-Received writes zero further movements. |

If Phase 4 ever changes to Option B, these tests must be updated in lockstep with the service change — partial migration is the most dangerous failure mode.

---

## 11. Phase 4A audit outcome (as-shipped: no behaviour change)

Phase 4A was an **audit-only** phase. No production code was modified. The findings:

- **Current optimistic model is correctly implemented** as documented in §1–§4. Every state transition writes (or doesn't write) the rows the design calls for.
- **Test coverage was complete except for one gap:** there was no explicit regression test that `close()` writes zero inventory rows. That test now exists in `ReturnInventoryTest` (the `closing_return_does_not_create_inventory_movement` row above) and is **the only file changed** in Phase 4A.
- **No real over-sell incident has been reported** that can be traced back to the inflation window — but the data to confidently rule it out hasn't been collected either.

### Required operational data before Option B/C can be approved

Phase 4 (the real implementation phase) is **blocked** on operations answering:

1. **Q1 — Average elapsed time from `Order → Returned` to inspection verdict** (target: minutes vs. hours vs. days). Cheap to derive from `audit_logs` once Phase 3's Received timestamps accumulate.
2. **Q2 — Average elapsed time from physical receipt to the `Received` checkpoint** (start collecting now — Phase 3 just shipped).
3. **Q3 — Damage rate** (`product_condition` IN `Damaged`, `Missing Parts`, `Unknown`). Single SQL on `returns` over the last 30 days.
4. **Q4 — Resale velocity for the top-50 returned SKUs**. Drives whether the inflation window is materially over-sellable.
5. **Q5 — Has any real over-sell incident traced to a returned-but-not-inspected unit?** If no incidents in 6+ months of operations, Option A is correct forever.
6. **Q6 — Which warehouses inspect on-the-spot vs. batch?** (Phase 3 unlocks batch — if no warehouse uses it, Phase 4 urgency drops.)
7. **Q7 — Are orders ever marked `Returned` before the parcel is physically in transit back?** If yes, the inflation window is even longer than the inspection latency.
8. **Q8 — Operational policy: should returned stock be sellable before inspection?** The yes/no answer to this is the policy fork between Option A (yes) and Option B (no).

### Anti-rules (do not implement these)

- **Do NOT remove the optimistic +qty in `OrderService::applyInventoryForTransition` alone.** Without also removing the −qty reversal in `ReturnService::inspect()`, on-hand will be permanently decremented for every Damaged return. The two changes are inseparable.
- **Do NOT add an `expected_return` movement type without explicit business buy-in.** Option C requires changing every on-hand SUM query in the codebase — high blast radius for unproven operational benefit.
- **Do NOT mix Phase 4 with any other Returns release.** Per the doc §5 warning, the migration must ship in a dedicated, revertible commit so any over-sell or stuck-stock incident can be rolled back without un-doing unrelated work.
- **Do NOT add a per-return UI toggle for restock timing.** That's a policy decision, not a per-record decision — a UI knob would invite drift across warehouses.

### What we DID change in Phase 4A

- Added `tests/Feature/Returns/ReturnInventoryTest::test_closing_return_does_not_create_inventory_movement` — pinning that `close()` writes zero inventory rows. Pure regression test; no production code touched.
- This document — appended this section. Restocking rules above §11 are unchanged.

---

## 12. Phase 4B as-shipped — restock-after-good-inspection

**Date shipped:** following commit `03d19d4` (Phase 4A audit).

### Business decision applied

The audit questions from §11 were answered by operations as follows:

| Question | Answer |
|---|---|
| Q1 — Avg elapsed time Returned → Inspection | **~1 day** |
| Q3 — Damage / not-restockable rate | **~5%** |
| Q5 — Has oversell ever happened? | **Yes, may have** |
| Q8 — Should returned stock be sellable before inspection? | **No** |

A ~1-day inflation window combined with confirmed (or suspected) over-sell incidents made Option B from the §5 trade-off unambiguous. Stock now comes back only after a physical inspector verdicts the goods Good + restockable.

### Final policy (live)

| Trigger | Inventory effect | Notes |
|---|---|---|
| Order → Returned (any origin) | **None** | Inflation window eliminated. The Ship -qty stays. |
| markReceived() | **None** | Phase 3 marker — unchanged. |
| inspect(Good, restockable=true) | **+qty Return To Stock** | Single source of return-related restock. `reference_type=OrderReturn`. |
| inspect(Damaged \| Missing Parts \| Unknown) OR restockable=false | **None** | Ship -qty is the write-off baseline; no reversal needed (nothing to reverse). |
| close() | **None** | Lifecycle marker. |
| updateDetails() | **None** | Finance-intent edit. |

### Code change footprint

| File | Change |
|---|---|
| `app/Services/OrderService.php` | Removed the post-ship → Returned `returnToStock` branch from `applyInventoryForTransition`. Comment block updated to document the new policy. |
| `app/Services/ReturnService.php::inspect()` | Inverted the inventory branch: Good + restockable now writes `+qty` (was: no-op). Any other verdict now writes nothing (was: `-qty` reversal). Class docblock updated. |
| `tests/Feature/Returns/ReturnInventoryTest.php` | 4 existing tests rewritten + 1 new test added (`missing_parts_or_not_restockable_inspection_writes_no_inventory_movement`). |
| `tests/Feature/Returns/ReturnInspectionWorkflowTest.php` | 2 inventory-asserting tests rewritten for the new policy. |
| `tests/Feature/Orders/ReturnFromStatusChangeTest.php` | 1 inventory test rewritten (renamed to `test_returned_no_longer_writes_optimistic_return_to_stock`). |

### What this MUST NOT regress (anti-rules)

- **Pre-ship → Returned must still write nothing.** Already pinned.
- **Damaged inspection must write zero rows.** Specifically: no `+qty Return To Stock`, no `-qty Return To Stock` reversal, no `Return Damaged` row. The damage is captured by `product_condition` + `return_status` only.
- **Good inspection must write exactly ONE row per item.** Reference is the OrderReturn, not the Order.
- **markReceived() and close() must remain inventory no-ops.**

### Rollback

If a production over-sell or stuck-stock incident traces to Phase 4B, the rollback is purely revert the commit. The change is contained to two service methods + their test files. No migration to undo. No data fix required — any returns inspected as Good under the new policy already have their `+qty Return To Stock` referenced to the OrderReturn, and a revert would leave that intact (the old code wrote the `+qty` referenced to the Order at status-change time; both forms produce the correct on-hand sum, the difference is purely the reference linkage).

### Tests pinning the new policy (Phase 4B)

| Test | Rule pinned |
|---|---|
| `ReturnInventoryTest::test_delivered_to_returned_does_not_write_return_to_stock` | Post-ship → Returned writes zero `Return To Stock` rows |
| `ReturnInventoryTest::test_pre_ship_to_returned_still_does_not_phantom_restock` | Pre-ship → Returned writes zero rows (unchanged) |
| `ReturnInventoryTest::test_good_restockable_inspection_writes_return_to_stock` | `inspect(Good, true)` writes one `+qty` referenced to OrderReturn |
| `ReturnInventoryTest::test_damaged_inspection_writes_no_inventory_movement` | `inspect(Damaged, false)` writes zero rows; on-hand stays at post-Ship |
| `ReturnInventoryTest::test_missing_parts_or_not_restockable_inspection_writes_no_inventory_movement` | Missing Parts / Unknown / Good+restockable=false all write zero rows |
| `ReturnInventoryTest::test_closing_return_does_not_create_inventory_movement` | `close()` writes zero rows (Phase 4A pin, still valid) |
| `ReturnInspectionWorkflowTest::test_inspect_from_received_good_writes_return_to_stock` | Good after Received writes one `+qty` |
| `ReturnInspectionWorkflowTest::test_inspect_from_received_damaged_writes_no_inventory_movement` | Damaged after Received writes zero rows |
| `ReturnInspectionWorkflowTest::test_mark_received_does_not_create_inventory_movement` | `markReceived()` writes zero rows (Phase 3 pin) |
| `ReturnFromStatusChangeTest::test_returned_no_longer_writes_optimistic_return_to_stock` | The atomic flow (HTTP route) also writes zero `Return To Stock` on Returned |
