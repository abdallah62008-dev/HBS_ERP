# Governance, Permissions & Approvals

> Status: **DESIGN ONLY.**

---

## 1. Scope

This document specifies which Orders & Products actions are **sensitive** — i.e. those that affect financial truth, historical reports, or customer trust — and how each is gated:

- **Permission slug** required (RBAC middleware)
- **Approval workflow** required (Phase 8 `ApprovalRequest`)
- **Audit log** mandatory
- **Block** vs **warn** vs **request-approval**

## 2. Sensitivity matrix

| Action | Permission slug | Approval needed? | Audit log | Severity |
|---|---|---|---|---|
| Edit product **cost price** | `products.edit_cost` | No (gate via slug only) | Yes | High |
| Edit product **selling price** | `products.edit_price` | No | Yes (existing `product_price_history`) | Medium |
| Edit product **min selling price** | `products.edit_price` | No | Yes | High |
| Edit product **VAT rate / tax_enabled / vat_inclusive_flag** | `products.edit_vat` | No | Yes | High |
| Edit product **brand** | `products.edit_brand` | No | Yes | Low |
| Edit product **channel SKU** | `products.edit_channel_sku` | No | Yes | Low |
| Edit product **country price** (Phase 4) | `products.edit_country_price` | No | Yes | High |
| **Sell below min selling price** on an order | `orders.override_price` (line-level) | **Yes** (Phase 8) — Approval Request type "Approve Below-Min Selling" | Yes | High |
| **High discount** on an order (> threshold %) | `orders.create` (existing) | **Yes** — Approval Request "Approve High Discount" | Yes | Medium |
| **Edit confirmed order financial fields** (after order is Confirmed/Packed/Shipped) | `orders.edit_financial` | **Yes** — existing "Edit Confirmed Order Price" approval (Phase 8 partial) | Yes | High |
| **Cancel after shipment** | `orders.cancel_after_ship` | **Yes** — Approval Request "Cancel After Shipment" | Yes | Critical |
| **Manual profit override** (force a different marketer_profit value) | `marketers.override_profit` | **Yes** — Approval Request "Manual Profit Override" | Yes | High |
| **Soft-delete order** | Implicit super-admin (Phase 5.6) | No (super-admin only) | Yes | Critical |
| **Hard-delete order** | None — blocked | n/a | n/a | Forbidden |
| **Edit order item snapshot** | `orders.edit_financial` | **Yes** | Yes | Very High |
| **Refund** | `payments.approve_refund` | Already gated by `refunds` approval flow (Phase 5A) | Yes | High |
| **Year-end close** | `year_end.manage` (existing) | Existing: typed `CLOSE YYYY` + backup ≤ 24h | Yes | Critical |

## 3. Recommended permission slugs to add

| Slug | Description | Granted by default to |
|---|---|---|
| `products.edit_cost` | Edit `products.cost_price` | Admin, Super Admin |
| `products.edit_price` | Edit `selling_price`, `marketer_trade_price`, `minimum_selling_price` | Manager+, Admin |
| `products.edit_vat` | Edit `vat_rate`, `tax_enabled`, `vat_inclusive_flag` | Admin, Super Admin |
| `products.edit_brand` | Edit brand FK | Manager+ |
| `products.edit_channel_sku` | Edit `product_channel_skus` rows | Manager+ |
| `products.edit_country_price` | Phase 4 — edit per-country prices | Admin, Super Admin |
| `orders.override_price` | Sell a line at a custom `unit_price` ≠ master, including below min (paired with approval) | Admin, Super Admin |
| `orders.below_min_approve` | Approve other users' below-min override requests | Admin, Super Admin |
| `orders.edit_financial` | Touch order financial fields after Confirmed | Admin, Super Admin |
| `orders.cancel_after_ship` | Cancel after Shipped (requires approval) | Admin, Super Admin |
| `marketers.override_profit` | Manual marketer profit override | Super Admin |
| `payments.record` | Record payments on orders | Manager+, Admin |
| `payments.mark_paid` | Move payment from Pending → Paid | Manager+, Admin |
| `payments.cancel` | Cancel a pending payment | Admin |
| `payments.approve_refund` | Approve refund request | Admin |

## 4. Approval workflow integration

The existing `ApprovalRequest` framework (Phase 8) supports polymorphic targets. Each new approval type registers a handler:

```php
// app/Services/ApprovalService::HANDLERS
'Approve Below-Min Selling' => fn ($approval) => app(OrderService::class)->applyBelowMinApproval($approval),
'Approve High Discount'     => fn ($approval) => app(OrderService::class)->applyDiscountApproval($approval),
'Edit Confirmed Order Price'=> /* already partial */,
'Cancel After Shipment'     => fn ($approval) => app(OrderService::class)->applyCancelAfterShip($approval),
'Manual Profit Override'    => fn ($approval) => app(MarketerWalletService::class)->applyProfitOverride($approval),
```

### Flow per approval type

1. User attempts the sensitive action → `ApprovalRequest` row created with status `Pending` + target (Order, Marketer, OrderItem).
2. Approver (different user, with the matching `*.*_approve` permission) reviews via `/approvals` page.
3. Approve → handler runs → sensitive state change applies → audit log row added.
4. Reject → ApprovalRequest moves to `Rejected` status; original action remains undone.

`Self-approval blocked` invariant: an `ApprovalRequest.requested_by != approved_by` check is enforced server-side.

## 5. Block vs warn vs request

| Severity | Behaviour |
|---|---|
| **Block** | Server rejects (422). User cannot proceed. |
| **Warn** | Server allows; UI shows a warning banner; no audit log unless the user dismisses+confirms. |
| **Request approval** | Server creates a `Pending` ApprovalRequest, the order/product stays in a pre-state pending approval. |

| Action | Default behaviour | When approval required |
|---|---|---|
| Sell below min | Block | Only with `orders.override_price` + reason; creates approval request that an approver clears in real time (no separate state — the order is *created* on approval) |
| High discount | Warn | When discount % > threshold → request |
| Negative marketer profit | Warn | Always warn; never block (legitimate pricing) |
| Missing cost | Warn | Always warn |
| Cancel after ship | Request approval | Always — no shortcut |
| Edit cost on a delivered order's product | Block at the product level | Approval needed via `products.edit_cost` only — does not retroactively affect old `order_items` (snapshot rule) |

## 6. Audit log requirements

Every action in §2 must:

1. Land in `audit_logs` with `action` matching the table (e.g. `orders.below_min_overridden`, `products.cost_edited`).
2. Include `old_values` + `new_values` (auto-redacted for sensitive keys per existing AuditLogService).
3. Reference `record_type` + `record_id`.
4. Include `user_id` of the actor.

Per-approval: when the approval is approved, an additional `audit_logs` row records `approvals.approved` with the approver's user_id.

## 7. UI surface (Phase 2 + Phase 3)

### Product Edit page
- Cost price field: greyed out unless user has `products.edit_cost`.
- VAT fields: greyed out unless user has `products.edit_vat`.
- Brand dropdown: greyed out unless `products.edit_brand`.
- Channel SKU tab: hidden unless `products.edit_channel_sku`.
- Each price change requires a `price_change_reason` (existing behaviour).

### Order Create / Edit
- If user lacks `orders.override_price`: the unit_price input is read-only.
- If user has `orders.override_price`: typing a price different from the master triggers an inline "Reason" textarea + warning. On submit, ApprovalRequest is created (high-severity cases) or the order is created (low-severity).
- Cancel button: visible only if order is pre-Shipped. After Shipped, only `orders.cancel_after_ship` users see a "Request cancellation" button that creates an ApprovalRequest.

### Approval Inbox
- `/approvals` lists all `Pending` approval requests visible to the current user.
- Each row: type, target, requested by, reason, request date, action buttons (Approve / Reject).

## 8. Year-end and fiscal-year interaction

- Closed fiscal years prevent editing orders within the closed period — `fiscal_year_lock` middleware (existing).
- An attempt to edit a closed-year order returns 422.
- Soft-delete on a closed-year order: blocked.
- Year-end close itself requires the existing double gate (typed `CLOSE YYYY` + backup ≤ 24h).

## 9. Do-now / do-later

### Phase O-1 — Should (paired with permission slug additions)
- Add the 8 new permission slugs.
- Update `PermissionsSeeder` + `RolesSeeder` with the new grants.
- Update UI permission gates on Product Edit + Order Create + Order Edit.

### Phase 8 — Should (approval handler completion)
- Register the 5 new ApprovalRequest handlers in `ApprovalService::HANDLERS`.
- Build the controllers + reason validation per handler.
- Add tests.

### Later
- Per-tier approval thresholds (e.g. "Manager can approve up to 20% discount; only Admin can approve > 20%").
- Approval reminders / SLA timers.
- Approval delegation ("Admin A is on leave, route Admin A's queue to Admin B").

## 10. Risks

| Risk | Mitigation |
|---|---|
| Adding permissions breaks existing users | New slugs are not auto-granted; Admin role gets them by default; other roles unchanged. Operators rerun `RolesSeeder` after release. |
| Approval queue overflows | Add stale-request alerts (Phase 7 notifications) when an approval sits Pending > 24h. |
| User tries to self-approve | Server enforces `approved_by != requested_by`. UI hides the Approve button on rows the user submitted. |
| Approval handlers diverge from the original action's logic | Each handler unit-tested; integration test re-applies the action via the approval path and asserts identical outcome. |
| Audit log gets noisy | The audit log already redacts secrets; new entries are scoped per action type, easily filtered in the Audit Logs page. |

## 11. References

- Existing: `app/Services/ApprovalService.php`, `app/Http/Controllers/ApprovalsController.php`
- [PRODUCT_PRICING_AND_VAT_GUIDELINES.md](./PRODUCT_PRICING_AND_VAT_GUIDELINES.md) (price edit gates)
- [MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md](./MULTI_PAYMENT_AND_COLLECTIONS_ROADMAP.md) (`payments.*` slugs)
- [MARKETER_PRICING_AND_PROFIT_ROADMAP.md](./MARKETER_PRICING_AND_PROFIT_ROADMAP.md) (`marketers.override_profit`)
- [ORDER_LIFECYCLE_AND_CREATE_UX.md](./ORDER_LIFECYCLE_AND_CREATE_UX.md) (where slugs surface in UI)
