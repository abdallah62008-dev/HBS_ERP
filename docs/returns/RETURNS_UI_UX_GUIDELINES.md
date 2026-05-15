# Returns — UI / UX Guidelines

> **Companion to:** [RETURNS_LIFECYCLE_AND_STATUSES.md](RETURNS_LIFECYCLE_AND_STATUSES.md) · [RETURNS_PERMISSIONS_AND_ROLES.md](RETURNS_PERMISSIONS_AND_ROLES.md)
> **Purpose:** The page-by-page UX contract for the Returns surface. Pinned so future work doesn't regress the discoverability and clarity gains of Phase 1.

---

## 1. Top-level principles

These five rules govern every Returns-related screen. They exist to prevent the failure mode that triggered Phase 1: *"the return looked like it disappeared."*

1. **A return must always be reachable from its order**, and the order page must visibly say a return exists.
2. **The default Returns queue shows Active work only** — but it must visibly say so, with counts that prove resolved returns exist elsewhere.
3. **Closed and Restocked returns are never deleted, only relocated** (to the Resolved tab). The UI must communicate this every time a status filter hides records.
4. **Editing an order with a return must not feel like editing the return.** The Edit page is for order fields; the return is managed on `/returns/{id}`.
5. **Money never moves silently.** Refund actions are explicit user clicks with their own confirmation — closing or editing a return must not touch a cashbox.

---

## 2. `Orders/Show.jsx` — the order-side integration

| Element | When shown | Behaviour |
|---|---|---|
| **Page header — "Manage return" button (amber)** | When `existing_return` prop is set AND `can('returns.view')`. | First action button in the header row, before *Timeline / Edit order / Change status / Delete*. Links to `route('returns.show', existing_return.id)`. `title` attribute spells out the return id and status. Color: `bg-amber-600`, prominent. |
| **Page header — "Edit order" button** | When `can('orders.edit')`. | Renamed from *Edit* to *Edit order* once the *Manage return* button exists alongside, to disambiguate which surface the operator is opening. |
| **Return-context banner (amber)** | Directly below the status row, when `existing_return` is set. | Reads: *"This order has a return record #N — status **<status>** · condition **<condition>**. Manage reason, condition, refund and close from the return page."* Includes a right-aligned *"Open return →"* underlined link (gated by `returns.view`). The banner cannot be dismissed. |
| **Status dropdown — Returned option** | Hidden when `has_return` (one-return-per-order rule) OR when `can_create_return` is false. | The modal also surfaces a helper hint explaining why the option is hidden (the prior task added these hints). |

### Don't

- Don't surface return details directly inside the Order Show page. Returns have their own page; cloning the controls invites drift.
- Don't auto-redirect the operator from Orders/Show to the return page. The order page is still the source of truth for shipping/items/profit; an auto-redirect is paternalistic.
- Don't render the "Manage return" button without an `existing_return.id` to point at — the link would 404.

---

## 3. `Orders/Edit.jsx` — the order-edit integration

| Element | When shown |
|---|---|
| **Helper hint** *"This order already has a return record — open the return to manage it."* | When `has_return && order.status !== 'Returned' && existing_return` (an order in a non-Returned status that nevertheless has a return — a slightly unusual data state, e.g. legacy data or operator manually flipped). Link routes via `existing_return.id`. |
| **Helper hint** *"You do not have permission to create return records, so the Returned status option is hidden here."* | When `!can_create_return && !has_return && order.status !== 'Returned'`. (Pre-existing — left as-is.) |
| **Return Details expansion block** | When `isNewReturned` (the operator just picked Returned in the dropdown of an order that doesn't have a return yet). The same fields as the Orders/Show modal. (Pre-existing — left as-is.) |

### Don't

- Don't show the same "Manage return" button as the Show page. The Edit page already provides the hint with a link; a button would compete with Save and confuse.
- Don't allow the Edit page to mutate any return field. The Edit page is for order fields; return mutations belong on `/returns/{id}`.

---

## 4. `Returns/Index.jsx` — the queue

### Layout (top to bottom)

1. **Page header** — title `Returns`, dynamic subtitle reflecting the current view (`Active returns · 14 of 231 total` / `Resolved returns · 217 of 231 total` / `All returns · 231` / `Pending · 6 of 231 total`). Right-aligned `+ New return` button (`returns.create`).
2. **Search input** — `q` over order_number + customer_name. Submit on Enter.
3. **Primary tabs (queue mode)** — `Active | Resolved | All`, each with a count badge. The selected tab gets a dark fill.
4. **Per-status drill-down chips** — `Pending | Received | Inspected | Damaged`, then a visual gap, then the visually-subtler `Restocked | Closed` chips. Each chip shows its count. Each chip applies `?status=<Name>`.
5. **Helper notice (Active view only, when `counts.resolved > 0`)** — A slate panel reading *"Showing active returns only. N resolved (Restocked + Closed) are hidden. [View Resolved →]"*. Disappears on every non-Active view.
6. **Results table** — `Return / Order / Customer / Reason / Condition / Status / Refund / Inspector`. The `Return` column shows the RMA display reference `RET-000006` (Phase 2 — see §6.bis). Empty state on the Active view (when resolved > 0) reads: *"No active returns. N resolved are under Resolved."*
7. **Pagination** — standard `Pagination` component, 30 per page.

### Counts must be honest

The counts shown next to the tabs and chips must represent the **whole dataset under the current `q` search**, not just the visible page. This is what makes the "but where is my Closed return?" question answer itself: *"You have 217 resolved — click Resolved to see them."*

`q` narrows the counts (a search for "ORD-123" filters every count to returns whose order matches), but the status filter does **not** — counts describe the whole queryable set.

### Don't

- Don't bury the "Showing active only" notice. It must be inline above the table, not in a tooltip.
- Don't change the empty-state copy on Active to *"No returns to show."* — that's the exact UX trap Phase 1 fixed.
- Don't add more queue modes than `Active / Resolved / All`. The three-tab model is ERP-standard; a fourth mode requires explicit rationale.
- Don't auto-navigate the user when a tab has zero records. Show the empty state with the resolved count, let the user choose.

---

## 5. `Returns/Show.jsx` — the management surface

### Cards / sections (top to bottom)

| Section | Contents | Notes |
|---|---|---|
| **Page header** | Return id, current `return_status` badge, `product_condition` badge. | If `order_context.mismatch` (the order has drifted from `Returned`/`Cancelled`), show a warning banner at the top. |
| **Linked order card** | Order number, customer name, customer phone, *Open order →* link. | Defends against the order/return-status mismatch case (e.g. order accidentally moved to Delivered after the return was opened). |
| **Items section** | Lines from `order.items`. Read-only on this page. | The inspection step uses these item quantities for the inventory movement. |
| **Inspection section** | Visible inspection form when `return_status` is `Pending` or `Received` AND `can('returns.inspect')`. Once inspected, becomes a read-only summary (condition / restockable / inspector / timestamp). | This is the gate from Active to Resolved-ish (`Restocked`/`Damaged`). |
| **Limited details edit** | Form for `refund_amount`, `shipping_loss_amount`, `notes`. Visible when `edit_context.can_edit` (i.e. `return_status !== 'Closed'`) AND the user has appropriate permission. | This is the form that mutates *intent*, not money. Copy must reflect that. |
| **Refund section** | List of `refunds` linked to this return, each with status (`requested`/`approved`/`rejected`/`paid`), amount, requester, approver, payer. *Request refund* CTA at top when `refund_context.can_request_refund` AND user has `refunds.create`. | The refund rows are not mutable from here; they have their own /refunds/ surface. This section is summary + bridge. |
| **Closure section** | *Close return* button when `return_status !== 'Closed'`. Optional closure note textarea. | Closure is intentional and reversible only by re-creating a new return (the system has no "re-open closed return" path — see Phase 3 for discussion). |
| **Activity / audit trail** *(future)* | A timeline of every state change with user + timestamp. | Today the audit_logs rows exist but there's no UI surface. A future enhancement could render them here. |

### Don't

- Don't fold the Refund section into the Limited Details Edit. They look similar (both have "amount") but are very different things — intent vs. commitment vs. cash.
- Don't show the inspection form for a closed return.
- Don't show the close button for a closed return.

---

## 5.bis. `Returns/Create.jsx` — the direct intake form (Phase 2)

The direct create flow is a **back-office correction tool**. Wherever the order's status is still mutable, operators should reach Returns through the atomic flow (`Orders → order page → Change status → Returned`). This page exists for legacy data and operator-recovery cases.

| Element | Behaviour |
|---|---|
| **"Preferred path" notice (slate, top of form)** | Always shown. Reads: *"Preferred path: open Orders → the order → Change status → Returned. That flow updates the order status, creates the return, and adjusts inventory atomically. Use this form only for back-office corrections."* |
| **Already-returned notice (amber)** | Shown when `?order_id=X` refers to an order that already has a return. Reads: *"Order {N} already has a return record and cannot be returned again."* Includes a *"Open existing return →"* link sourced from the controller-supplied `existing_return_id` prop. |
| **Field: Reason** | Required. Helper: *"A reason is required for every return."* |
| **Field: Product condition (provisional)** | Optional; defaults to `Unknown`. Helper: *"Use Unknown if the goods haven't arrived yet — the inspector locks the final condition later."* |
| **Field: Refund amount** | Optional. Helper: *"Optional. Records the **intended** refund — no money moves until a separate Request refund action."* Pinned at the intake layer so the operator can't mistake the field for a payment. |
| **Field: Shipping loss** | Optional. Helper: *"Optional. Records absorbed shipping cost — no finance row is posted."* |
| **Field: Notes (internal)** | Optional. Helper: *"Operations-internal notes for warehouse and order agents — not customer-facing."* |

### Don't

- Don't auto-redirect the operator from `/returns/create` when an `order_id` is supplied with an existing return. The amber notice + the link is the right UX — the operator may still want to land on the form to see the recent-orders fallback.
- Don't add a customer-facing note field here. The current `notes` column is single-purpose (internal); splitting it is a future migration phase and needs an operational request.

---

## 6. Status badges — color conventions

`StatusBadge` already exists in `resources/js/Components/StatusBadge.jsx`. For Returns, the convention should be:

### 6.bis. Return display reference (Phase 2 — no migration)

Every return carries a derived `display_reference` value computed by the `OrderReturn` model's `getDisplayReferenceAttribute()` accessor:

```
id=6   → "RET-000006"
id=42  → "RET-000042"
```

The accessor is registered in the model's `$appends` array, so every serialised payload (Inertia props, JSON responses, audit log scaffolds) contains the field. The frontend reads `ret.display_reference` directly — there is no client-side fallback formatter to drift from the backend rule.

**Where it appears:**

- `Returns/Index.jsx` — the *Return* column on the queue table (`RET-000006` instead of `#6`).
- `Returns/Show.jsx` — the page header (`RET-000006`) and `<Head title>`.
- `Orders/Show.jsx` — the amber return-context banner and the *Manage return* button's `title` tooltip both render the formatted reference.

**Where it intentionally does NOT appear:**

- Conversational copy ("Refund #5 for return #6") keeps the short `#id` form because it's a sentence fragment, not an identifier label.
- `Orders/Edit.jsx` — the helper hint uses prose ("open the return"), so the formatted reference isn't needed.

**Future direction.** If an external integration ever requires a real RMA number, add a nullable `rma_number` column in a dedicated migration phase. At that point swap the accessor to prefer the column when set, falling back to the padded id:

```php
public function getDisplayReferenceAttribute(): string
{
    return $this->attributes['rma_number']
        ?? 'RET-' . str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
}
```

The frontend continues to read `display_reference` unchanged.

---

| Status | Recommended color | Rationale |
|---|---|---|
| `Pending` | amber | needs work |
| `Received` | blue | progress, no decision yet |
| `Inspected` | indigo | nearly decided |
| `Restocked` | emerald | good outcome, money/stock recovered |
| `Damaged` | rose | bad outcome, money/stock lost |
| `Closed` | slate / gray | finalised, no further action |

For `product_condition`:

| Condition | Recommended color |
|---|---|
| `Good` | emerald |
| `Damaged` | rose |
| `Missing Parts` | amber |
| `Unknown` | slate |

Today the StatusBadge component picks its color based on the value text; adding any new status MUST register a color so the badge doesn't default to neutral.

---

## 7. Empty states — the dropping return problem

Phase 1's biggest UX risk: when the operator just closed a return and lands on `/returns`, the just-closed item is now invisible. The empty-state copy *must* explain where it went.

| View | Empty-state copy |
|---|---|
| Active view, `counts.resolved > 0` | *"No active returns. N resolved are under Resolved."* — with the count hyperlinked to the Resolved tab. |
| Active view, `counts.resolved == 0` | *"No returns yet."* (Clean install / new system.) |
| Resolved view | *"No resolved returns yet."* |
| All view | *"No returns yet."* |
| Specific-status view (e.g. `Closed`) | *"No returns in status 'Closed'."* |
| Any view + search `q` set | *"No results for "{q}"."* with a "Clear search" link. |

---

## 8. Error states — what to show

| Error path | Copy / behaviour |
|---|---|
| `OrderStatusFlowService` throws "already has a return record" | Inertia flash error: *"Order {order_number} already has a return record."* with a link to the existing return. |
| `OrderStatusFlowService` throws "return reason required" | Inline validation error on the `return.return_reason_id` field in the modal / edit form. |
| `ReturnService::updateDetails` throws "refund_amount below active refunds" | Inline error under the `refund_amount` input. The number context (current active refund total) must be shown. |
| `ReturnService::inspect` throws "already inspected" | Inline error above the inspection form. Recommend an instruction: *"This return has already been inspected. Open a new return record if you need to record a new verdict."* |
| `ReturnService::close` on already-closed | Should never happen (button is hidden); if it does, flash *"Return is already closed."* and re-render. |
| `FiscalYearLock` 403 on the atomic Returned transition | Inertia error modal — show the message verbatim: *"Fiscal year {name} is closed. Edits require an approval-override."* |
| Permission 403 (any returns.* missing) | Standard Inertia error modal *"You do not have permission for this action."* |

---

## 9. Accessibility & keyboard

- All tab buttons on `/returns` are real `<button>` elements (not divs) with hover/focus rings via Tailwind's `focus-visible:` utilities.
- The amber banner on `Orders/Show` MUST have visible text contrast in the `text-amber-800 on bg-amber-50` palette (it does).
- The "Open return →" link in the banner is keyboard-tabbable.
- The inspection form's `restockable` checkbox MUST have a proper label (it does).
- The page header `subtitleFor()` text is dynamic — screen readers will pick it up via the heading region; no extra `aria-live` required.

---

## 10. What NOT to do — anti-patterns

| Anti-pattern | Why it's wrong |
|---|---|
| Combining the "Manage return" button and the "Edit order" button into a single dropdown menu | Two distinct surfaces. A dropdown hides the difference and the operator picks the wrong one. |
| Hiding the count badges on the tabs | The counts are the entire UX answer to "where did my return go?" — removing them re-creates the original bug. |
| Auto-closing a return when its refund is paid | Couples two lifecycles that are intentionally independent. Operations decides when to close; finance decides when to pay. |
| Removing the *Returned* status from the dropdown when `has_return` BUT not surfacing a hint | The current code does both — it removes the option AND shows a hint explaining why. Removing one without the other re-creates the silent-failure UX. |
| Re-rendering the whole page on a tab click | Use Inertia partial navigation (`router.get(... preserveState: true, replace: true)`) so the search input and scroll position aren't lost. The current Phase 1 implementation does this. |
| Putting Refund action buttons on the Returns Index table rows | Refund actions live on `/refunds/` and `/returns/{id}`. The Index is a queue, not a workbench. |
