# Returns — Permissions & Roles

> **Companion to:** [RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md](RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md) · `database/seeders/PermissionsSeeder.php` · `database/seeders/RolesSeeder.php`
> **Purpose:** Document the existing permission slugs for the Returns module, the role-to-action matrix, and the separation-of-duties principle that underpins it.

---

## 1. Permission slugs — current

Seeded in `PermissionsSeeder` under the `returns` module:

| Slug | What it gates |
|---|---|
| `returns.view` | Read access — `/returns` index, `/returns/{id}` show. Required to render the *Manage return* button on the order page (the Phase 1 pending work). |
| `returns.create` | Open a new return record. Required by `ReturnsController::store`, `OrdersController::changeStatus` (when target is `Returned`), and `OrdersController::update` (when transitioning into `Returned`). Also currently used as the gate for `update` (limited-fields edit) and `close`. |
| `returns.inspect` | Run the inspection step. Required by `ReturnsController::inspect` → `ReturnService::inspect`. |
| `returns.approve` | Reserved. Not yet wired to any route. Intended for Phase 3 — a possible "approve inspection result" step before the verdict is final. |

The **refund** side of the wall has its own slugs, separately documented in `docs/finance/PHASE_0_PERMISSIONS_AND_ROLES.md`:

| Slug | What it gates |
|---|---|
| `refunds.view` | Read access to the refunds list/detail pages. |
| `refunds.create` | Create a `requested` refund — used by `ReturnsController::requestRefund` to bridge Returns → Finance. |
| `refunds.approve` | Move a refund from `requested` → `approved`. |
| `refunds.reject` | Move a refund from `requested` → `rejected`. |
| `refunds.pay` | The final cashbox OUT posting. Period-locked. |

A user reading "permission to do anything refund-shaped" needs to look at BOTH columns — Returns slugs gate the lifecycle paperwork; Refund slugs gate the money movement.

---

## 2. Recommended role matrix

Sourced from the current `RolesSeeder` plus the recent `ac08d7e` grant of `returns.create` to `order-agent`. Cells marked **maybe** are configurable per deployment — the seeder ships the conservative default.

| Action | Super Admin | Admin | Manager | Order Agent | Warehouse Agent | Accountant | Marketer | Viewer |
|---|:--:|:--:|:--:|:--:|:--:|:--:|:--:|:--:|
| View returns (`returns.view`) | ✅ | ✅ | ✅ | ✅ | ✅ | maybe | — | ✅ |
| Create return (`returns.create`) | ✅ | ✅ | ✅ | ✅ | — | — | — | — |
| Inspect return (`returns.inspect`) | ✅ | ✅ | ✅ | — | ✅ | — | — | — |
| Edit return details (`returns.create` today; Phase 3 may split) | ✅ | ✅ | ✅ | limited | limited | — | — | — |
| Close return (`returns.create` today; Phase 3 may split) | ✅ | ✅ | ✅ | maybe | maybe | — | — | — |
| Approve inspection result (`returns.approve`, reserved) | ✅ | ✅ | ✅ | — | — | — | — | — |
| **Request refund from return** (`refunds.create`) | ✅ | ✅ | ✅ | ✅ | — | ✅ | — | — |
| **Approve refund** (`refunds.approve`) | ✅ | ✅ | ✅ | — | — | — | — | — |
| **Reject refund** (`refunds.reject`) | ✅ | ✅ | ✅ | — | — | ✅ | — | — |
| **Pay refund / cashbox OUT** (`refunds.pay`) | ✅ | — | — | — | — | ✅ | — | — |
| **Change order status → Returned** (`orders.change_status` + `returns.create`) | ✅ | ✅ | ✅ | ✅ | — | — | — | — |

Notes on **maybe** cells:

- `Accountant — returns.view`: Today the seeder does NOT grant this. The accountant *does* see refund-related data via `refunds.view`. A return is operations paperwork, not finance paperwork. If your operations side wants finance to see return reasons for reconciliation, add `returns.view` explicitly.
- `Order Agent / Warehouse Agent — close`: Today `close` is gated by `returns.create`, so order agents have it and warehouse agents do not. Phase 3 may split closure into its own slug (`returns.close`) — at that point operations needs to decide.

### What the matrix means in practice

- **Super Admin** is the model-level bypass — `User::hasPermission` short-circuits to `true`.
- **Admin** is "everything except destructive system controls" (excludes `permissions.manage`, `year_end.manage`, `backup.manage`). All Returns + Refund operations are within scope.
- **Manager** owns the operational oversight bucket — full Returns workflow plus refund approval. Cannot pay refunds (separation of duties).
- **Order Agent** is the customer-issue intake role — can open a return at the moment the customer reports the problem, and can request a refund as a paperwork action. Cannot inspect, cannot approve, cannot pay.
- **Warehouse Agent** is the physical-handling role — inspects what the warehouse receives. Cannot open returns (since the operations decision precedes the warehouse step).
- **Accountant** is the money role. Does **not** touch Returns at all by default; the refund work happens on the Finance side after the operations side has requested the refund.
- **Marketer** is external; never has any Returns surface access.
- **Viewer** is read-only across operations.

---

## 3. Separation-of-duties principle

The matrix above enforces three SoD rules:

1. **The person who creates the operations paperwork (`returns.create`, `refunds.create`) is not the person who decides on the money (`refunds.approve`).** Order Agent creates; Manager / Admin approves.
2. **The person who approves the money (`refunds.approve`) is not the person who pays it out (`refunds.pay`).** Manager approves; Accountant pays.
3. **The person who inspects physical goods (`returns.inspect`) is independent of both the paperwork creator and the money decider.** Warehouse Agent inspects.

These three are the textbook SoD walls for retail returns. They are the same walls the Finance module enforces for refunds; see `docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md`.

---

## 4. Why `order-agent` was granted `returns.create` (commit `ac08d7e`)

The original seeded `order-agent` role had `orders.change_status` but **not** `returns.create`. Combined with the professional return-management flow (which atomically creates a return when the order moves to `Returned`), this produced a UX deadlock: an order agent could see the `Returned` option in the status dropdown filter but couldn't actually use it without `returns.create`, so the option was silently hidden by the frontend.

The fix was to grant `returns.create` (and `returns.view`) to the `order-agent` role. Order Agents are the front-line intake role — when a customer calls saying "the courier failed delivery, I want to return", the order agent is the natural one to mark the order Returned and open the return record. Granting refund approval / payment would breach SoD; granting `returns.create` does not (the refund is still a separate action, gated by `refunds.create` *and* `refunds.approve` for the money path).

---

## 5. What needs `returns.view`?

`returns.view` is currently the gate for:

| Surface | Why |
|---|---|
| `GET /returns` (`returns.index`) | The Returns queue. |
| `GET /returns/{return}` (`returns.show`) | A single return. |
| The "Manage return" button on `Orders/Show.jsx` *(pending Phase 1)* | Linking *to* a return requires permission to view that page. |
| The "open the return" hint on `Orders/Edit.jsx` *(pending Phase 1)* | Same. |
| The amber return-context banner on `Orders/Show.jsx` *(pending Phase 1)* | The banner contains a link to `/returns/{id}`. |

A user without `returns.view` who reaches `Orders/Show` will see `has_return: true` but will not see the *Manage return* button (the JSX `can('returns.view')` check). They will know a return exists but cannot open it. This is the correct behaviour — if your role is supposed to see returns, give it `returns.view`.

---

## 6. Period-lock / fiscal-year interaction

The route-level `fiscal_year_lock` middleware (applied to `orders.update` and `orders.change-status` per `routes/web.php`) blocks an order-status transition when the order's fiscal year is closed. By extension, opening a return via the atomic flow is blocked too (because that path goes through `OrdersController::changeStatus` or `update`).

The direct return-create path (`POST /returns` → `ReturnsController::store`) does **not** carry `fiscal_year_lock` — direct return creation is a back-office correction tool and the assumption is that closing an order's fiscal year does not bar adding a forgotten return record.

The Finance Phase 5F period-close is independent of the fiscal-year lock and applies to refund payment, not to return creation. See [RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md](RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md) §8.

---

## 7. UI gating — must match the route middleware

The frontend hides actions the user cannot perform. As of the pending Phase-1 work:

| UI element | `can(...)` gate |
|---|---|
| "+ New return" button on `/returns` | `returns.create` |
| "Manage return" button on `Orders/Show.jsx` | `returns.view` (the link target needs view access) |
| "Open return →" link in the order-page banner | `returns.view` |
| Return Details inspect block on `/returns/{id}` | `returns.inspect` (also requires `return.return_status` to be inspectable) |
| Limited-fields Edit form on `/returns/{id}` | `returns.create` (current rule) AND `return_status !== 'Closed'` |
| Close button on `/returns/{id}` | `returns.create` (current rule) AND `return_status !== 'Closed'` |
| "Request refund" CTA on `/returns/{id}` | `refunds.create` AND `return.canRequestRefund()` |

**Defence in depth principle:** the UI gate is a UX nicety — the route middleware (`permission:returns.…`) is the security control. Removing a UI gate without removing the matching route middleware degrades UX but does not break security. Removing the route middleware without removing the UI gate is a real security defect. Always change both sides.

---

## 8. Future / reserved slugs

Slugs that exist in `PermissionsSeeder` for forward-compatibility but are not yet wired to a route:

| Slug | Intended use |
|---|---|
| `returns.approve` | Reserved for Phase 3 — possibly to gate "approve inspection result" or for an approval step between Inspected and Restocked. Currently not granted by any role's wiring. |

When a new slug is added to a phase, it MUST be:

1. Added to `PermissionsSeeder::run()`.
2. Granted to the appropriate roles in `RolesSeeder` (use the most conservative grant — manager and above, until proven otherwise).
3. Used as middleware on the new route (`Route::middleware('permission:returns.<slug>')`).
4. Mirrored in the relevant `can('returns.<slug>')` UI gate.
5. Covered by at least one positive test (allowed role can perform action) and one negative test (denied role gets 403).
