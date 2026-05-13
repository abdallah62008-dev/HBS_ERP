# Performance Phase 4 — Frontend UX Optimization

> **Status:** Optional. Visible improvements regardless of backend performance.
> Phase 1 + Phase 2 fix the underlying speed. Phase 4 fixes the *perceived* speed and is independently shippable in small commits.

---

## Loading skeletons on Dashboard cards

When the Dashboard loads, the cards currently appear empty until all metrics resolve. A skeleton placeholder (the standard gray pulsing rectangle pattern Inertia + Tailwind support natively) improves perceived performance even before Phase 2 query batching lands.

**Where to add:**

- `resources/js/Pages/Dashboard.jsx` — per card, render a small `<Skeleton />` when its data prop is `undefined`.

**Where NOT to add:**

- Forms (`Orders/Create`, `Orders/Edit`) — they should render immediately with empty state, not skeletons.

---

## Typeahead dropdown for product search

After Phase 1's server-side product search endpoint lands, the Create page's results table is a natural fit for a typeahead component:

- Search input → 200 ms debounce → fetch endpoint → render top 25 results.
- Click a result → add to cart, clear search.
- Keyboard navigation: ↓↑ to highlight, Enter to add.

The existing client-side `useMemo` filter goes away (already only filters the 25-row visible set).

---

## Lazy-load optional panels

Pages with multiple cards but only one primary action benefit from lazy-loading the secondary cards after first paint:

- `Returns/Show.jsx` — lazy-load the Phase 5C linked-refunds list after the Order Summary + Return Summary cards render. For most returns this list is empty; the user rarely needs it on first paint.
- `Orders/Show.jsx` — the customer-detail card is non-blocking; can render after the items + totals.

Implementation pattern: `useEffect` + `useState(null)` for the data, render `null` until the state is hydrated.

---

## Typeahead for marketer + customer pickers

After Phase 1's marketers prop gating:

- For privileged users with a small marketers list (<50): keep the native `<select>`.
- For privileged users with a larger list (>50) or any future customer picker: replace with a searchable combobox component (headless UI + manual implementation).

Same pattern as the product typeahead above.

---

## Avoid huge native `<select>` dropdowns

Native `<select>` becomes unusable past ~200 options on most browsers. Search the codebase for `<select>` populated by a prop that could grow unbounded:

- `Orders/Create.jsx` marketer dropdown — handled by Phase 1 gating + this typeahead.
- `Customers/*.jsx` location pickers — already use a custom `LocationSelect` component.
- Any future "pick a user / supplier / SKU" dropdown — should default to typeahead from day one.

---

## Do not virtualize small paginated tables

`react-virtualized` / `react-window` are powerful but heavy. The current paginated tables (20 or 30 rows visible) do not benefit. **Add virtualization only when a table grows past 200 rows on screen**, which today only happens on Cashbox Statement (50 rows — still fine without virtualization).

---

## Keep forms simple

- No animations on every keystroke.
- No heavy validation that runs on every render — defer to `onBlur` or submit.
- `useMemo`/`useCallback` dependency lists: audit once per page to confirm they aren't recreating arrays on every render.
- Avoid `JSON.stringify(...)` inside dependency arrays — recompute the relevant primitive instead.

---

## Avoid expensive calculations on every render

The Orders/Create marketer profit preview block currently re-runs its fetch on every keystroke. The debounce is in place — verify it's actually firing (`setTimeout` cleared correctly on re-renders).

The Refunds/Index Pay modal recomputes `cashbox.balance < refund.amount` for the "insufficient balance" warning on every render. That's fine — it's an O(1) comparison on a small object.

---

## Use debounce for search/preview calls

- Product search: 200 ms (Phase 1).
- Marketer profit preview: already debounced; current value should be 300–500 ms.
- Any future autocomplete: 200–300 ms is the sweet spot.

---

## Do not weaken pagination

Replacing pagination with infinite scroll on Orders Index / Refunds Index / etc. is a **UX decision, not a performance fix**. Infinite scroll often amplifies load if not carefully designed (each scroll fetch is a new request). Stay paginated unless a UX redesign explicitly chooses infinite scroll.

---

## Suggested order

1. Dashboard skeletons (visible win, ~1 day).
2. Product search typeahead component (depends on Phase 1 backend; ~2 days).
3. Returns/Show lazy refunds list (~half day).
4. Marketer/customer typeahead pickers (~1 day, only if list size warrants).

Skip the rest unless production telemetry identifies a specific UX bottleneck.

---

## Constraints (do not break)

- No removal of pagination.
- No change to the cost/profit visibility gate.
- No change to form submission contracts.
- No accessibility regressions — skeletons must include `aria-busy="true"` or equivalent.
- No new heavy dependencies. Existing Vite bundle should stay under the current size.
