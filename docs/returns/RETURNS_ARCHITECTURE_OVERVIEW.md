# Returns — Architecture Overview

> **Companion to:** [README.md](README.md) · [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md)
> **Purpose:** The big picture of how Returns fits into HBS_ERP — entities, relationships, data flow, and module boundaries. Documents **current as-built behaviour**; design recommendations live in the lifecycle / roadmap docs.

---

## 1. What "Returns" means in HBS_ERP

A **Return** is the recorded handling of merchandise the customer is sending (or has sent) back to the warehouse. It is a *lifecycle record*, not a money movement. It tracks:

- **Which order** the goods came from.
- **Why** the goods are coming back (`return_reason`).
- **What condition** the goods are in (`product_condition`).
- **Whether** they can re-enter stock (`restockable`).
- **What inspection verdict** the warehouse reached.
- **How much**, if any, the customer is owed back (`refund_amount` — the *intent*, not the *posted refund*).
- **How much shipping cost** the business absorbed on the failed delivery (`shipping_loss_amount`).
- **The audit trail** of every state change.

A return is **separate from** the cash refund that may or may not follow it. The refund is a Finance-module concern with its own lifecycle, approval chain, and cashbox posting. See [RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md](RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md).

---

## 2. Entities

### 2.1 Core entities

| Entity | DB table | Role |
|---|---|---|
| **Order** | `orders` | The source of the return. An order has zero or one return (one-return-per-order rule). When the return is opened, the order's `status` is moved to `Returned`. |
| **OrderReturn** | `returns` | The return record itself. Holds the `return_status` lifecycle, `product_condition`, `refund_amount`, `shipping_loss_amount`, inspector, timestamps. |
| **ReturnReason** | `return_reasons` | The lookup table of reasons (e.g. *"Customer Did Not Answer"*, *"Product Damaged"*, *"Refused Delivery"*). Active/Inactive flag, no business logic. |
| **OrderItem** | `order_items` | The line items on the originating order — the inspection step iterates over these to write inventory movements. |
| **InventoryMovement** | `inventory_movements` | Each restock or restock-reversal writes one row here (`movement_type = 'Return To Stock'`). |
| **Refund** | `refunds` | **Owned by Finance, not Returns.** Linked back via `refunds.order_return_id`. Has its own lifecycle (`requested → approved/rejected → paid`) and posts to `cashbox_transactions` on payment. |
| **Cashbox / CashboxTransaction** | `cashboxes`, `cashbox_transactions` | The money side. Returns never touch cashboxes; only refunds do. |
| **Shipment** | `shipments` | Snapshot info — the return record copies `shipping_company_id` from the order's active shipment at open-time so the audit trail is intact even if the shipment record changes later. |

### 2.2 Supporting entities

| Entity | Role |
|---|---|
| **User** | The `created_by`, `updated_by`, `inspected_by` references on a return point at users. |
| **AuditLog** | Every return mutation (create, update, inspect, close, request refund) writes an `audit_logs` row via `AuditLogService`. |
| **Customer** | The customer linked to the order. Returns refresh the customer's risk score on inspection (`CustomerRiskService::refreshFor`). |
| **FiscalYear / FinancePeriod** | Returns themselves are not period-locked. Refunds posted from a return ARE — that lock lives in the Finance module. |

---

## 3. Relationships

```
                      ┌────────────────┐
                      │   Customer     │
                      └────────┬───────┘
                               │ (one-to-many)
                               ▼
┌──────────────┐          ┌────────────┐         ┌─────────────────┐
│   Order      │ 1 ── 1+  │ OrderItem  │         │ ReturnReason    │
│  (status:    │──────────│            │         │ (lookup)        │
│   Returned)  │          └────────────┘         └────────┬────────┘
└──┬───────────┘                                          │
   │ 1 ── 0|1                                             │ N ── 1
   ▼                                                      │
┌──────────────────────────────────────────────────────────┐
│              OrderReturn  (`returns` table)              │
│                                                          │
│   return_status:    Pending → … → Closed                 │
│   product_condition: Good | Damaged | Missing | Unknown  │
│   refund_amount, shipping_loss_amount, notes             │
│   inspected_by, inspected_at, restockable                │
└──┬────────────────────────────────────┬────────────────┘
   │                                    │
   │ writes (via InventoryService)      │ may spawn (Phase 5C)
   ▼                                    ▼
┌──────────────────────┐         ┌──────────────────────┐
│ InventoryMovement    │         │ Refund               │
│ type=Return To Stock │         │ (Finance module —    │
│ +qty or −qty         │         │  has its own         │
│                      │         │  approval & payment) │
└──────────────────────┘         └──────────┬───────────┘
                                            │ pay()
                                            ▼
                                  ┌──────────────────────┐
                                  │ CashboxTransaction   │
                                  │ source_type=refund   │
                                  │ (outflow)            │
                                  └──────────────────────┘
```

**Key cardinality rules** (enforced in code):

- **One return per order.** `OrderStatusFlowService` checks `$order->returns()->exists()` before opening a new one and throws if a return already exists. Backend `OrderReturnRequest::rules()` echoes this rule for the direct-create path.
- **A return belongs to exactly one order.** `order_id` is required + foreign-keyed.
- **A return may have zero or many refunds.** Multiple partial refunds against the same return are allowed (`refunds.order_return_id`); the limit is the `refund_amount` field on the return minus the sum of active refunds.
- **A refund belongs to exactly one return** (when created from the return-refund path). It does NOT carry inventory side-effects — it is a money movement.

---

## 4. Current data flow

The five canonical paths.

### 4.1 Order → Returned (the "from-status-change" path)

This is the primary entry point. The operator is on Orders/Show or Orders/Edit, picks `Returned` from the status dropdown, fills the Return Details panel, and saves. The flow:

```
User clicks "Change status" / "Save"
        │
        ▼
OrdersController::changeStatus  OR  OrdersController::update
        │  validates payload  (incl. return.return_reason_id required_if status=Returned)
        │  checks permission  (returns.create — separate from orders.change_status)
        │  checks fiscal year lock
        ▼
OrderStatusFlowService::changeStatus
        │  (atomic DB::transaction)
        │
        ├─ 1. ReturnService::open()         → OrderReturn row in `Pending`
        │       writes audit_log(action=created, module=returns)
        │
        ├─ 2. OrderService::changeStatus('Returned')
        │       ├─ updates orders.status, stamps returned_at
        │       ├─ writes order_status_history row
        │       ├─ writes audit_log(action=status_change, module=orders)
        │       └─ InventoryService::record('Return To Stock', +qty)
        │            ONLY for post-ship transitions
        │            (Shipped / Out for Delivery / Delivered → Returned)
        │
        └─ 3. MarketerWalletService::syncFromOrder()
                  (if order has a marketer_id)
        │
        ▼
Redirect to /returns/{new_return_id}
```

The atomic transaction is critical: if anything throws in step 2 or 3 (e.g. fiscal-year guard fails), step 1 is rolled back too. No half-state.

### 4.2 Direct return creation (the "from-/returns/create" path)

A less common entry point used when the order was already moved to `Returned` outside this flow (e.g. legacy data, or the operator forgot to use the modal). The form on `/returns/create` lets the operator pick an order that **has no existing return** and write a return record against it.

```
ReturnsController::create  (preselects ?order_id=X if reachable)
        │
        ▼
ReturnsController::store
        │  validates via OrderReturnRequest
        ▼
ReturnService::open(payload)
        │  OrderReturn row in `Pending`
        ▼
Redirect to /returns/{new_id}
```

Note this path does **NOT** change the order's status. It assumes the order is already `Returned`. The atomic flow (4.1) is preferred wherever possible.

### 4.3 Inspection

```
On /returns/{id}, operator clicks "Inspect"
        │
        ▼
ReturnsController::inspect
        │  validates condition + restockable + optional refund_amount
        ▼
ReturnService::inspect()
        │  (atomic DB::transaction)
        │
        ├─ writes inventory_movement(s):
        │     ├─ if Good + restockable      → no-op (keeps the optimistic +qty)
        │     └─ else (Damaged | Missing |  → write -qty REVERSAL of the optimistic restock
        │         Unknown | !restockable)
        │
        ├─ updates the return row:
        │     return_status      = restockable ? 'Restocked' : 'Damaged'
        │     product_condition  = …
        │     restockable        = …
        │     inspected_by/at    = now()
        │
        ├─ refreshes customer risk score
        │
        └─ writes audit_log(action=inspected, module=returns)
```

The "always write the optimistic restock at status-change time, then conditionally reverse at inspection time" model is the current as-built behaviour. Trade-offs and the alternative `restock-on-inspection` model are documented in [RETURNS_INVENTORY_AND_RESTOCKING_RULES.md](RETURNS_INVENTORY_AND_RESTOCKING_RULES.md).

### 4.4 Limited edit (refund_amount / shipping_loss / notes)

A back-office correction path. The Return Show page renders an Edit form for three NON-state-machine fields. The service guards:

- The return must not be `Closed`.
- `refund_amount` cannot drop below the sum of linked active refunds (`Refund::ACTIVE_STATUSES`) — i.e. you cannot reduce the intent below what is already in flight.
- A `lockForUpdate()` re-check is performed inside the transaction so two concurrent edits cannot race.
- Audit log entry written on any actual change; no-op edits do not write a row.

### 4.5 Close

```
On /returns/{id}, operator clicks "Close"
        │
        ▼
ReturnsController::close
        │
        ▼
ReturnService::close()
        │
        ├─ return_status = 'Closed'
        ├─ optional appended note
        └─ writes audit_log(action=closed, module=returns)
```

Closing is **idempotent in terms of inventory** — it writes no movement. It is purely a lifecycle finalisation marker.

---

## 5. Module boundaries

The Returns module is a thin layer. Most of the heavy lifting belongs to its neighbours.

| Module | What it owns | Where the wall sits |
|---|---|---|
| **Returns** | The `OrderReturn` row, its status lifecycle, the inspection result, the refund INTENT (`refund_amount`), the shipping loss, the notes, the audit trail of state changes. | Returns calls Inventory to write `Return To Stock` movements. Returns calls Finance (via the `requestRefund` endpoint) to create a refund record — but **Finance owns** what happens next. |
| **Orders** | The `orders.status` enum, the order's status history, the order-side audit log. | Orders owns the `Returned` status transition itself; `OrderStatusFlowService` is the **only** layer that legitimately writes a return + flips order status atomically. |
| **Inventory** | All `inventory_movements` rows. `InventoryService::record()` is the only legitimate way to write a movement. | Returns asks Inventory to write a `Return To Stock` movement at two moments: (a) the optimistic restock on order-status-→-Returned, (b) the reversal at inspection time if the goods aren't restockable. |
| **Finance / Refunds** | The `Refund` lifecycle (`requested → approved/rejected → paid`), the cashbox OUT posting on payment, the fiscal-year guard, the refund permissions, the refund reports. | Returns does NOT post refunds. The Returns Show page surfaces a "Request refund" CTA which creates a `Refund` in `requested` — from that point, the refund is a Finance entity. |
| **Marketers (Wallet)** | When a marketer-attached order is returned, the marketer's pending profit is reversed by `MarketerWalletService::syncFromOrder()`. | Returns never writes marketer wallet entries directly. The Order status transition does. |
| **Shipping** | `Shipment` records, courier states, COD reconciliation. | Returns snapshots `shipping_company_id` at open-time but never mutates shipping records. |
| **Customers** | Customer profile + risk score. | Returns triggers `CustomerRiskService::refreshFor($customer)` after inspection. |
| **Audit** | Every state change writes to `audit_logs` via `AuditLogService`. | Returns logs `created`, `updated`, `inspected`, `closed` against `module = 'returns'`. |

The principle: **Returns is the lifecycle layer.** It does not move money, does not own stock, does not own orders. Its single source of truth is the `returns` row.

---

## 6. Read paths

Three primary read surfaces serve the Returns data:

| Page / Endpoint | What it shows |
|---|---|
| **GET `/returns`** (`ReturnsController::index`) | The Returns queue. Tabbed by mode (Active / Resolved / All) and filterable by specific status, with counts. The default view excludes resolved statuses so the queue isn't cluttered by finished work. *(Tabs + counts are part of the pending Phase-1 work; the default-Active behaviour is in `main`.)* |
| **GET `/returns/{return}`** (`ReturnsController::show`) | The single-return management page. Loads the order + items + customer + reason + inspector + linked refunds. Surfaces the `refund_context` (refundable remaining, active refund total) and `order_context` (mismatch warnings if the order's status drifted from `Returned`/`Cancelled`). |
| **GET `/orders/{order}`** (`OrdersController::show`) | The order page. Surfaces `has_return` + `existing_return` (`id`, `return_status`, `product_condition`) so the operator can render a *Manage return* CTA and a context banner. *(Banner + button are part of the pending Phase-1 work.)* |

`/orders/{order}/edit` also receives the same return-context props so the Edit page can hint *"this order already has a return — open the return"* without losing the editing surface.

---

## 7. Where the code lives

| Layer | Files |
|---|---|
| **Models** | `app/Models/Order.php`, `app/Models/OrderReturn.php`, `app/Models/ReturnReason.php`, `app/Models/Refund.php` |
| **Services** | `app/Services/ReturnService.php` (open/inspect/updateDetails/close), `app/Services/OrderStatusFlowService.php` (the atomic Returned-transition wrapper), `app/Services/OrderService.php` (the inventory hook for Returned transitions) |
| **Controllers** | `app/Http/Controllers/ReturnsController.php`, `app/Http/Controllers/OrdersController.php` (the show/edit/changeStatus/update endpoints) |
| **Requests** | `app/Http/Requests/OrderReturnRequest.php`, `app/Http/Requests/ReturnInspectRequest.php`, `app/Http/Requests/UpdateOrderRequest.php` |
| **Routes** | `routes/web.php` — the `Route::middleware('permission:returns.…')` group |
| **Pages** | `resources/js/Pages/Returns/{Index,Show,Create}.jsx`, plus `resources/js/Pages/Orders/{Show,Edit}.jsx` for the integration points |
| **Tests** | `tests/Feature/Returns/{ReturnInventoryTest,ReturnManagementTest,ReturnsIndexVisibilityTest}.php`, `tests/Feature/Orders/ReturnFromStatusChangeTest.php` |
| **Docs** | This folder. |

---

## 8. Non-goals

To keep the module's scope clean, these are **explicitly NOT** Returns concerns:

- Posting money in or out of any cashbox. (Finance.)
- Holding a fiscal-year / finance-period guard. (Finance.)
- Sending a replacement to the customer — that's an Order-level operation. (Today the "send replacement" workflow does not exist as a first-class feature; see [RETURNS_ERP_WORKFLOW_ROADMAP.md](RETURNS_ERP_WORKFLOW_ROADMAP.md) Phase 6.)
- Counting/aggregating returns for marketing reports. (Reports module.)
- Notifying the customer of return progress. (Out of scope today; could become a future hook.)

If a feature request crosses one of these lines, route it to the owning module first.
