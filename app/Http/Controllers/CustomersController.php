<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Country;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Services\AuditLogService;
use App\Services\CustomerRiskService;
use App\Services\PhoneNormalizationService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for customers, including tag sync and risk-score recalculation.
 *
 * Backend permission enforcement happens at the route layer
 * (routes/web.php) via the `permission:` middleware. The controller
 * additionally relies on Form Requests for validation and the
 * AuditLogService for change tracking.
 */
class CustomersController extends Controller
{
    public function __construct(
        private readonly CustomerRiskService $riskService,
        private readonly PhoneNormalizationService $phoneService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'risk_level', 'customer_type']);

        $customers = Customer::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                // O-2: extend search to match the normalized triple too.
                // A search for "+201012345678" now finds a customer whose
                // operator typed "01012345678".
                $like = "%{$term}%";
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)
                        ->orWhere('primary_phone', 'like', $like)
                        ->orWhere('secondary_phone', 'like', $like)
                        ->orWhere('normalized_phone', 'like', $like)
                        ->orWhere('secondary_normalized_phone', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($filters['risk_level'] ?? null, fn ($q, $v) => $q->where('risk_level', $v))
            ->when($filters['customer_type'] ?? null, fn ($q, $v) => $q->where('customer_type', $v))
            ->withCount('orders')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Create', [
            'locations' => $this->locationTree(),
            'default_country_code' => SettingsService::get('default_country_code', 'EG'),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

        // O-2: populate the phone triple from the operator's raw input.
        // The request validator already proved the values are
        // normalizable; here we materialise the columns.
        $data = $this->withNormalizedPhones($data);

        $customer = DB::transaction(function () use ($data, $tags) {
            $customer = Customer::create([
                ...$data,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            foreach (array_unique(array_filter(array_map('trim', $tags))) as $tag) {
                $customer->tags()->create([
                    'tag' => $tag,
                    'created_by' => Auth::id(),
                ]);
            }

            AuditLogService::logModelChange($customer, 'created', 'customers');

            return $customer;
        });

        // AJAX consumers (the inline "+ New customer" modal on the order
        // form) want the new row back as JSON so they can auto-select it
        // without losing the in-progress order state. Browser POSTs from
        // the standalone /customers/create page still get the redirect.
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'customer' => $customer->fresh()->loadCount('orders'),
                'message' => 'Customer created.',
            ]);
        }

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Customer created.');
    }

    public function show(Customer $customer, Request $request): Response
    {
        $customer->load([
            'tags',
            'addresses',
            'orders' => fn ($q) => $q->latest('id')->limit(20),
        ]);

        // C-1: ship the props the quick-action bar needs.
        //
        // `latest_order_id` is the most-recent "safe" order — Cancelled
        // and Need Review orders are excluded so the "Duplicate Last
        // Order" button never seeds a fresh order from something that
        // was deliberately killed. The query uses the same
        // `customer_id` index the rest of the controller uses; no
        // schema change.
        $user = $request->user();
        $latestOrderId = \App\Models\Order::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', ['Cancelled', 'Need Review'])
            ->latest('id')
            ->value('id');

        // C-2: 360 stats + duplicate-customer detection. Computed in the
        // controller via aggregate queries; no service layer needed.
        // `riskBreakdown` is computed once and re-used so we don't pay
        // for the same risk math twice when both `risk_breakdown` and
        // `risk_recommendation` need it.
        $riskBreakdown = $this->riskService->calculate($customer);
        $stats = $this->customerStats($customer);
        $duplicateCustomers = $this->duplicateCustomers($customer);

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'risk_breakdown' => $riskBreakdown,

            // C-1 quick-action props. Keep the surface tiny — each prop
            // has a single job and a clear data source.
            'latest_order_id' => $latestOrderId,
            'total_orders' => $stats['total_orders'],
            'whatsapp_url' => $customer->whatsappUrl(),
            'can_create_order' => (bool) $user?->hasPermission('orders.create'),
            'can_view_orders' => (bool) $user?->hasPermission('orders.view'),

            // C-2: stats + duplicate alert + risk recommendation.
            'stats' => $stats,
            'duplicate_customers' => $duplicateCustomers,
            'risk_recommendation' => $this->riskRecommendation($riskBreakdown['level'] ?? 'Low'),
        ]);
    }

    /**
     * C-2: aggregate stats for the Customer 360 cards.
     *
     * One indexed query (customer_id) using SUM-CASE expressions —
     * mirrors the cheap pattern used by CustomerRiskService::calculate().
     * No PHP-side iteration, no `customer->orders` materialisation.
     *
     * Field meanings — pinned here because they show up in tests + UI:
     *
     * - `total_spent`         — Delivered orders only. Cancelled and
     *                           Returned orders are excluded; otherwise
     *                           the operator would see misleading
     *                           revenue.
     * - `outstanding_balance` — Estimated. Sums `cod_amount` on orders
     *                           whose collection is still open. The
     *                           pre-O-5 single-COD model can't reflect
     *                           multi-payment splits, so the UI labels
     *                           this card as "Estimated outstanding".
     * - `cod_success_rate`    — `Collected + Settlement Received` rows
     *                           over total `cod_amount > 0` rows. Null
     *                           when no COD orders.
     * - `return_rate`         — Returned / (Delivered + Returned).
     *                           Both outcomes count toward the
     *                           denominator so an only-Returned customer
     *                           shows 100% (not undefined). Null when
     *                           both are zero.
     * - `average_order_value` — total_spent / delivered_orders. Null
     *                           when delivered = 0.
     *
     * @return array<string,mixed>
     */
    private function customerStats(\App\Models\Customer $customer): array
    {
        $row = \App\Models\Order::query()
            ->where('customer_id', $customer->id)
            ->selectRaw("
                COUNT(*) AS total_orders,
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) AS delivered_orders,
                SUM(CASE WHEN status = 'Returned' THEN 1 ELSE 0 END) AS returned_orders,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
                SUM(CASE WHEN status = 'Delivered' THEN total_amount ELSE 0 END) AS total_spent,
                SUM(
                    CASE
                        WHEN cod_amount > 0
                         AND collection_status IN ('Not Collected','Partially Collected','Pending Settlement','Rejected')
                        THEN cod_amount
                        ELSE 0
                    END
                ) AS outstanding_balance,
                SUM(CASE WHEN cod_amount > 0 THEN 1 ELSE 0 END) AS cod_orders,
                SUM(
                    CASE
                        WHEN cod_amount > 0
                         AND collection_status IN ('Collected','Settlement Received')
                        THEN 1
                        ELSE 0
                    END
                ) AS cod_collected_orders,
                MAX(created_at) AS last_order_at
            ")
            ->first();

        $total = (int) ($row->total_orders ?? 0);
        $delivered = (int) ($row->delivered_orders ?? 0);
        $returned = (int) ($row->returned_orders ?? 0);
        $cancelled = (int) ($row->cancelled_orders ?? 0);
        $totalSpent = (float) ($row->total_spent ?? 0);
        $outstanding = (float) ($row->outstanding_balance ?? 0);
        $codOrders = (int) ($row->cod_orders ?? 0);
        $codCollected = (int) ($row->cod_collected_orders ?? 0);

        $aov = $delivered > 0 ? round($totalSpent / $delivered, 2) : null;
        $codSuccessRate = $codOrders > 0 ? round(($codCollected / $codOrders) * 100, 1) : null;
        $returnDenominator = $delivered + $returned;
        $returnRate = $returnDenominator > 0 ? round(($returned / $returnDenominator) * 100, 1) : null;

        return [
            'total_orders' => $total,
            'delivered_orders' => $delivered,
            'returned_orders' => $returned,
            'cancelled_orders' => $cancelled,
            'total_spent' => round($totalSpent, 2),
            // Labeled "Estimated outstanding" in the UI — the math is a
            // best-effort approximation pre-O-5. Numeric type stays
            // consistent for the React tabular-nums formatter.
            'outstanding_balance' => round($outstanding, 2),
            'cod_orders' => $codOrders,
            'cod_collected_orders' => $codCollected,
            'cod_success_rate' => $codSuccessRate,
            'return_rate' => $returnRate,
            'average_order_value' => $aov,
            'last_order_at' => $row->last_order_at,
        ];
    }

    /**
     * C-2: read-only duplicate-customer detector.
     *
     * Uses the O-2 indexed `normalized_phone` column. Excludes the
     * current customer and soft-deleted rows. No merge, no auto-action
     * — the UI surfaces a banner; merge is Phase C-5.
     *
     * @return array<int, array{id:int, name:string, primary_phone:string}>
     */
    private function duplicateCustomers(\App\Models\Customer $customer): array
    {
        // Only meaningful when the current customer has a normalized
        // phone. Pre-O-2 customers with NULL normalized_phone are
        // skipped — backfill is the right path before duplicate review.
        if (! $customer->normalized_phone) {
            return [];
        }

        return \App\Models\Customer::query()
            ->where('normalized_phone', $customer->normalized_phone)
            ->where('id', '!=', $customer->id)
            ->whereNull('deleted_at')
            ->limit(5) // sane cap — operator clicks through to inspect
            ->get(['id', 'name', 'primary_phone'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'primary_phone' => $c->primary_phone,
            ])
            ->all();
    }

    /**
     * C-2: operational copy derived from the risk level.
     *
     * Pure mapping — no policy change. The order flow remains unblocked
     * for every level; this is a guidance string for the operator.
     */
    private function riskRecommendation(string $level): string
    {
        return match ($level) {
            'High' => 'Confirm carefully before shipping or COD.',
            'Medium' => 'Review recent history before shipping.',
            default => 'Normal order flow.',
        };
    }

    public function edit(Customer $customer): Response
    {
        $customer->load('tags');

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
            'tags' => $customer->tags->pluck('tag'),
            'locations' => $this->locationTree(),
            'default_country_code' => SettingsService::get('default_country_code', 'EG'),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? null; // null => leave alone
        unset($data['tags']);

        // O-2: recompute the phone triple from whatever was sent on the
        // update. Unchanged phones don't need to re-flow through the
        // service because the existing columns stay.
        $data = $this->withNormalizedPhones($data);

        DB::transaction(function () use ($customer, $data, $tags) {
            $customer->fill([
                ...$data,
                'updated_by' => Auth::id(),
            ])->save();

            if ($tags !== null) {
                $clean = array_unique(array_filter(array_map('trim', $tags)));
                CustomerTag::where('customer_id', $customer->id)->delete();
                foreach ($clean as $tag) {
                    $customer->tags()->create([
                        'tag' => $tag,
                        'created_by' => Auth::id(),
                    ]);
                }
            }

            AuditLogService::logModelChange($customer, 'updated', 'customers');
        });

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Customer updated.');
    }

    /**
     * O-2: enrich a validated customer payload with the phone triple.
     *
     * The request validator has already proved the raw `primary_phone`
     * (and `secondary_phone` when present) can be normalized. Here we
     * persist the resulting `country_code` / `local_phone` /
     * `normalized_phone` columns alongside the legacy phone strings.
     *
     * Idempotent: the original keys are preserved so callers can write
     * the array straight to `Customer::create()` / `fill()`.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function withNormalizedPhones(array $data): array
    {
        if (! empty($data['primary_phone'])) {
            $res = $this->phoneService->normalize(
                $data['primary_phone'],
                $data['country_code'] ?? null,
            );
            if ($res['valid']) {
                $data['country_code'] = $res['country_code'];
                $data['local_phone'] = $res['local_phone'];
                $data['normalized_phone'] = $res['normalized_phone'];
            }
        }
        if (! empty($data['secondary_phone'])) {
            $res = $this->phoneService->normalize(
                $data['secondary_phone'],
                $data['secondary_country_code'] ?? null,
            );
            if ($res['valid']) {
                $data['secondary_country_code'] = $res['country_code'];
                $data['secondary_local_phone'] = $res['local_phone'];
                $data['secondary_normalized_phone'] = $res['normalized_phone'];
            }
        }
        return $data;
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $customer->update([
            'deleted_by' => Auth::id(),
        ]);
        $customer->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'customers',
            recordType: Customer::class,
            recordId: $customer->id,
        );

        return redirect()
            ->route('customers.index')
            ->with('success', 'Customer deleted.');
    }

    /**
     * Active country → state → city tree used by the create/edit forms
     * to populate cascading dropdowns. Tiny payload (<10 KB), shipped
     * inline with the page so the form is reactive without an extra fetch.
     *
     * @return array<int, array<string,mixed>>
     */
    public static function locationTree(): array
    {
        return Country::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['states' => function ($q) {
                $q->where('is_active', true)
                    ->orderBy('sort_order')
                    ->with(['cities' => function ($cq) {
                        $cq->where('is_active', true)->orderBy('sort_order');
                    }]);
            }])
            ->get()
            ->map(fn ($country) => [
                'id' => $country->id,
                'code' => $country->code,
                'name_ar' => $country->name_ar,
                'name_en' => $country->name_en,
                'states' => $country->states->map(fn ($s) => [
                    'id' => $s->id,
                    'name_ar' => $s->name_ar,
                    'name_en' => $s->name_en,
                    'type' => $s->type,
                    'cities' => $s->cities->map(fn ($c) => [
                        'id' => $c->id,
                        'name_ar' => $c->name_ar,
                        'name_en' => $c->name_en,
                    ])->all(),
                ])->all(),
            ])
            ->all();
    }

    /**
     * Phone-prefilled lookup endpoint used by the new-order page. Returns
     * a JSON match (or null) so the order form can show a "we know this
     * customer" panel before the operator finishes typing.
     */
    public function lookupByPhone(Request $request)
    {
        $phone = trim((string) $request->query('phone', ''));
        if ($phone === '') {
            return response()->json(['customer' => null]);
        }

        $normalised = preg_replace('/[\s\-+]/', '', $phone);

        $customer = Customer::query()
            ->whereRaw("REPLACE(REPLACE(REPLACE(primary_phone, ' ', ''), '-', ''), '+', '') = ?", [$normalised])
            ->orWhereRaw("REPLACE(REPLACE(REPLACE(secondary_phone, ' ', ''), '-', ''), '+', '') = ?", [$normalised])
            ->withCount(['orders', 'orders as returned_orders_count' => fn ($q) => $q->where('status', 'Returned')])
            ->first();

        return response()->json(['customer' => $customer]);
    }
}
