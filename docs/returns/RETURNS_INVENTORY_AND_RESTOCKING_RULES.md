# Returns — Inventory & Restocking Rules

> **Companion to:** [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) · [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md) (Phase 4)
> **Purpose:** Document the current inventory side-effects of the Returns lifecycle, the alternative model, the trade-offs, and the recommendation.
>
> ⚠️ **This module crosses into Inventory.** Any change here is a Phase 4 change and **needs explicit business approval.**

---

## 1. Current as-built behaviour

| Step | Inventory side-effect |
|---|---|
| `ReturnService::open()` — return record created (`Pending`) | **None.** Creating the return row does not touch inventory. |
| `OrderService::changeStatus($order, 'Returned')` for a **post-ship** order (Shipped / Out for Delivery / Delivered → Returned) | **Writes one `inventory_movement` row per item.** `movement_type = 'Return To Stock'`, `quantity = +item.quantity`, `reference_type = Order::class`, `reference_id = order.id`. This is the *optimistic restock*. |
| `OrderService::changeStatus($order, 'Returned')` for a **pre-ship** order | **None.** Pre-ship → Returned writes nothing — there is no on-hand to restore. |
| `ReturnService::inspect($return, 'Good', restockable: true)` | **None.** The optimistic +qty stands. (The "no-op" branch in `inspect()` is the success case.) |
| `ReturnService::inspect($return, anything-else)` | **Writes one `inventory_movement` per item.** `movement_type = 'Return To Stock'`, `quantity = -item.quantity`, `reference_type = OrderReturn::class`, `reference_id = return.id`. This **reverses** the optimistic restock. The note says `"Reversal — return inspected as <condition>"`. |
| `ReturnService::close()` | **None.** Closure writes no inventory rows. |
| `ReturnService::updateDetails()` (refund_amount / shipping_loss / notes edit) | **None.** Limited details edit is finance-intent only. |

### Concrete walkthrough — order with 3 units of one SKU

```
Order placed                             on_hand: 100  reserved: 0
Order → Confirmed                        on_hand: 100  reserved: 3   (reserve movement)
Order → Shipped                          on_hand:  97  reserved: 0   (ship movement: -3, releases reservation)
Order → Delivered                        on_hand:  97  reserved: 0   (no movement)
Order → Returned                         on_hand: 100  reserved: 0   (optimistic Return To Stock: +3)
ReturnService::inspect Good+restockable  on_hand: 100  reserved: 0   (no-op; the +3 stays)
ReturnService::close                     on_hand: 100  reserved: 0   (no-op)
```

vs. the same order inspected as Damaged:

```
Order placed                             on_hand: 100  reserved: 0
… same up to Returned …                  on_hand: 100  reserved: 0
ReturnService::inspect Damaged           on_hand:  97  reserved: 0   (reversal: -3, cancels the optimistic +3)
ReturnService::close                     on_hand:  97  reserved: 0   (no-op)
```

The net on-hand correctly reflects reality at every step **after** inspection. The window in which on-hand is *wrong* is the gap between `Order → Returned` and the inspection verdict.

---

## 2. Why "optimistic restock"?

The current model writes the restock movement *immediately* when the operator marks the order Returned, before any physical inspection. The rationale (encoded in the comment on `OrderService::applyInventoryForTransition`):

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
| `bug_a_delivered_to_returned_writes_return_to_stock` | Optimistic +qty is written on Delivered → Returned. |
| `bug_a_pre_ship_to_returned_does_not_phantom_restock` | Pre-ship → Returned writes NO movement (no phantom +qty). |
| `good_return_inspection_restores_full_on_hand` | Good + restockable inspection writes no further movement; on-hand stays at post-Returned level. |
| `bug_b_damaged_return_does_not_double_decrement` | Damaged inspection writes exactly one −qty reversal; net change from post-ship baseline is zero. |
| `bug_c_change_status_returns_flash_error_not_500` | The status transition surfaces errors as flash messages instead of 500. |

If Phase 4 ever changes to Option B, these tests must be updated in lockstep with the service change — partial migration is the most dangerous failure mode.
