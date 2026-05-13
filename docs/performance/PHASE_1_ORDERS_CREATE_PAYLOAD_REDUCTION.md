# Performance Phase 1 — Orders/Create Payload Reduction

> **Target page:** `/orders/create`.
> **Status:** Plan only. No code changes in this document.
> **Expected impact:** Inertia page payload drops from ~750 KB to <20 KB on a tenant with >1000 SKUs. Time-to-interactive on 3G drops by 5–10×.

---

## Problem

`OrdersController::create()` currently passes four large props to `Orders/Create.jsx`:

| Prop | Source | Size today |
|---|---|---|
| `products` | `productsForOrderEntry()` — `SELECT id, sku, barcode, name, selling_price, minimum_selling_price, tax_enabled, tax_rate, category_id, category_name, on_hand, reserved FROM products LEFT JOIN inventory_movements ... GROUP BY products.id` for **every active product** | ~150 bytes/row × N active products |
| `marketers` | `Marketer::query()->where('status','Active')->with('user','priceTier')->get()` then `.map()` — **every active marketer** | Typically <200 rows; unbounded |
| `categories` | `Category::query()->where('status','Active')->orderBy('name')->get(['id','name','parent_id'])` | Usually <50 rows; fine |
| `locations` | `CustomersController::locationTree()` — country/state/city tree | Unknown — needs measurement |

The biggest problem is `products`. On a tenant with 5,000 SKUs that's ~750 KB of JSON parsed by the browser before the React tree mounts.

The page renders a search box + scan input + maximum-25-row results table. The full catalogue is only ever used by a client-side `useMemo` filter; the user sees ≤25 rows at a time.

Shipping the full catalogue is wasted work.

---

## Proposed Solution

### Server-side product search endpoint

```
GET /api/products/search?q=<term>&category_id=<id>&limit=25
```

Use a **web route** (not `routes/api.php`) because the project pattern is web routes for everything Inertia-related. The route already exists implicitly through the existing `web` middleware group with CSRF + session auth, which matches how `/orders/marketer-profit-preview` works today.

**Suggested route definition:**

```php
Route::middleware('permission:orders.create')->get('/orders/products/search', [OrdersController::class, 'searchProducts'])
    ->name('orders.products.search');
```

**Suggested controller method:**

```php
public function searchProducts(Request $request): JsonResponse
{
    $q = trim((string) $request->query('q', ''));
    $categoryId = $request->query('category_id');
    $limit = min(25, max(1, (int) $request->query('limit', 25)));

    // Reuse productsForOrderEntry()'s base query but with WHERE + LIMIT
    // applied AFTER the GROUP BY so on_hand/reserved still aggregate
    // correctly. Returns the SAME 10-field shape so the frontend
    // result-row component does not need to change.
}
```

### Safe product fields (defence-in-depth)

The endpoint must return **only** fields that `productsForOrderEntry()` returns today. No new fields. This preserves the cost/profit visibility gate added in commit `ea3e6e5`.

**Allowed:**

```
id
sku
barcode
name
selling_price
minimum_selling_price
tax_enabled
tax_rate
category_id
category_name
available  (computed: on_hand − reserved)
```

**Must NOT be returned:**

```
cost_price
product_cost
purchase_price
marketer_trade_price
marketer_shipping_cost
marketer_vat_percent
net_profit
profit
margin
marketer_profit
```

A test (see `Tests Needed` below) must assert this contract.

---

## Frontend Plan

In `resources/js/Pages/Orders/Create.jsx`:

1. Change the initial `products` prop default to `[]` (already what comes back from `productsForOrderEntry()` when filtered).
2. Update the `OrdersController::create` to ship `products: []` (or to drop the prop entirely and have the JSX call the search endpoint on mount with `q=''`).
3. Add a `useEffect` that calls the new endpoint:
   - On mount: load the first 25 results (no filter), so the panel isn't empty.
   - On search-query change: debounce 200 ms, call the endpoint with `q` + `category_id`, set state.
   - On category change: same.
4. Show a loading indicator on the results table while fetching.
5. Show a clear "no results" state.
6. **Critical**: the selected items (left side of the page) must NOT depend on the search results being in memory. The current code stores selected items in `data.items` (an array of `{product_id, name, sku, quantity, unit_price, ...}` snapshots) independent of the search list. This is already correct — verify it stays that way.

### Backward compatibility

- The existing barcode scan handler currently looks up products in the full client-side list. After Phase 1 it must hit the search endpoint with `q=<barcode>` (or a separate `lookupByBarcode` endpoint). Test that scanning a known barcode still adds the product.
- The category filter currently filters the client-side list. After Phase 1 it must pass `category_id` to the search endpoint.

### What stays the same

- The order POST payload (`StoreOrderRequest` fields) is unchanged.
- The order item shape stored in `data.items` is unchanged.
- Item-level pricing rules + duplicate-warning + marketer-profit-preview behaviour are all unchanged.

---

## Marketers prop gating

After Phase 1, the `marketers` prop should also be reduced:

- For users **without** `orders.view_profit`: pass `marketers: []`. They can't see the marketer profit preview anyway (gated in commit `ea3e6e5`).
- For users **with** `orders.view_profit` and a large marketers list (>100): consider either a typeahead component or paginate. For now (typically <200), keep the existing payload but only for privileged users.

Keep this change small: a one-line conditional in `OrdersController::create()`.

---

## Tests Needed

Add a new file: `tests/Feature/Orders/ProductSearchEndpointTest.php`. Coverage:

| # | Test | Asserts |
|---|---|---|
| 1 | `endpoint_requires_authentication` | 401/redirect when not logged in. |
| 2 | `endpoint_requires_orders_create_permission` | 403 without the slug. |
| 3 | `endpoint_returns_max_25_results` | Seed 100 products, request `q=`, expect ≤25 rows. |
| 4 | `endpoint_filters_by_name` | `q=Widget` returns only matching SKUs. |
| 5 | `endpoint_filters_by_sku` | `q=WGT-001` returns the SKU match. |
| 6 | `endpoint_filters_by_category_id` | `category_id=X` returns only that category's products. |
| 7 | `endpoint_returns_only_safe_fields` | Response JSON contains NO `cost_price`, `marketer_trade_price`, `net_profit`, etc. (defence against accidental field leak). |
| 8 | `endpoint_returns_available_stock` | The `available` field reflects `on_hand − reserved`. |
| 9 | `endpoint_excludes_inactive_products` | Inactive product never appears. |
| 10 | `endpoint_excludes_deleted_products` | Soft-deleted product never appears. |
| 11 | `non_privileged_user_create_page_marketers_prop_is_empty` | The Inertia prop is `[]` when user lacks `orders.view_profit`. |
| 12 | `privileged_user_create_page_marketers_prop_is_populated` | The prop is populated when user has `orders.view_profit`. |
| 13 | `existing_orders_create_test_still_passes` | Regression: the Create page renders and order creation succeeds. |

The existing `OrderProfitVisibilityTest` must continue to pass. Specifically:

- `test_create_page_products_prop_does_not_expose_cost_price` should still pass — the new endpoint must also avoid leaking cost.

---

## Risks

| Risk | Mitigation |
|---|---|
| Selected products disappear from the cart after a new search | `data.items` is a separate state slice — already independent. Add a test that proves this. |
| Search endpoint leaks cost/profit fields | Test #7 enforces field whitelist. |
| Barcode scan path breaks | Update the scan handler to call the endpoint with `q=<scanned barcode>` first, then add the returned product to `data.items`. |
| Debounce too aggressive — keystrokes feel laggy | 200 ms is a good default; tune to 150 ms if perceived as slow. |
| Debounce too weak — hammer the server | 150 ms debounce + max 25 results + indexed SQL = no risk. |
| Adding `/orders/products/search` collides with route model binding on `/orders/{order}` | The route `/orders/products/search` is a static segment that resolves before `/orders/{order}` — but verify with `php artisan route:list` after wiring. |
| The endpoint adds new query patterns missing an index | The base query is identical to `productsForOrderEntry()`'s GROUP BY pattern; the WHERE filter on `products.status` is already indexed. No new index needed. |

---

## Done Definition

- `/orders/create` no longer ships the full product catalogue. Network tab shows initial page JSON < 30 KB (or whatever the catalogue-less size is).
- Order creation still works end-to-end for all roles (Order Agent, Manager, Accountant, Super Admin).
- All 13 new tests pass.
- All existing `OrderProfitVisibilityTest` tests pass.
- All existing `ReturnFromStatusChangeTest` and `ReturnManagementTest` tests pass.
- `php artisan test` shows zero regressions vs. the 327-test baseline (post-`ea3e6e5`).
- `npm run build` succeeds.

---

## Constraints (do not break)

- Cost/profit visibility gate from commit `ea3e6e5` stays intact.
- Order pricing formulas unchanged.
- Finance ledger principles unchanged.
- No migrations.
- No new permissions.
- No frontend hiding of required data — payload reduction is server-side.
- Pagination of other Orders pages unchanged.
