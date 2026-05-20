<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderRequest;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnReason;
use App\Services\AuditLogService;
use App\Services\DuplicateDetectionService;
use App\Services\OrderService;
use App\Services\OrderStatusFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class OrdersController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly DuplicateDetectionService $duplicateService,
        private readonly OrderStatusFlowService $statusFlow,
    ) {}

    /**
     * Sensitive cost/profit columns that must be hidden from users
     * without the `orders.view_profit` permission. Used by every
     * Inertia render path that serializes an Order or OrderItem.
     *
     * Hiding via `makeHidden` removes the fields from JSON output so
     * they don't ship to the browser in page props — defence-in-depth
     * on top of the React-side `can('orders.view_profit')` checks.
     */
    private const PROFIT_HIDDEN_ORDER_FIELDS = [
        'net_profit',
        'product_cost_total',
        'marketer_profit',
        'marketer_trade_total',
    ];

    private const PROFIT_HIDDEN_ORDER_ITEM_FIELDS = [
        'marketer_trade_price',
        'marketer_shipping_cost',
        'marketer_vat_percent',
    ];

    /**
     * Strip cost/profit fields from an Order (and its loaded items)
     * when the current user lacks `orders.view_profit`. No-op for
     * privileged users. Returns the same instance for chaining.
     */
    private function sanitizeProfitFor(?\App\Models\User $user, \App\Models\Order $order): \App\Models\Order
    {
        if ($user?->hasPermission('orders.view_profit')) {
            return $order;
        }
        $order->makeHidden(self::PROFIT_HIDDEN_ORDER_FIELDS);
        if ($order->relationLoaded('items')) {
            foreach ($order->items as $item) {
                $item->makeHidden(self::PROFIT_HIDDEN_ORDER_ITEM_FIELDS);
            }
        }
        return $order;
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        // C-1: `customer_id` joins the filter set. The original phone-
        // search via `q` stays — it's the ad-hoc fallback — but the
        // indexed `customer_id` filter is the reliable path the
        // Customer Show "View Orders" button uses.
        $filters = $request->only(['q', 'status', 'risk_level', 'shipping_status', 'customer_id']);

        $orders = Order::query()
            ->forCurrentMarketer()
            ->with('customer:id,name,primary_phone')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $term = trim($term);
                // Phase 5.4: support display-number search like
                // "ORD-2026-000123-AH" by splitting at the entry-code
                // suffix and matching order_number + entry_code together.
                $orderNumberPart = $term;
                $entryCodePart = null;
                if (preg_match('/^(.+-\d{4,}-\d+)-(.+)$/', $term, $matches)) {
                    $orderNumberPart = $matches[1];
                    $entryCodePart = $matches[2];
                }
                $q->where(function ($w) use ($term, $orderNumberPart, $entryCodePart) {
                    $w->where('order_number', 'like', "%{$term}%")
                        ->orWhere('external_order_reference', 'like', "%{$term}%")
                        ->orWhere('customer_name', 'like', "%{$term}%")
                        ->orWhere('customer_phone', 'like', "%{$term}%");
                    if ($entryCodePart !== null) {
                        $w->orWhere(function ($d) use ($orderNumberPart, $entryCodePart) {
                            $d->where('order_number', $orderNumberPart)
                                ->where('entry_code', $entryCodePart);
                        });
                    }
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->where('customer_risk_level', $v))
            ->when($filters['shipping_status'] ?? null, fn ($q, $v) => $q->where('shipping_status', $v))
            // C-1: customer_id filter. Cast defensively so a non-numeric
            // value (e.g. "abc" injected via URL) becomes 0 and produces
            // an empty result set instead of a SQL error.
            ->when((int) ($filters['customer_id'] ?? 0) > 0, fn ($q) => $q->where('customer_id', (int) $filters['customer_id']))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Strip cost/profit fields from each row when the user lacks
        // `orders.view_profit`. Done after pagination so the data layer
        // is unchanged but the Inertia JSON output is sanitized.
        $orders->getCollection()->transform(fn (Order $o) => $this->sanitizeProfitFor($user, $o));

        // C-1: when `customer_id` is set on the filter, resolve a slim
        // customer summary so the UI can render a "Filtered by
        // customer #X" pill without an extra round-trip. Null when the
        // id is missing / invalid / soft-deleted — the orders list
        // still renders (empty under that filter) but no pill.
        $filterCustomer = null;
        if ((int) ($filters['customer_id'] ?? 0) > 0) {
            $filterCustomer = \App\Models\Customer::query()
                ->whereNull('deleted_at')
                ->where('id', (int) $filters['customer_id'])
                ->first(['id', 'name', 'primary_phone']);
        }

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => Order::STATUSES,
            'can_view_profit' => (bool) $user?->hasPermission('orders.view_profit'),
            // C-1: pill payload. `null` when no customer filter is active.
            'filter_customer' => $filterCustomer ? [
                'id' => (int) $filterCustomer->id,
                'name' => $filterCustomer->name,
                'primary_phone' => $filterCustomer->primary_phone,
            ] : null,
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $canViewProfit = (bool) $user?->hasPermission('orders.view_profit');

        // C-1: optional "Add Order from Customer Show" prefill. When the
        // user lands here via `?customer_id=<id>` we load a slim
        // customer object (same shape as `duplicate_from.customer` so
        // Create.jsx's existing matchedCustomer flow handles both
        // paths). Fields are cost/profit-free by design.
        //
        // `duplicate_from` (O-1) takes precedence — if both query params
        // are present, the duplicate flow wins because it already
        // contains a (possibly different) customer reference.
        $prefillCustomer = null;
        $prefillCustomerId = (int) $request->query('customer_id', 0);

        // O-1: optional "Save & Duplicate" prefill. The previous Save call
        // redirected here with `?duplicate_from=<id>`; load a slim copy of
        // that order's customer + items so the form can hydrate without
        // re-fetching. Authorize through the same ownership gate that
        // protects Order Show so a marketer can't snoop on another
        // marketer's orders via the prefill route. Cost/profit fields are
        // never included in the prefill payload.
        $duplicateFrom = null;
        $sourceOrderId = (int) $request->query('duplicate_from', 0);
        if ($sourceOrderId > 0) {
            $source = Order::query()
                ->with([
                    'items:id,order_id,product_id,product_variant_id,quantity,unit_price,discount_amount',
                    // O-1: pull a slim customer summary so Create.jsx can
                    // render the "existing customer" green card without a
                    // follow-up `/customers/lookup` round-trip. Fields are
                    // the same shape the lookupByPhone endpoint returns.
                    'customer:id,name,primary_phone,secondary_phone,email,city,governorate,country,default_address,customer_type,risk_level,primary_phone_whatsapp',
                ])
                ->find($sourceOrderId);
            if ($source) {
                // Re-use existing ownership check — marketers can only
                // duplicate their own orders. authorizeOwnership aborts
                // 403; trap and silently skip the prefill so the page
                // still renders.
                try {
                    $this->authorizeOwnership($source);
                    $duplicateFrom = [
                        'source_order_id' => (int) $source->id,
                        'source_order_number' => $source->order_number,
                        'customer_id' => $source->customer_id,
                        // Slim customer object — matches what Create.jsx's
                        // matchedCustomer state expects so the existing
                        // green "existing customer" panel renders without
                        // an extra round-trip.
                        'customer' => $source->customer ? [
                            'id' => $source->customer->id,
                            'name' => $source->customer->name,
                            'primary_phone' => $source->customer->primary_phone,
                            'secondary_phone' => $source->customer->secondary_phone,
                            'email' => $source->customer->email,
                            'city' => $source->customer->city,
                            'governorate' => $source->customer->governorate,
                            'country' => $source->customer->country,
                            'default_address' => $source->customer->default_address,
                            'customer_type' => $source->customer->customer_type ?? null,
                            'risk_level' => $source->customer->risk_level ?? null,
                            'primary_phone_whatsapp' => (bool) ($source->customer->primary_phone_whatsapp ?? true),
                        ] : null,
                        'customer_address' => $source->customer_address,
                        'city' => $source->city,
                        'governorate' => $source->governorate,
                        'country' => $source->country,
                        'marketer_id' => $source->marketer_id,
                        'source' => $source->source,
                        'shipping_amount' => (float) $source->shipping_amount,
                        // Snapshot items minus their financial provenance.
                        // The new order is a fresh creation — we only seed
                        // product_id + qty + unit_price + discount.
                        'items' => $source->items->map(fn ($it) => [
                            'product_id' => (int) $it->product_id,
                            'product_variant_id' => $it->product_variant_id ? (int) $it->product_variant_id : null,
                            'quantity' => (int) $it->quantity,
                            'unit_price' => (float) $it->unit_price,
                            'discount_amount' => (float) $it->discount_amount,
                        ])->all(),
                    ];
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    // Don't bubble — just render the create page empty.
                    $duplicateFrom = null;
                }
            }
        }

        // C-1: resolve `?customer_id=` only when duplicate_from isn't
        // already setting the customer. Duplicate-from wins because its
        // payload contains items too — overriding it with a different
        // customer would silently break the operator's intent.
        //
        // C-5B Must-Fix M1: if the prefill customer has been merged
        // away, redirect the operator to the surviving customer's
        // Show page. Otherwise they'd silently create a fresh order
        // against a tombstone (which our M1 store-side guard would
        // then reject — surface the error early on the GET instead).
        if ($prefillCustomerId > 0 && $duplicateFrom === null) {
            $customerModel = \App\Models\Customer::query()
                ->whereNull('deleted_at')
                ->find($prefillCustomerId);
            if ($customerModel && $customerModel->merged_into_customer_id) {
                \App\Services\AuditLogService::log(
                    action: 'write_blocked_merged_source',
                    module: 'customers',
                    recordType: \App\Models\Customer::class,
                    recordId: $customerModel->id,
                    newValues: [
                        'merged_into_customer_id' => $customerModel->merged_into_customer_id,
                        'attempted_url' => $request->fullUrl(),
                        'method' => 'GET (orders.create prefill)',
                    ],
                );
                return redirect()
                    ->route('orders.create', ['customer_id' => $customerModel->merged_into_customer_id])
                    ->with('error', "Customer #{$customerModel->id} was merged into Customer #{$customerModel->merged_into_customer_id}. Continue on the surviving customer.");
            }
            if ($customerModel) {
                // Cost/profit fields are deliberately excluded — the
                // prefill payload is safe for any caller. Marketers
                // browsing other marketers' customers can still hit
                // this; the customer record itself isn't gated and the
                // operator still has to pick items + click Save before
                // anything materialises.
                $prefillCustomer = [
                    'id' => (int) $customerModel->id,
                    'name' => $customerModel->name,
                    'primary_phone' => $customerModel->primary_phone,
                    'secondary_phone' => $customerModel->secondary_phone,
                    'normalized_phone' => $customerModel->normalized_phone,
                    'country_code' => $customerModel->country_code,
                    'local_phone' => $customerModel->local_phone,
                    'email' => $customerModel->email,
                    'city' => $customerModel->city,
                    'governorate' => $customerModel->governorate,
                    'country' => $customerModel->country,
                    'default_address' => $customerModel->default_address,
                    'customer_type' => $customerModel->customer_type ?? null,
                    'risk_level' => $customerModel->risk_level ?? null,
                    'primary_phone_whatsapp' => (bool) ($customerModel->primary_phone_whatsapp ?? true),
                ];
            }
            // Invalid / soft-deleted id → silently leave prefill_customer
            // null. The page still renders; the operator searches by phone
            // as usual.
        }

        return Inertia::render('Orders/Create', [
            // Performance Phase 1: do NOT ship the full active product
            // catalogue. The page now calls `orders.products.search`
            // via debounced AJAX as the operator types. We seed with
            // the first 25 products (alphabetical) so the panel is not
            // empty on first paint — same data shape the search
            // endpoint returns.
            'products' => $this->productsForOrderEntry(['limit' => 25]),
            'categories' => Category::query()
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
            'locations' => CustomersController::locationTree(),
            'default_country_code' => \App\Services\SettingsService::get('default_country_code', 'EG'),
            // Phase 5.4: server-computed preview of the entry_code the
            // OrderService will stamp on the new order. Only valid for the
            // staff-creates-order path (no marketer_id selected); marketer-
            // created orders override with marketers.code at save time.
            'entry_code_preview' => $this->previewEntryCode($user),
            // Phase 5.9: marketer list for the optional "On behalf of"
            // picker — drives the marketer-profit preview.
            //
            // Performance Phase 1: only ship this list to users who can
            // actually USE the marketer profit preview (`orders.view_profit`).
            // Non-privileged users (Order Agents, Warehouse Agents,
            // Viewers, Marketers) never see the preview block (commit
            // ea3e6e5) so the list is dead weight in their payload.
            'marketers' => $canViewProfit
                ? \App\Models\Marketer::query()
                    ->where('status', 'Active')
                    ->with(['user:id,name', 'priceTier:id,code,name'])
                    ->orderBy('code')
                    ->get(['id', 'code', 'user_id', 'marketer_price_tier_id'])
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'code' => $m->code,
                        'name' => $m->user?->name,
                        'tier_name' => $m->priceTier?->name,
                    ])
                    ->all()
                : [],
            // Cost/profit-visibility gate. The Marketer profit preview
            // block on Create.jsx renders only when this is true; the
            // backing preview endpoint is also gated by the same slug.
            'can_view_profit' => $canViewProfit,

            // O-1: Save action variant gates + optional duplicate prefill.
            //  - `can_print_label` shows/hides the "Save & Print Label"
            //    button. Server enforces the same permission on redirect.
            //  - `duplicate_from` is null in the common case; populated
            //    only when the user landed via `?duplicate_from=<id>`.
            'can_print_label' => (bool) $user?->hasPermission('shipping.print_label'),
            'duplicate_from' => $duplicateFrom,
            // C-1: customer prefill from `?customer_id=<id>`. Null when
            // the param is absent / invalid / soft-deleted, or when
            // duplicate_from is active.
            'prefill_customer' => $prefillCustomer,
        ]);
    }

    /**
     * Frontend preview of the entry_code that the OrderService would assign
     * for a staff-created order. Mirrors OrderService::resolveEntryCode for
     * the "no marketer" case so the read-only field on the Create Order
     * page shows the right value before submit.
     */
    private function previewEntryCode($user): ?string
    {
        if (! $user) return null;
        if ($user->entry_code) {
            return mb_substr((string) $user->entry_code, 0, 16);
        }
        if ($user->name) {
            $words = preg_split('/\s+/u', trim($user->name));
            $initials = '';
            foreach ($words as $word) {
                $clean = preg_replace('/[^A-Za-z0-9]/u', '', $word);
                if ($clean !== '') {
                    $initials .= mb_strtoupper(mb_substr($clean, 0, 1));
                }
            }
            $initials = mb_substr($initials, 0, 16);
            return $initials !== '' ? $initials : null;
        }
        return null;
    }

    /**
     * Active products with derived available_stock (on-hand minus
     * outstanding reservations). Single grouped query — same SUM-CASE
     * pattern used by InventoryController and DashboardController.
     *
     * Performance Phase 1 (`docs/performance/PHASE_1_*.md`): now accepts
     * optional filters so the same query powers both the Create page's
     * initial empty payload AND the new search endpoint. Returns the
     * SAME 11-field shape so `Orders/Create.jsx` doesn't change.
     *
     * Cost/profit fields (cost_price, marketer_trade_price, etc.) are
     * NEVER returned by this method — the safe-fields contract from
     * commit ea3e6e5 stays intact for both call sites.
     *
     * @param  array{q?: ?string, category_id?: ?int, limit?: int}  $filters
     * @return \Illuminate\Support\Collection<int, array<string,mixed>>
     */
    private function productsForOrderEntry(array $filters = [])
    {
        $stockTypes = "'Purchase','Return To Stock','Opening Balance','Transfer In','Adjustment','Stock Count Correction','Ship','Return Damaged','Transfer Out'";

        $q = isset($filters['q']) && $filters['q'] !== null ? trim((string) $filters['q']) : null;
        $categoryId = isset($filters['category_id']) && $filters['category_id'] !== null
            ? (int) $filters['category_id']
            : null;
        // Limit is bounded server-side so callers can't ask for the whole
        // catalogue via ?limit=99999. Max 50; default 25.
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 25;
        $limit = max(1, min(50, $limit));

        $query = DB::table('products')
            ->leftJoin('inventory_movements', 'products.id', '=', 'inventory_movements.product_id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'Active')
            ->when($q !== null && $q !== '', function ($w) use ($q) {
                // Match name / SKU / barcode. Wildcards are escaped via
                // PDO param binding so user input cannot inject `%`.
                $w->where(function ($w2) use ($q) {
                    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                    $w2->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like);
                });
            })
            ->when($categoryId !== null, fn ($w) => $w->where('products.category_id', $categoryId))
            ->selectRaw("
                products.id,
                products.sku,
                products.barcode,
                products.name,
                products.selling_price,
                products.minimum_selling_price,
                products.tax_enabled,
                products.tax_rate,
                products.category_id,
                categories.name AS category_name,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type IN ($stockTypes) THEN inventory_movements.quantity ELSE 0 END), 0) AS on_hand,
                COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Reserve' THEN inventory_movements.quantity ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN inventory_movements.movement_type = 'Release Reservation' THEN inventory_movements.quantity ELSE 0 END), 0) AS reserved
            ")
            ->groupBy([
                'products.id', 'products.sku', 'products.barcode', 'products.name',
                'products.selling_price', 'products.minimum_selling_price',
                'products.tax_enabled', 'products.tax_rate',
                'products.category_id', 'categories.name',
            ])
            ->orderBy('products.name')
            ->limit($limit);

        return $query->get()
            ->map(fn ($p) => [
                'id' => (int) $p->id,
                'sku' => $p->sku,
                'barcode' => $p->barcode,
                'name' => $p->name,
                'selling_price' => (float) $p->selling_price,
                'minimum_selling_price' => (float) $p->minimum_selling_price,
                'tax_enabled' => (bool) $p->tax_enabled,
                'tax_rate' => (float) $p->tax_rate,
                'category_id' => $p->category_id ? (int) $p->category_id : null,
                'category_name' => $p->category_name,
                'available' => max(0, (int) $p->on_hand - (int) $p->reserved),
            ]);
    }

    /**
     * Performance Phase 1 — server-side product search for `Orders/Create.jsx`.
     *
     * Replaces the previous "ship the entire catalogue in page props"
     * pattern. Returns at most 25 (configurable up to 50) products
     * matching the query string, plus optional category filter.
     *
     * Field shape is identical to `productsForOrderEntry()` so the
     * frontend search-results component renders unchanged. Cost/profit
     * fields are never returned (preserves commit ea3e6e5's gate).
     */
    public function searchProducts(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:128'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $this->productsForOrderEntry([
            'q' => $data['q'] ?? null,
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : null,
            'limit' => isset($data['limit']) ? (int) $data['limit'] : 25,
        ]);

        return response()->json([
            'products' => $results->values(),
            'limit' => isset($data['limit']) ? max(1, min(50, (int) $data['limit'])) : 25,
        ]);
    }

    public function store(StoreOrderRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        // O-1: `submit_action` controls only the post-save redirect.
        // It must not leak into OrderService::createFromPayload, which
        // would treat it as an order column.
        $submitAction = $payload['submit_action'] ?? 'save';
        unset($payload['submit_action']);
        $payload['created_by'] = Auth::id();

        // C-5B Must-Fix M1: reject orders whose customer_id resolves
        // to a merged-away customer. The Create page redirects on
        // GET; this is the belt-and-braces server guard against a
        // direct POST that bypassed the GET.
        if (! empty($payload['customer_id'])) {
            $merged = \App\Models\Customer::query()
                ->whereKey((int) $payload['customer_id'])
                ->whereNotNull('merged_into_customer_id')
                ->first(['id', 'merged_into_customer_id']);
            if ($merged) {
                \App\Services\AuditLogService::log(
                    action: 'write_blocked_merged_source',
                    module: 'customers',
                    recordType: \App\Models\Customer::class,
                    recordId: $merged->id,
                    newValues: [
                        'merged_into_customer_id' => $merged->merged_into_customer_id,
                        'attempted_url' => $request->fullUrl(),
                        'method' => 'POST (orders.store)',
                    ],
                );
                return back()
                    ->withErrors(['customer_id' => "Customer #{$merged->id} was merged into Customer #{$merged->merged_into_customer_id}. Use the surviving customer."])
                    ->withInput();
            }
        }

        $order = $this->orderService->createFromPayload($payload);

        return $this->postSaveRedirect($order, $submitAction, $request->user());
    }

    /**
     * Resolve the post-save redirect for the Order Create page (O-1).
     *
     * The order is ALREADY created at this point — `submit_action` only
     * decides where the operator lands next. Order data is never
     * mutated by this method.
     *
     *  - `save`              → Order Show (existing behaviour).
     *  - `save_add_new`      → fresh Order Create.
     *  - `save_duplicate`    → Order Create with `?duplicate_from={id}`.
     *  - `save_print_label`  → shipping label print page if the user
     *                          has `shipping.print_label`; otherwise
     *                          fall back to Order Show with a note.
     */
    private function postSaveRedirect(Order $order, string $action, ?\App\Models\User $user): RedirectResponse
    {
        $created = "Order {$order->order_number} created.";

        switch ($action) {
            case 'save_add_new':
                return redirect()
                    ->route('orders.create')
                    ->with('success', $created . ' Ready for the next one.');

            case 'save_duplicate':
                return redirect()
                    ->route('orders.create', ['duplicate_from' => $order->id])
                    ->with('success', $created . ' Pre-filled for duplication.');

            case 'save_print_label':
                if ($user?->hasPermission('shipping.print_label')) {
                    return redirect()
                        ->route('shipping-labels.print', $order)
                        ->with('success', $created);
                }
                // Fall through to Order Show with a hint so the operator
                // can ask an authorised user to print. Don't 403 — the
                // order itself was created successfully.
                return redirect()
                    ->route('orders.show', $order)
                    ->with('success', $created . ' (Label printing requires shipping.print_label permission.)');

            case 'save':
            default:
                return redirect()
                    ->route('orders.show', $order)
                    ->with('success', $created);
        }
    }

    public function show(Order $order): Response
    {
        $this->authorizeOwnership($order);

        $order->load([
            'items',
            'customer',
            'createdBy:id,name',
            'confirmedBy:id,name',
            'packedBy:id,name',
            'shippedBy:id,name',
        ]);

        // Returns/Refunds UX: surface enough info to the Change Status
        // modal so the UI can conditionally show "Return details" when
        // the user picks `Returned`, AND can hide the Returned option
        // entirely when it isn't valid (no returns.create permission,
        // or the order already has a return).
        //
        // Professional Return Management — also surface the actual
        // return record (id + status + condition) so Orders/Show can
        // render a "Manage Return" link directly to /returns/{id}.
        // Without this the operator's only path to the return is via
        // the Edit page, where they often start editing order fields
        // instead of opening the return.
        $existingReturn = $order->returns()
            ->latest('id')
            ->first(['id', 'return_status', 'product_condition']);
        $hasReturn = $existingReturn !== null;
        $user = request()->user();

        // Defence-in-depth: hide cost/profit columns from the JSON when
        // the user lacks `orders.view_profit`. Show.jsx also gates the
        // UI with `can('orders.view_profit')`, but stripping the fields
        // here means they don't even reach the browser's page props.
        $this->sanitizeProfitFor($user, $order);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            // R11 — the legal forward transitions for THIS order's current
            // status. The Change Status modal renders only these (plus the
            // current status as the no-op baseline). The server-side DAG
            // gate in OrderService::changeStatus stays the enforcement
            // backstop; this prop is UX only.
            'allowed_transitions' => Order::ALLOWED_TRANSITIONS[$order->status] ?? [],
            'return_reasons' => ReturnReason::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'return_conditions' => OrderReturn::CONDITIONS,
            'can_create_return' => (bool) ($user?->hasPermission('returns.create')),
            'can_view_profit' => (bool) ($user?->hasPermission('orders.view_profit')),
            'has_return' => $hasReturn,
            'existing_return' => $existingReturn,
        ]);
    }

    public function edit(Order $order): Response
    {
        $this->authorizeOwnership($order);

        // Professional Return Management — the Edit page mirrors the
        // Orders/Show Change Status modal: when the operator picks
        // `Returned`, the Edit form expands a Return Details section
        // and the update route uses OrderStatusFlowService to create
        // the linked return atomically. We pass the same props the
        // Show page uses so the JSX can render identical fields.
        $user = request()->user();
        $existingReturn = $order->returns()
            ->latest('id')
            ->first(['id', 'return_status', 'product_condition']);
        $hasReturn = $existingReturn !== null;

        // Strip cost/profit columns from the JSON when the user lacks
        // `orders.view_profit`. Edit.jsx also gates the visible blocks
        // with `can('orders.view_profit')`.
        $this->sanitizeProfitFor($user, $order);

        return Inertia::render('Orders/Edit', [
            'order' => $order,
            'statuses' => Order::STATUSES,
            'return_reasons' => ReturnReason::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'return_conditions' => OrderReturn::CONDITIONS,
            'can_create_return' => (bool) $user?->hasPermission('returns.create'),
            'can_view_profit' => (bool) $user?->hasPermission('orders.view_profit'),
            'has_return' => $hasReturn,
            'existing_return' => $existingReturn,
        ]);
    }

    public function update(UpdateOrderRequest $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        // Professional Return Management — the Edit form may carry an
        // optional `return.*` payload alongside the normal order fields.
        // It's only required when the operator is transitioning INTO
        // `Returned` from this page (NOT when the order is already
        // Returned and other fields are being edited). For every other
        // status the payload is ignored.
        $extra = $request->validate([
            'return' => ['nullable', 'array'],
            'return.return_reason_id' => ['nullable', 'exists:return_reasons,id'],
            'return.product_condition' => ['nullable', \Illuminate\Validation\Rule::in(OrderReturn::CONDITIONS)],
            'return.refund_amount' => ['nullable', 'numeric', 'min:0'],
            'return.shipping_loss_amount' => ['nullable', 'numeric', 'min:0'],
            'return.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data = $request->validated();
        $statusChange = $data['status'] ?? null;
        $statusNote = $data['status_note'] ?? null;
        unset($data['status'], $data['status_note']);

        $isNewReturned = $statusChange === 'Returned' && $statusChange !== $order->status;

        // Inline conditional-required check: a return reason is only
        // required when this is a NEW transition into Returned. We do
        // this instead of `required_if:status,Returned` because the
        // latter would falsely fire when an already-Returned order is
        // being edited for non-status fields.
        if ($isNewReturned && empty($extra['return']['return_reason_id'] ?? null)) {
            return back()
                ->withInput()
                ->withErrors([
                    'return.return_reason_id' => 'A return reason is required when changing the order to Returned.',
                ]);
        }

        // Returned transition requires returns.create permission. Inline
        // check so a user with `orders.edit` alone cannot create a
        // return record via the bulk-update path.
        if ($isNewReturned) {
            abort_unless(
                $request->user()?->hasPermission('returns.create'),
                403,
                'You do not have permission to create a return record.',
            );
        }

        try {
            $newReturn = DB::transaction(function () use ($order, $data, $statusChange, $statusNote, $extra, $isNewReturned) {
                // 1. Persist the non-status field edits (address, money
                //    tweaks, notes) first. If the transaction rolls back
                //    later, these revert too.
                $order->fill([...$data, 'updated_by' => Auth::id()])->save();
                AuditLogService::logModelChange($order, 'updated', 'orders');

                if (! $statusChange || $statusChange === $order->status) {
                    return null; // No status change — done.
                }

                if ($isNewReturned) {
                    // 2a. Use the same atomic flow as Orders/Show modal:
                    //     creates OrderReturn + flips order.status. The
                    //     wrapper itself runs `OrderService::changeStatus`
                    //     which writes inventory return-to-stock and
                    //     reverses marketer profit.
                    $result = $this->statusFlow->changeStatus($order->refresh(), [
                        'status' => 'Returned',
                        'note' => $statusNote,
                        'return' => $extra['return'] ?? [],
                    ]);
                    return $result['return'] ?? null;
                }

                // 2b. Non-Returned status transition — legacy path.
                $this->orderService->changeStatus($order->refresh(), $statusChange, $statusNote);
                return null;
            });
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Send the operator to the new return's show page when a return
        // was just created. Otherwise return to the order detail page.
        if ($newReturn) {
            return redirect()
                ->route('returns.show', $newReturn)
                ->with('success', 'Order returned and return record created.');
        }

        return redirect()
            ->route('orders.show', $order)
            ->with('success', 'Order updated.');
    }

    /**
     * Soft-delete an order.
     *
     * Per the operations policy, only super-admins may delete orders —
     * even users with the legacy `orders.delete` permission slug get a
     * hard 403 here. This replaces the prior approval-request workflow
     * (which let any user with `orders.delete` open an approval) with a
     * direct super-admin-only action.
     *
     * Soft-delete is used so the order's history is preserved: items,
     * inventory movements, shipping records, returns, marketer
     * transactions, and audit trails remain intact and can be restored
     * via `Order::withTrashed()` if the deletion was a mistake. The
     * authoritative cleanup (`destroyForce`) stays for emergency hard
     * deletes triggered by other paths and is itself gated by route
     * middleware.
     */
    public function destroy(Request $request, Order $order): RedirectResponse
    {
        $user = $request->user();

        // Hard 403 for non-super-admins. Reachable only by direct route
        // hit; the UI's delete control should already be hidden via the
        // `auth.user.is_super_admin` flag.
        if (! $user || ! $user->isSuperAdmin()) {
            abort(403, 'Only super administrators can delete orders.');
        }

        $this->authorizeOwnership($order);

        $reason = trim((string) $request->input('reason', ''));

        // Soft delete preserves all related rows (items, inventory
        // movements, returns, shipments, marketer transactions, audit
        // trail). No orphaning.
        $order->update(['deleted_by' => $user->id]);
        $order->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'orders',
            recordType: Order::class,
            recordId: $order->id,
            oldValues: [
                'order_number' => $order->order_number,
                'status' => $order->status,
                'total_amount' => $order->total_amount,
            ],
            newValues: [
                'action' => 'soft_delete',
                'reason' => $reason !== '' ? $reason : 'No reason provided.',
            ],
        );

        return redirect()
            ->route('orders.index')
            ->with('success', "Order {$order->order_number} deleted.");
    }

    /**
     * Approval-gated edit of money fields on a confirmed order. The
     * regular `update()` method blocks these once the order is confirmed
     * — this endpoint creates the approval instead.
     */
    public function requestPriceEdit(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $data = $request->validate([
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_amount' => ['nullable', 'numeric', 'min:0'],
            'extra_fees' => ['nullable', 'numeric', 'min:0'],
            'cod_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $patch = collect($data)
            ->only(['discount_amount', 'shipping_amount', 'extra_fees', 'cod_amount'])
            ->filter(fn ($v) => $v !== null)
            ->all();

        if (empty($patch)) {
            return back()->with('error', 'Nothing to change.');
        }

        $oldValues = collect($order->only(array_keys($patch)))->all();

        app(\App\Services\ApprovalService::class)->request(
            type: 'Edit Confirmed Order Price',
            target: $order,
            oldValues: $oldValues,
            newValues: $patch,
            reason: $data['reason'],
        );

        return back()->with('success', 'Price-edit request submitted for approval.');
    }

    /**
     * Used by the approval system itself when the previous (legacy)
     * delete flow was invoked. Kept for super-admin emergencies.
     */
    public function destroyForce(Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $order->update(['deleted_by' => Auth::id()]);
        $order->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'orders',
            recordType: Order::class,
            recordId: $order->id,
        );

        return redirect()->route('orders.index')->with('success', 'Order deleted.');
    }

    /**
     * Status change endpoint — used by Orders/Show quick-action buttons.
     *
     * Returns/Refunds UX: when the target status is `Returned`, the
     * request also carries a `return` payload (reason, condition,
     * amounts, notes). The status change AND return creation happen
     * atomically through `OrderStatusFlowService`. On success the
     * operator is redirected to the new return's show page so they
     * can proceed with inspection. For every other status the legacy
     * behavior is preserved exactly.
     */
    public function changeStatus(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnership($order);

        $data = $request->validate([
            'status' => ['required', 'in:'.implode(',', Order::STATUSES)],
            'note' => ['nullable', 'string', 'max:500'],
            // Optional return payload. Required keys are gated by
            // `required_if:status,Returned` below.
            'return' => ['nullable', 'array'],
            // `nullable` MUST come first: the Orders/Show modal always
            // sends a `return` object, so `return.return_reason_id`
            // arrives present-but-empty for non-Returned changes. The
            // global ConvertEmptyStringsToNull middleware rewrites that
            // '' to null — without `nullable`, the `exists` rule would
            // then run against null and fail, silently rejecting EVERY
            // status change. `required_if` still forces a real reason
            // when the target status is `Returned`.
            'return.return_reason_id' => ['nullable', 'required_if:status,Returned', 'exists:return_reasons,id'],
            'return.product_condition' => ['nullable', Rule::in(OrderReturn::CONDITIONS)],
            'return.refund_amount' => ['nullable', 'numeric', 'min:0'],
            'return.shipping_loss_amount' => ['nullable', 'numeric', 'min:0'],
            'return.notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // Returned status requires a separate permission so a user with
        // `orders.change_status` alone cannot create return records.
        if ($data['status'] === 'Returned') {
            abort_unless(
                $request->user()?->hasPermission('returns.create'),
                403,
                'You do not have permission to create a return record.',
            );
        }

        try {
            $result = $this->statusFlow->changeStatus($order, $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Domain-rule failures (shipping checklist, profit guard,
            // duplicate return, fiscal/period guard) bubble up here.
            // Surface as a flash error so the modal redirects back
            // instead of 500-ing. The DB::transaction inside the
            // service guarantees no partial state.
            return back()->with('error', $e->getMessage());
        }

        // Returned + return created → send the operator to the new
        // return's show page where they'll do the inspection next.
        if ($data['status'] === 'Returned' && ! empty($result['return'])) {
            return redirect()
                ->route('returns.show', $result['return'])
                ->with('success', 'Order returned and return record created.');
        }

        return back()->with('success', "Status changed to {$data['status']}.");
    }

    public function timeline(Order $order): Response
    {
        $this->authorizeOwnership($order);

        $order->load([
            'statusHistory.changedBy:id,name',
            'notes.createdBy:id,name',
        ]);

        return Inertia::render('Orders/Timeline', [
            'order' => $order,
        ]);
    }

    /**
     * AJAX endpoint hit while creating an order — runs the duplicate
     * detection rules over the in-progress payload and returns the
     * score + reasons so the form can show a warning.
     */
    public function checkDuplicate(Request $request)
    {
        $data = $request->validate([
            'primary_phone' => ['nullable', 'string'],
            'secondary_phone' => ['nullable', 'string'],
            'customer_name' => ['nullable', 'string'],
            'city' => ['nullable', 'string'],
            'customer_address' => ['nullable', 'string'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->duplicateService->evaluate($data));
    }

    /**
     * Phase 5.9 — AJAX preview of marketer profit for the in-progress
     * Order Create payload. Used by the "Marketer profit preview"
     * widget in resources/js/Pages/Orders/Create.jsx so the operator
     * sees the resolved tier cost / shipping / VAT live as they edit.
     *
     * Returns the per-line breakdown plus a total. NULL when the
     * marketer or items aren't supplied yet.
     */
    public function marketerProfitPreview(
        Request $request,
        \App\Services\MarketerPricingResolver $resolver,
    ) {
        // Cost/profit is sensitive — this endpoint returns per-line
        // cost_price + profit + total. Gate on `orders.view_profit` so
        // Order Agents, Warehouse Agents, Viewers, and Marketers (who
        // have `orders.create` for the back-office Create page) cannot
        // pull internal pricing data via direct API calls.
        abort_unless(
            $request->user()?->hasPermission('orders.view_profit'),
            403,
            'You do not have permission to view marketer profit.',
        );

        $data = $request->validate([
            'marketer_id' => ['required', 'exists:marketers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ]);

        $marketer = \App\Models\Marketer::with('priceTier:id,code,name', 'priceGroup:id,name')
            ->findOrFail($data['marketer_id']);

        $lines = [];
        $total = 0.0;
        foreach ($data['items'] as $item) {
            $resolved = $resolver->resolveForItem(
                $marketer,
                (int) $item['product_id'],
                ! empty($item['product_variant_id']) ? (int) $item['product_variant_id'] : null,
            );
            $profit = $resolver->profitForItem(
                unitPrice: (float) $item['unit_price'],
                quantity: (int) $item['quantity'],
                costPrice: $resolved['cost_price'],
                shippingCost: $resolved['shipping_cost'],
                vatPercent: $resolved['vat_percent'],
            );
            $total += $profit;
            $lines[] = [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
                'unit_price' => (float) $item['unit_price'],
                'cost_price' => $resolved['cost_price'],
                'shipping_cost' => $resolved['shipping_cost'],
                'vat_percent' => $resolved['vat_percent'],
                'source' => $resolved['source'],
                'profit' => $profit,
            ];
        }

        return response()->json([
            'marketer' => [
                'id' => $marketer->id,
                'code' => $marketer->code,
                'tier' => $marketer->priceTier?->name,
                'group' => $marketer->priceGroup?->name,
            ],
            'lines' => $lines,
            'total' => round($total, 2),
        ]);
    }

    /**
     * Marketer ownership: marketers can only access their own orders.
     * Phase 5 will replace `marketer_id` with the real marketer record id;
     * the gate is in place now so the moment Phase 5 lands, isolation
     * works without touching this controller.
     */
    private function authorizeOwnership(Order $order): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(401);
        }

        if (! $user->isMarketer()) {
            return;
        }

        $marketerId = $user->marketer_id ?? null;
        if ($marketerId === null || $order->marketer_id !== $marketerId) {
            abort(403, 'You do not have access to this order.');
        }
    }
}
