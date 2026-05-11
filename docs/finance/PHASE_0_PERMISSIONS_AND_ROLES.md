# Phase 0 — Permissions and Roles

> **Companion to:** [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
> **Purpose:** New permission slugs introduced by the finance roadmap, and the recommended role grants.

This document **does not** modify `database/seeders/PermissionsSeeder.php` or `database/seeders/RolesSeeder.php`. Each slug is added in its phase's PR, alongside the corresponding feature code. The role matrix below is the authoritative target — phases should not invent new grants on the fly.

---

## 1. Confirmed: the Accountant role already exists

The `accountant` role is present in `database/seeders/RolesSeeder.php`. The finance roadmap **extends** the accountant role; it does not create a new one. No new role is proposed.

> If for any reason this is no longer true at implementation time, the implementer should re-confirm before granting cashbox / refund permissions to the accountant.

---

## 2. Proposed permission slugs

Slugs follow the existing catalogue convention (`module.action`, lower_snake_case for actions). They are added to `PermissionsSeeder::catalogue()` in the phase that uses them.

### 2.1 Cashboxes (Phase 1)
| Slug | Description |
|---|---|
| `cashboxes.view` | List + view balance + view statement |
| `cashboxes.create` | Create new cashbox |
| `cashboxes.edit` | Edit name, type (when no transactions), is_active flag |
| `cashboxes.deactivate` | Set `is_active = false` |
| `cashbox_transactions.view` | View the statement (the transaction list) |
| `cashbox_transactions.create` | Free-form manual adjustment entry (audit-logged) |
| `cashbox_transfers.create` | Move money between cashboxes (Phase 2) |

> Deliberately **no** `cashboxes.delete` or `cashbox_transactions.delete` slugs — these operations do not exist in the design.

### 2.2 Payment Methods (Phase 2)
| Slug | Description |
|---|---|
| `payment_methods.view` | List payment methods |
| `payment_methods.create` | Add a new method (rare; admins only) |
| `payment_methods.edit` | Edit name / default cashbox |
| `payment_methods.deactivate` | Set `is_active = false` |

### 2.3 Refunds (Phase 5)
| Slug | Description |
|---|---|
| `refunds.view` | View refunds list / detail |
| `refunds.create` | Create a refund request (status = Requested) |
| `refunds.approve` | Move Requested → Approved |
| `refunds.pay` | Move Approved → Paid (writes the cashbox outflow) |
| `refunds.reject` | Move Requested or Approved → Rejected |

> Each transition is its own permission for separation of duties.

### 2.4 Collections (Phase 3)
| Slug | Description |
|---|---|
| `collections.assign_cashbox` | Pick the cashbox + payment method on an existing collection |
| `collections.reconcile_settlement` | Run the bulk "Reconcile courier settlement" action |

> Existing slugs `collections.view`, `collections.update`, `collections.reconcile` are unchanged. The new slugs are finer-grained complements.

### 2.5 Expenses (Phase 4)
| Slug | Description |
|---|---|
| `expenses.assign_cashbox` | Pick the cashbox + payment method on a new or existing expense |

> Existing slugs `expenses.view`, `expenses.create`, `expenses.edit`, `expenses.delete`, `expenses.export` are unchanged.

### 2.6 Marketers (Phase 6)
| Slug | Description |
|---|---|
| `marketers.payout` | Execute a marketer payout (writes cashbox outflow + marketer ledger row) |

> Today, `marketers.wallet` is used as the de-facto gate for payouts via the wallet page. Phase 6 promotes the payout action to its own slug so payout authority is separable from wallet visibility.

### 2.7 Reports (Phase 7)
| Slug | Description |
|---|---|
| `finance.reports` | Access cashbox balances, statements, refund / expense / payout reports, finance dashboard |

> Existing `reports.profit`, `reports.cash_flow`, etc. remain. `finance.reports` is the umbrella slug for finance-specific reports.

### 2.8 Order Price Override (Phase 9)
| Slug | Description |
|---|---|
| `orders.override_price` | Override a line item's `unit_price` after order creation |

---

## 3. Recommended role matrix

`Y` = granted. `—` = not granted. The matrix shows the **end state** after Phase 9. Earlier phases grant only the slugs that exist by that phase.

### 3.1 Cashbox slugs
| Role | view | create | edit | deactivate | tx.view | tx.create | transfers.create |
|---|---|---|---|---|---|---|---|
| Super Admin | Y | Y | Y | Y | Y | Y | Y |
| Admin | Y | Y | Y | Y | Y | Y | Y |
| **Accountant** | Y | Y | Y | — | Y | Y | Y |
| Manager | Y | — | — | — | Y | — | — |
| Order Agent | — | — | — | — | — | — | — |
| Shipping Agent | Y (courier cashboxes only — UI filter) | — | — | — | Y (same filter) | — | — |
| Warehouse Agent | — | — | — | — | — | — | — |
| Marketer | — | — | — | — | — | — | — |
| Viewer | Y | — | — | — | Y | — | — |

### 3.2 Payment methods slugs
| Role | view | create | edit | deactivate |
|---|---|---|---|---|
| Super Admin | Y | Y | Y | Y |
| Admin | Y | Y | Y | Y |
| **Accountant** | Y | — | Y | — |
| Manager | Y | — | — | — |
| All others | — | — | — | — |

### 3.3 Refund slugs
| Role | view | create | approve | pay | reject |
|---|---|---|---|---|---|
| Super Admin | Y | Y | Y | Y | Y |
| Admin | Y | Y | Y | Y | Y |
| **Accountant** | Y | Y | — | Y | Y |
| Manager | Y | Y | Y | — | Y |
| Order Agent | Y | Y (request only) | — | — | — |
| Shipping Agent | — | — | — | — | — |
| Warehouse Agent | Y (read for return context) | — | — | — | — |
| Marketer | — | — | — | — | — |
| Viewer | Y | — | — | — | — |

> The split is deliberate: managers can approve but cannot pay; accountants can pay but should not approve their own (typically a manager-or-higher approves and accountant pays). Admins can do both; super-admin can do everything.

### 3.4 Collections / expenses assignment slugs
| Role | collections.assign_cashbox | collections.reconcile_settlement | expenses.assign_cashbox |
|---|---|---|---|
| Super Admin | Y | Y | Y |
| Admin | Y | Y | Y |
| **Accountant** | Y | Y | Y |
| Manager | Y | Y | Y |
| Shipping Agent | — | Y (reconcile only) | — |
| All others | — | — | Inherits expenses.create |

### 3.5 Marketer payout
| Role | marketers.payout |
|---|---|
| Super Admin | Y |
| Admin | Y |
| **Accountant** | Y |
| Manager | — |
| All others | — |

### 3.6 Finance reports
| Role | finance.reports |
|---|---|
| Super Admin | Y |
| Admin | Y |
| **Accountant** | Y |
| Manager | Y |
| Order Agent | — |
| Shipping Agent | — |
| Warehouse Agent | — |
| Marketer | — |
| Viewer | Y |

### 3.7 Order price override
| Role | orders.override_price |
|---|---|
| Super Admin | Y |
| Admin | Y |
| **Accountant** | — |
| Manager | Y |
| Order Agent | — |
| All others | — |

> Override is a sales decision, not an accounting decision. Accountants do not get this slug.

---

## 4. Design rules followed by the matrix

| Rule | How it is reflected |
|---|---|
| **Separation of duties for money outflow** | Refund request, approval, and payment are three different slugs. Marketer payout is gated separately from marketer wallet visibility. |
| **No mixing of execution with view-only roles** | The Viewer role is granted view slugs only — never create / approve / pay / deactivate. |
| **Accountant is the financial workhorse** | Accountant has the broadest non-admin grants on cashbox transactions, settlement reconciliation, and payouts — but does not approve refunds and does not override prices. |
| **Shipping Agent sees only courier-related cashboxes** | The slug grants visibility; the UI must additionally filter to cashboxes of `type='courier_cod'`. Backend should also enforce this filter (defense in depth). |
| **Marketers see none of this** | Marketer role is intentionally locked out of every finance slug. Their finance view is the marketer wallet page only. |
| **Override is sales authority, not finance** | `orders.override_price` is granted to Manager but not to Accountant. |

---

## 5. Confirmation and verification at implementation time

Each implementation phase must:
1. Confirm the slugs above do not yet exist in `PermissionsSeeder::catalogue()`.
2. Add only the slugs scoped to the phase.
3. Grant the slugs in `RolesSeeder::roleDefinitions()` according to this matrix.
4. Add a feature test asserting that:
   - A user with the slug can call the protected endpoint and gets `2xx`.
   - A user without the slug gets `403` and the affected props are absent from the Inertia payload.

---

## Cross-references

- Module map and principles → [PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md](PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md)
- Phased delivery plan → [PHASE_0_FINANCE_ROADMAP.md](PHASE_0_FINANCE_ROADMAP.md)
- Business rules → [PHASE_0_FINANCIAL_BUSINESS_RULES.md](PHASE_0_FINANCIAL_BUSINESS_RULES.md)
- Schema for tables these slugs guard → [PHASE_0_DATABASE_DESIGN_DRAFT.md](PHASE_0_DATABASE_DESIGN_DRAFT.md)
- Risk and audit context → [PHASE_0_RISK_CONTROLS_AND_AUDIT.md](PHASE_0_RISK_CONTROLS_AND_AUDIT.md)
