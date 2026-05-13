# Performance — Manual QA Checklist

> Use this checklist to validate each Performance Phase against real user flows. Execute the relevant journeys after the phase's commit lands on a staging copy of production data.
>
> Phase 1 covers journeys 1–8. Phase 2 covers journeys 9–11. Phase 4 covers journey 14.

---

## How to use

1. Set up a staging copy of production data so the catalogue / cashbox / order volume is realistic.
2. Open the browser DevTools Network tab BEFORE navigating to each page. Note the initial page payload size and Time-To-Interactive.
3. Walk through each journey below in order.
4. Fill the Pass/Fail column. If Fail, file a bug with screenshots + the Network-tab waterfall.
5. When all relevant journeys are Pass, the phase is QA-signed.

Legend for Pass/Fail:
- ✅ Pass
- ❌ Fail (file bug)
- ⚪ Not yet executed
- ⏭️ Skipped (with reason in Notes)

---

## Journeys

| # | Scenario | User Role | Steps | Expected Result | Pass/Fail | Notes |
|---:|---|---|---|---|:-:|---|
| 1 | Open `/orders/create` with large catalogue | Order Agent (no `orders.view_profit`) | Navigate to `/orders/create`. Watch the Network tab. | Initial page Inertia JSON < 30 KB. Time-to-interactive < 1.5 s on a 3G throttle. No products list in props. | ⚪ | |
| 2 | Search product by SKU | Order Agent | Type a known SKU prefix in the search box. | Top 25 matching SKUs appear within 300 ms. Search results table populates without page reload. | ⚪ | |
| 3 | Search product by Arabic / English name | Order Agent | Type an Arabic substring of a product name, then an English substring. | Both queries return correct results in the right language. UTF-8 round-trip is correct. | ⚪ | |
| 4 | Filter product by category | Order Agent | Pick a category from the category dropdown. | Only that category's products appear in search results (still capped at 25). | ⚪ | |
| 5 | Add multiple products | Order Agent | From the search results, add 3 different products to the cart. Then search for and add a 4th. | All 4 items appear in the cart in the order added. The cart is NOT cleared when the search query changes. | ⚪ | |
| 6 | Save the order | Order Agent | Fill customer details, click Save. | Order is created with all 4 items. Server-side totals are correct. Redirect to the new order's show page. | ⚪ | |
| 7 | Confirm non-privileged user does NOT receive cost/profit fields | Order Agent | Open DevTools → Network → `/orders/create` request → Preview tab. Inspect each product object in the response. | No `cost_price`, `marketer_trade_price`, `marketer_profit`, `net_profit`, or similar keys appear anywhere. The `can_view_profit` prop is `false`. | ⚪ | |
| 8 | Confirm Super Admin profit preview still works | Super Admin | Open `/orders/create`. Pick a marketer from the "On behalf of" dropdown. Add items. | Marketer profit preview block renders. Per-line cost / shipping / profit values appear. `can_view_profit` is `true`. | ⚪ | |
| 9 | Open `/finance/reports` (Overview) | Accountant | Navigate to `/finance/reports`. Watch the Laravel query log. | Page loads in < 800 ms. The total-balance card displays the correct sum. After Phase 2: only one `SUM(amount) GROUP BY cashbox_id` query fires (not N per cashbox). | ⚪ | |
| 10 | Open `/finance/reports/cashboxes` | Accountant | Navigate. Watch the per-row balances. | Each row's balance is correct. After Phase 2: a single grouped query instead of one per row. | ⚪ | |
| 11 | Open Dashboard | Admin | Navigate to `/dashboard`. Watch the query log. | All cards render with correct numbers. After Phase 2: ~5–8 queries total instead of ~17. Status counts come from one grouped query. | ⚪ | |
| 12 | Open Return Show page | Manager | Navigate to a return with a few linked refunds. | Page loads cleanly. Order Summary card displays the linked order status. No mismatch warning when order is in `Returned`. Refund list renders. | ⚪ | |
| 13 | Open Order Edit page | Manager | Navigate to an Order Edit page. | Page loads cleanly. Status dropdown filters correctly. Selecting `Returned` (if eligible) reveals the inline Return Details panel. | ⚪ | |
| 14 | Test on slow connection / browser throttling | Order Agent | DevTools → Network → throttle to "Slow 3G". Reload `/orders/create`. | Initial page renders within 3 s. Search debounce feels acceptable (200 ms). No infinite spinners. | ⚪ | |

---

## Phase mapping

| Phase | Journeys to run |
|---|---|
| Phase 1 — `Orders/Create` payload reduction | 1, 2, 3, 4, 5, 6, 7, 8, 14 |
| Phase 2 — Query optimization | 9, 10, 11 |
| Phase 4 — Frontend UX | 12, 13, 14 (re-run) |

Phase 3 (Index review) has no manual journeys — it's evidence-driven and not in scope yet.

---

## Sign-off

| Journey | Pass/Fail | Tested by | Date | Bugs filed |
|---|:-:|---|---|---|
| 1 — `/orders/create` payload | ⚪ | | | |
| 2 — Product search by SKU | ⚪ | | | |
| 3 — Product search by name | ⚪ | | | |
| 4 — Product search by category | ⚪ | | | |
| 5 — Add multiple products | ⚪ | | | |
| 6 — Save order | ⚪ | | | |
| 7 — Non-privileged user profit visibility | ⚪ | | | |
| 8 — Super Admin profit preview | ⚪ | | | |
| 9 — `/finance/reports` overview | ⚪ | | | |
| 10 — `/finance/reports/cashboxes` | ⚪ | | | |
| 11 — Dashboard | ⚪ | | | |
| 12 — Return Show | ⚪ | | | |
| 13 — Order Edit | ⚪ | | | |
| 14 — Slow connection | ⚪ | | | |

**Overall status:**

- [ ] Phase 1 journeys all passed → safe to move to Phase 2.
- [ ] Phase 2 journeys all passed → safe to consider Phase 4.
- [ ] Phase 4 journeys all passed → performance work signed off.

---

## Notes for QA testers

- **Take screenshots of the Network tab waterfall** at every step. They're the only objective measurement of "is this faster?"
- **Always compare against a baseline.** Before any Phase 1 work, run journey 1 on the current code, note the payload size + TTI. After Phase 1 work, re-run and compare.
- **Test on the user's actual hardware.** If operators work from a slow tablet, test on a slow tablet. Devtools throttling is a useful proxy but not a replacement.
- **Don't conclude "Phase 2 didn't help" without checking the query log.** A faster page could be cache, a slower page could be network jitter. The query log is the ground truth.
- **Test the regression cases too**: cost/profit visibility (journey 7), order creation success (journey 6), Return Management flow (journey 12, 13). Performance optimizations must not break the data-integrity guarantees those flows depend on.

---

## Known constraints to verify under QA

These must remain true after every performance phase:

- [ ] Non-privileged users still cannot see cost/profit anywhere (commit `ea3e6e5`).
- [ ] Closed finance periods still block writes inside the range (Phase 5F).
- [ ] Order status transition to Returned still creates a linked OrderReturn atomically (Phase 5G).
- [ ] Refund lifecycle still works end-to-end (Phases 5A → 5C).
- [ ] Marketer payout lifecycle still works (Phase 5D).
- [ ] Cashbox ledger is still append-only — `balance()` always computed from `cashbox_transactions` (Phase 0 Finance docs).
- [ ] No pagination removed.
- [ ] No `migrate:fresh` was run during QA setup.
