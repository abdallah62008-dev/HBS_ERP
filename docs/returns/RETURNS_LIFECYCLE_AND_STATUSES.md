# Returns — Lifecycle & Statuses

> **Companion to:** [RETURNS_ARCHITECTURE_OVERVIEW.md](RETURNS_ARCHITECTURE_OVERVIEW.md) · [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md)
> **Purpose:** The state machine. What each `return_status` value means today, which actions each state allows or forbids, and a recommended forward lifecycle for Phases 3 / 6.

---

## 1. Current as-built statuses

Defined in `App\Models\OrderReturn`:

```php
public const STATUSES = [
    'Pending',
    'Received',
    'Inspected',
    'Restocked',
    'Damaged',
    'Closed',
];
```

`product_condition` is a parallel dimension (not a status), defined as:

```php
public const CONDITIONS = ['Good', 'Damaged', 'Missing Parts', 'Unknown'];
```

`return_status` describes **where the return is in its lifecycle**.
`product_condition` describes **the verdict the inspector reached**.
They're related (a `Damaged` status implies a non-good condition) but they answer different questions.

---

## 2. Status meanings — current as-built

| Status | Meaning | When entered | Entered by |
|---|---|---|---|
| **Pending** | A return record has been opened but not yet processed. The goods have not been inspected. | At `ReturnService::open()` — i.e. the moment the order moves to `Returned` (atomic flow) OR the moment `/returns/store` is called (direct-create flow). | Any user with `returns.create`. |
| **Received** | The warehouse has physically received the goods but has not yet inspected them. | **Not currently written by any service.** Reserved for a future Received-step UI (Phase 3). The receive checkpoint exists conceptually; today returns go `Pending → Inspected → Restocked/Damaged → Closed` without touching this state. | TBD (Phase 3). |
| **Inspected** | The goods have been inspected but the verdict has not yet resolved into a restock / damage outcome. | **Not currently written by any service.** Reserved for the same Phase 3 work. | TBD (Phase 3). |
| **Restocked** | Inspection concluded **Good and restockable** — the optimistic `Return To Stock` movement stands; goods are back in sellable on-hand. | `ReturnService::inspect($return, condition, restockable=true)` with `condition === 'Good'`. | User with `returns.inspect` (warehouse-agent, manager, admin). |
| **Damaged** | Inspection concluded the goods are **NOT** restockable — either condition is Damaged / Missing Parts / Unknown, OR `restockable` was explicitly false. The optimistic `Return To Stock` was reversed (–qty). | `ReturnService::inspect($return, …, restockable=false)` or any non-Good condition. | User with `returns.inspect`. |
| **Closed** | The return lifecycle is finalised. No further state changes; no inventory side effects on entry. | `ReturnService::close($return)`. | User with `returns.create` (current rule) — *see Phase 4 design note below*. |

> Note: **`Received` and `Inspected` are defined in the constant but currently unused** by any service code path. They are there for Phase 3 to use as intermediate checkpoints. Filtering by them on `/returns` is supported and will simply return zero rows until that phase ships. They are *forward-compatible placeholders*, not legacy data.

---

## 3. Queue mode vs. status

The Returns Index page groups statuses into two **queue modes** for daily operations:

| Queue mode | Statuses included | What it represents |
|---|---|---|
| **Active** *(default view)* | Pending, Received, Inspected, Damaged | Returns the operator still owes work on. Damaged is in Active because a damaged return often still owes a refund decision and/or a write-off. |
| **Resolved** | Restocked, Closed | History — work that has left the operational workflow. |

The `All` view includes both. Each specific status remains directly addressable via `?status=<Name>` regardless of mode.

This is **NOT** a third axis of status. `return_status` is the only stored field; "Active / Resolved / All" is purely a UI grouping over the same six values.

---

## 4. Allowed actions per status

| Action | Pending | Received | Inspected | Restocked | Damaged | Closed |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| View | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| Edit refund_amount / shipping_loss / notes | ✅ | ✅ | ✅ | ✅ | ✅ | **🚫 closed** |
| Inspect | ✅ | ✅ | **🚫 already inspected** | **🚫 already inspected** | **🚫 already inspected** | **🚫 closed** |
| Close | ✅ | ✅ | ✅ | ✅ | ✅ | **🚫 already closed** |
| Request refund (`requestRefund`) | ⚠️ allowed by route but `canRequestRefund()` returns false unless status is in `REFUND_ELIGIBLE_STATUSES` and `refund_amount > 0` | same | ✅ if amount > 0 | ✅ if amount > 0 | ✅ if amount > 0 | ✅ if amount > 0 |
| Receive new inventory movements from this return | ✅ (the optimistic +qty written at order-status-→-Returned) | — | — | ✅ (the +qty stays; this IS the "restocked" outcome) | — | — |
| Reverse inventory movements | — | — | — | — | ✅ (the inspection wrote a −qty reversal of the optimistic restock) | — |

### Refund eligibility — the explicit rule

```php
public const REFUND_ELIGIBLE_STATUSES = ['Inspected', 'Restocked', 'Damaged', 'Closed'];

public function canRequestRefund(): bool
{
    return (float) $this->refund_amount > 0
        && in_array($this->return_status, self::REFUND_ELIGIBLE_STATUSES, true);
}
```

So a return in `Pending` or `Received` cannot legitimately spawn a refund — the inspection step is the moment the refund decision is locked in. The frontend honours this; the backend `requestRefund` controller does **not** enforce it directly (it delegates to `RefundService::createFromReturn` which has its own guards). Phase 5 may tighten this.

---

## 5. What MUST NOT happen in each status

Anti-rules that the service layer enforces:

| Status | Forbidden |
|---|---|
| **Pending** | Inventory cannot be re-recorded (the optimistic +qty was written at order-status-→-Returned; doing it again would double-credit). |
| **Received** | (Phase 3 placeholder — no enforced rules yet.) |
| **Inspected** | (Phase 3 placeholder — once entered for real, transitioning back to Pending would be forbidden.) |
| **Restocked** | Re-inspection is blocked: `ReturnService::inspect()` throws `RuntimeException` *"Return already inspected. Open a new return record for further changes."* The optimistic +qty must not be rewritten. |
| **Damaged** | Same re-inspection block as Restocked. The −qty reversal must not be rewritten (would re-deduct stock). |
| **Closed** | All mutations forbidden via `ReturnService::updateDetails()` and `ReturnService::inspect()` — both throw `RuntimeException`. The frontend hides the Edit form on closed returns (`edit_context.can_edit = false`). |

---

## 6. Transition diagram — current

```
                    ┌─────────┐
       open()  ───▶ │ Pending │
                    └────┬────┘
                         │ inspect()  (atomic)
              ┌──────────┴──────────┐
              │                     │
   condition=Good                 condition=Damaged|Missing|Unknown
   AND restockable=true              OR restockable=false
              │                     │
              ▼                     ▼
        ┌──────────┐          ┌──────────┐
        │Restocked │          │ Damaged  │
        └────┬─────┘          └────┬─────┘
             │                     │
             │     close()         │
             └──────────┬──────────┘
                        ▼
                  ┌──────────┐
                  │  Closed  │  (terminal)
                  └──────────┘
```

`Received` and `Inspected` are reachable as filter values but no `open()`/`inspect()`/`close()` code path transitions INTO them today. Phase 3 will use them as intermediate checkpoints in the warehouse flow.

---

## 7. Recommended long-term lifecycle (Phase 3 + Phase 6)

The current state machine collapses receive + inspect + restock-decision into a single `inspect()` call. That is fast but loses fidelity for warehouses that want a Received step before inspection (e.g. for batched inspections at end of day).

A more typical OMS lifecycle:

```
Requested  →  Received  →  Inspected  →  Resolution Pending  →  Resolved
  (Pending)                                                       │
                                                       ┌──────────┼─────────────┐
                                                       ▼          ▼             ▼
                                                  Restocked  Replacement   Refund Pending
                                                              Sent                │
                                                                                  ▼
                                                                             Refunded
```

Mapping to the current enum:

| Recommended phase | Maps to today's status | Phase change |
|---|---|---|
| Requested | `Pending` | none — rename optional |
| Received | `Received` | new transition `markReceived()` (Phase 3) |
| Inspected | `Inspected` | new transition (Phase 3); becomes a real intermediate state |
| Resolution Pending | n/a today — currently `Restocked` AND `Damaged` are entered directly | new transient state (Phase 3, optional) |
| Restocked | `Restocked` | unchanged |
| Replacement Sent | n/a today — see Phase 6 | **new status** `Reshipped` (Phase 6, needs business decision) |
| Refund Pending | n/a — the refund is a separate Finance record | already correctly modelled — no change needed |
| Closed | `Closed` | unchanged |

**Important — this section is a PROPOSAL.** Phases 3 and 6 will revisit these names. Do not implement them without explicit business approval — they affect every status filter, the role matrix, the audit log, every report, and any external integration that reads `returns.return_status`.

---

## 8. The Active / Resolved / All semantics — pinned

To prevent drift in future work:

- **Active = `{Pending, Received, Inspected, Damaged}`**
- **Resolved = `{Restocked, Closed}`**
- These two sets are **always disjoint** and **always cover every value in `STATUSES`**.
- The backend exposes `status_groups.active` and `status_groups.resolved` on `/returns` so the frontend never hard-codes the lists.

If a future phase adds a status, it MUST be classified into one of these two buckets. A status that doesn't fit either is a design error (it would orphan returns from both the active queue and the resolved history).

---

## 9. Open business questions (decide before Phase 3)

These are surfaced from the current implementation but not yet answered:

1. **Should `Received` be mandatory before `Inspected`?** Today inspection can happen on a `Pending` return without ever passing through `Received`. Phase 3 needs to decide: keep the shortcut, or enforce a Received → Inspected progression.
2. **Should a `Damaged` return automatically open a refund request?** Today no — the operator must explicitly hit "Request refund" from `/returns/{id}`. Some OMS implementations auto-open the refund when the damage is the seller's fault.
3. **Should `Closed` require a closure note?** Today the note is optional. Audit reasons may want it required for damaged-and-closed returns specifically.
4. **What happens to the underlying order's `status` when its return is `Closed`?** Today: nothing — the order stays `Returned`. That's accurate (the order *was* returned) but loses the "return concluded" signal. Phase 6 may introduce a `Resolved` order status; that needs explicit business approval.
5. **What is the "Reshipped" state really for?** If "the warehouse sent a replacement to the customer" is operationally important, it deserves either a new return status (`Reshipped`) or a new Order workflow (a child shipment under the original order). Phase 6 will decide; today it is neither.

These open questions feed [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md).
