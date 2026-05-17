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

        // C-3: read-only customer activity timeline. Merges events from
        // customer.created_at + orders + order_status_history + returns +
        // refunds. Capped at 30 events. No writes, no policy changes.
        $timeline = $this->customerTimeline($customer);

        // C-4B: customer address book. Default first, then newest by id.
        // Shaped specifically for the Show panel so the front-end doesn't
        // have to massage the Eloquent serialisation.
        $customerAddresses = $customer->addresses()
            ->with(['createdBy:id,name', 'updatedBy:id,name'])
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get(['id', 'customer_id', 'address', 'city', 'governorate', 'country', 'is_default', 'created_by', 'updated_by', 'created_at', 'updated_at'])
            ->map(fn ($a) => [
                'id' => (int) $a->id,
                'address' => $a->address,
                'city' => $a->city,
                'governorate' => $a->governorate,
                'country' => $a->country,
                'is_default' => (bool) $a->is_default,
                'created_at' => optional($a->created_at)->toIso8601String(),
                'updated_at' => optional($a->updated_at)->toIso8601String(),
                'created_by' => $a->createdBy ? ['id' => (int) $a->createdBy->id, 'name' => $a->createdBy->name] : null,
                'updated_by' => $a->updatedBy ? ['id' => (int) $a->updatedBy->id, 'name' => $a->updatedBy->name] : null,
            ])
            ->all();

        // C-4A: structured customer notes (latest 50). Separate from
        // the legacy `customers.notes` text column.
        $customerNotes = $customer->customerNotes()
            ->with('createdBy:id,name')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'customer_id', 'note', 'is_internal', 'created_by', 'created_at'])
            ->map(fn ($n) => [
                'id' => (int) $n->id,
                'note' => $n->note,
                'is_internal' => (bool) $n->is_internal,
                'created_at' => optional($n->created_at)->toIso8601String(),
                'created_by' => $n->createdBy ? [
                    'id' => (int) $n->createdBy->id,
                    'name' => $n->createdBy->name,
                ] : null,
            ])
            ->all();

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

            // C-3: read-only activity timeline.
            'timeline' => $timeline,

            // C-4A: structured customer notes (separate from the
            // free-text `customers.notes` column). Also drives a new
            // event type in `customerTimeline()`.
            'customer_notes' => $customerNotes,
            // Surface delete permission for the per-note delete button.
            // The customers.delete slug is the existing surface for any
            // destructive customer-side action.
            'can_delete_customer' => (bool) $user?->hasPermission('customers.delete'),

            // C-4B: address book payload + manage gate. `customers.edit`
            // covers add/update/set-default; delete is gated by the
            // existing `can_delete_customer` flag the notes panel
            // already uses.
            'customer_addresses' => $customerAddresses,
            'can_manage_addresses' => (bool) $user?->hasPermission('customers.edit'),
        ]);
    }

    /**
     * C-4A: store a structured customer note.
     *
     * Permission gating is enforced at the route layer
     * (`permission:customers.edit`). This method only needs to validate
     * input and write the row.
     */
    public function storeNote(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
            'is_internal' => ['nullable', 'boolean'],
        ]);

        $note = $customer->customerNotes()->create([
            'note' => $data['note'],
            'is_internal' => array_key_exists('is_internal', $data)
                ? (bool) $data['is_internal']
                : true,
            'created_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($note, 'created', 'customers');

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Note added.');
    }

    /**
     * C-4A: delete a structured customer note.
     *
     * Defence-in-depth — verify the note actually belongs to the URL
     * customer before deleting. Without this check a privileged user
     * could pass a customer_id of customer A with a note_id from
     * customer B and remove the wrong row.
     */
    public function destroyNote(Customer $customer, \App\Models\CustomerNote $note): RedirectResponse
    {
        if ((int) $note->customer_id !== (int) $customer->id) {
            abort(404);
        }

        $note->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'customers',
            recordType: \App\Models\CustomerNote::class,
            recordId: $note->id,
        );

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Note removed.');
    }

    /* ──────────────────── C-4B: Customer Address Book ──────────────────── */

    /**
     * Validate a customer-address payload. Centralised so the create
     * and update paths share the same rules and any future tightening
     * (e.g. district FK once O-3 ships) lands in one place.
     *
     * @return array<string,mixed>
     */
    private function validateAddressPayload(Request $request): array
    {
        // city and country are required by the `customer_addresses` table
        // schema (NOT NULL). governorate is nullable on both sides. The
        // doc spec's "nullable" hint reflects the wishlist for after O-3
        // ships the full address tree; for now we match the DB.
        return $request->validate([
            'address' => ['required', 'string', 'max:2000'],
            'city' => ['required', 'string', 'max:255'],
            'governorate' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Mirror a default address back to the legacy `customers.*` columns.
     *
     * Pre-C-4B, every read path (Order Create prefill, customer Show,
     * reports) sourced the customer's address from `customers.default_address`
     * + `city` / `governorate` / `country`. Keeping those columns in sync
     * means we don't touch existing read paths and back-compat stays
     * intact.
     *
     * Caller is responsible for running this inside the same transaction
     * as the address mutation.
     */
    private function syncLegacyDefaultAddress(Customer $customer, \App\Models\CustomerAddress $address): void
    {
        $customer->fill([
            'default_address' => $address->address,
            'city' => $address->city ?? $customer->city,
            'governorate' => $address->governorate ?? $customer->governorate,
            'country' => $address->country ?? $customer->country,
            'updated_by' => Auth::id(),
        ])->save();
    }

    public function storeAddress(Request $request, Customer $customer): RedirectResponse
    {
        $data = $this->validateAddressPayload($request);
        $explicitDefault = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($customer, $data, $explicitDefault) {
            // First address for this customer auto-becomes default
            // even if the form didn't check the box. Saves an extra
            // round-trip and avoids a customer with no default.
            $isFirst = $customer->addresses()->count() === 0;
            $shouldBeDefault = $explicitDefault || $isFirst;

            if ($shouldBeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            $address = $customer->addresses()->create([
                'address' => $data['address'],
                'city' => $data['city'],
                'governorate' => $data['governorate'] ?? null,
                'country' => $data['country'],
                'is_default' => $shouldBeDefault,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);

            if ($shouldBeDefault) {
                $this->syncLegacyDefaultAddress($customer, $address);
            }

            AuditLogService::logModelChange($address, 'created', 'customers');
        });

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Address added.');
    }

    public function updateAddress(Request $request, Customer $customer, \App\Models\CustomerAddress $address): RedirectResponse
    {
        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(404);
        }
        $data = $this->validateAddressPayload($request);
        $newDefault = (bool) ($data['is_default'] ?? false);

        DB::transaction(function () use ($customer, $address, $data, $newDefault) {
            $address->fill([
                'address' => $data['address'],
                'city' => $data['city'],
                'governorate' => $data['governorate'] ?? null,
                'country' => $data['country'],
                'is_default' => $newDefault,
                'updated_by' => Auth::id(),
            ]);

            // If the operator promotes this row to default, clear the
            // other addresses' default flag first so we never violate
            // the invariant.
            if ($newDefault) {
                $customer->addresses()
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->save();

            if ($newDefault) {
                $this->syncLegacyDefaultAddress($customer, $address);
            }

            AuditLogService::logModelChange($address, 'updated', 'customers');
        });

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Address updated.');
    }

    public function setDefaultAddress(Customer $customer, \App\Models\CustomerAddress $address): RedirectResponse
    {
        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(404);
        }

        DB::transaction(function () use ($customer, $address) {
            $customer->addresses()->update(['is_default' => false]);
            $address->fill(['is_default' => true, 'updated_by' => Auth::id()])->save();
            $this->syncLegacyDefaultAddress($customer, $address);

            AuditLogService::log(
                action: 'default_set',
                module: 'customers',
                recordType: \App\Models\CustomerAddress::class,
                recordId: $address->id,
            );
        });

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Default address updated.');
    }

    public function destroyAddress(Customer $customer, \App\Models\CustomerAddress $address): RedirectResponse
    {
        if ((int) $address->customer_id !== (int) $customer->id) {
            abort(404);
        }

        DB::transaction(function () use ($customer, $address) {
            $wasDefault = (bool) $address->is_default;
            $address->delete();

            // If we just deleted the default, promote the most-recent
            // remaining address to default. If none remain, the legacy
            // `customers.default_address` stays as the last-known value
            // (no autoclear — that would lose history for downstream
            // reports). Operators can edit the customer to clear.
            if ($wasDefault) {
                $next = $customer->addresses()->orderByDesc('id')->first();
                if ($next) {
                    $next->fill(['is_default' => true, 'updated_by' => Auth::id()])->save();
                    $this->syncLegacyDefaultAddress($customer, $next);
                }
            }

            AuditLogService::log(
                action: 'deleted',
                module: 'customers',
                recordType: \App\Models\CustomerAddress::class,
                recordId: $address->id,
            );
        });

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Address removed.');
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

    /**
     * C-3: build the read-only customer activity timeline.
     *
     * Sources merged in this phase (all use indexed columns):
     *   - customer.created_at                  → customer_created
     *   - orders WHERE customer_id = ?         → order_created
     *   - order_status_history (recent orders) → order_status_changed
     *   - returns WHERE customer_id = ?        → return_created
     *   - refunds WHERE customer_id = ?        → refund_created
     *                                          + refund_approved (when approved_at set)
     *                                          + refund_rejected (when rejected_at set)
     *                                          + refund_paid     (when paid_at set)
     *
     * Each source is independently capped before merge so a customer with
     * 10 000 orders doesn't pull 30 000 history rows into PHP. After
     * merging we sort DESC by timestamp and slice to 30. Operator clicks
     * through to the related record for detail.
     *
     * Audit-log and shipment events are deferred — see
     * docs/orders-products/IMPLEMENTATION_PHASES.md C-3 section.
     *
     * @return array<int, array{
     *   id:string, type:string, title:string, subtitle:?string,
     *   timestamp:string, actor_name:?string, tone:string,
     *   href:?string, meta:?array
     * }>
     */
    private function customerTimeline(\App\Models\Customer $customer): array
    {
        $events = [];
        $cap = 30; // per-source cap; final list is also sliced to 30.

        // 1. Customer profile created — anchor event so a brand-new
        //    customer with zero orders still has a timeline.
        if ($customer->created_at) {
            $events[] = [
                'id' => "customer_created:{$customer->id}",
                'type' => 'customer_created',
                'title' => 'Customer profile created',
                'subtitle' => $customer->name,
                'timestamp' => $customer->created_at->toIso8601String(),
                'actor_name' => optional($customer->createdBy)->name,
                'tone' => 'slate',
                'href' => null,
                'meta' => null,
            ];
        }

        // 2. Orders created. Limit upfront so order_status_history can
        //    safely use the same id list without an unbounded IN clause.
        $recentOrders = \App\Models\Order::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit($cap)
            ->get(['id', 'order_number', 'status', 'total_amount', 'currency_code', 'created_at', 'created_by']);

        $orderIds = $recentOrders->pluck('id')->all();

        foreach ($recentOrders as $o) {
            $events[] = [
                'id' => "order_created:{$o->id}",
                'type' => 'order_created',
                'title' => "Order {$o->order_number} created",
                'subtitle' => $o->currency_code . ' ' . number_format((float) $o->total_amount, 2),
                'timestamp' => optional($o->created_at)->toIso8601String(),
                'actor_name' => null,
                'tone' => 'default',
                'href' => route('orders.show', $o->id),
                'meta' => ['order_status' => $o->status],
            ];
        }

        // 3. Order status changes — bounded by the recent order id list.
        if (! empty($orderIds)) {
            $history = \App\Models\OrderStatusHistory::query()
                ->whereIn('order_id', $orderIds)
                ->latest('id')
                ->limit($cap)
                ->get(['id', 'order_id', 'old_status', 'new_status', 'changed_by', 'created_at']);

            $orderNumberById = $recentOrders->pluck('order_number', 'id');

            foreach ($history as $h) {
                $orderNumber = $orderNumberById[$h->order_id] ?? "#{$h->order_id}";
                $tone = match ($h->new_status) {
                    'Delivered' => 'emerald',
                    'Returned', 'Cancelled' => 'amber',
                    'Need Review' => 'red',
                    default => 'default',
                };
                $events[] = [
                    'id' => "order_status:{$h->id}",
                    'type' => 'order_status_changed',
                    'title' => "Order {$orderNumber} changed from {$h->old_status} to {$h->new_status}",
                    'subtitle' => null,
                    'timestamp' => optional($h->created_at)->toIso8601String(),
                    'actor_name' => optional($h->changedBy)->name,
                    'tone' => $tone,
                    'href' => route('orders.show', $h->order_id),
                    'meta' => ['from' => $h->old_status, 'to' => $h->new_status],
                ];
            }
        }

        // 4. Returns — directly indexed on customer_id.
        $returns = \App\Models\OrderReturn::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit($cap)
            ->get(['id', 'order_id', 'return_status', 'created_at', 'created_by']);

        foreach ($returns as $r) {
            // Use the model's display reference accessor if available.
            $rmaRef = method_exists($r, 'getDisplayReferenceAttribute')
                ? $r->display_reference
                : ("RET-" . str_pad((string) $r->id, 6, '0', STR_PAD_LEFT));
            $events[] = [
                'id' => "return_created:{$r->id}",
                'type' => 'return_created',
                'title' => "Return {$rmaRef} opened",
                'subtitle' => "Status: {$r->return_status}",
                'timestamp' => optional($r->created_at)->toIso8601String(),
                'actor_name' => null,
                'tone' => 'amber',
                'href' => route('returns.show', $r->id),
                'meta' => ['return_status' => $r->return_status, 'order_id' => $r->order_id],
            ];
        }

        // 5. Refunds — indexed on customer_id. Each refund row can yield
        //    up to 4 events: created, approved, rejected, paid. The
        //    refund index has individual timestamp indexes so this stays
        //    cheap.
        $refunds = \App\Models\Refund::query()
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->limit($cap)
            ->get(['id', 'order_id', 'amount', 'status', 'created_at', 'approved_at', 'rejected_at', 'paid_at']);

        foreach ($refunds as $rf) {
            $events[] = [
                'id' => "refund_created:{$rf->id}",
                'type' => 'refund_created',
                'title' => "Refund request #{$rf->id} created",
                'subtitle' => 'Amount: ' . number_format((float) $rf->amount, 2),
                'timestamp' => optional($rf->created_at)->toIso8601String(),
                'actor_name' => null,
                'tone' => 'default',
                'href' => null, // Refund show route may not be permission-safe for every user; defer the link.
                'meta' => ['refund_status' => $rf->status, 'order_id' => $rf->order_id],
            ];
            if ($rf->approved_at) {
                $events[] = [
                    'id' => "refund_approved:{$rf->id}",
                    'type' => 'refund_approved',
                    'title' => "Refund #{$rf->id} approved",
                    'subtitle' => null,
                    'timestamp' => $rf->approved_at->toIso8601String(),
                    'actor_name' => null,
                    'tone' => 'emerald',
                    'href' => null,
                    'meta' => ['refund_id' => $rf->id],
                ];
            }
            if ($rf->rejected_at) {
                $events[] = [
                    'id' => "refund_rejected:{$rf->id}",
                    'type' => 'refund_rejected',
                    'title' => "Refund #{$rf->id} rejected",
                    'subtitle' => null,
                    'timestamp' => $rf->rejected_at->toIso8601String(),
                    'actor_name' => null,
                    'tone' => 'amber',
                    'href' => null,
                    'meta' => ['refund_id' => $rf->id],
                ];
            }
            if ($rf->paid_at) {
                $events[] = [
                    'id' => "refund_paid:{$rf->id}",
                    'type' => 'refund_paid',
                    'title' => "Refund #{$rf->id} paid",
                    'subtitle' => null,
                    'timestamp' => $rf->paid_at->toIso8601String(),
                    'actor_name' => null,
                    'tone' => 'emerald',
                    'href' => null,
                    'meta' => ['refund_id' => $rf->id],
                ];
            }
        }

        // 7. Customer addresses (C-4B). Only the *creation* event is
        //    emitted — the table stores the current row only, so we
        //    don't fabricate update/default-change history. The actor
        //    is the create-time `created_by`; updated_by is for
        //    audit-log lookups, not for timeline storytelling.
        $addressesForTimeline = \App\Models\CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->with('createdBy:id,name')
            ->latest('id')
            ->limit($cap)
            ->get(['id', 'customer_id', 'address', 'city', 'governorate', 'country', 'is_default', 'created_by', 'created_at']);

        foreach ($addressesForTimeline as $a) {
            $preview = trim((string) $a->address);
            if (mb_strlen($preview) > 80) {
                $preview = mb_substr($preview, 0, 80) . '…';
            }
            $cityLine = trim(implode(' · ', array_filter([$a->city, $a->governorate, $a->country])));
            $subtitle = $cityLine !== '' ? "{$cityLine} — {$preview}" : $preview;
            $events[] = [
                'id' => "customer_address_added:{$a->id}",
                'type' => 'customer_address_added',
                'title' => $a->is_default ? 'Default address added' : 'Address added',
                'subtitle' => $subtitle,
                'timestamp' => optional($a->created_at)->toIso8601String(),
                'actor_name' => optional($a->createdBy)->name,
                'tone' => 'default',
                'href' => null,
                'meta' => ['is_default' => (bool) $a->is_default],
            ];
        }

        // 6. Customer notes (C-4A). Indexed by (customer_id, created_at)
        //    so the per-customer scan is cheap. Note body is truncated
        //    to a preview for the timeline subtitle — full body lives
        //    only on the Notes panel.
        $notes = \App\Models\CustomerNote::query()
            ->where('customer_id', $customer->id)
            ->with('createdBy:id,name')
            ->latest('id')
            ->limit($cap)
            ->get(['id', 'customer_id', 'note', 'is_internal', 'created_by', 'created_at']);

        foreach ($notes as $n) {
            $preview = mb_substr((string) $n->note, 0, 80);
            if (mb_strlen((string) $n->note) > 80) {
                $preview .= '…';
            }
            $events[] = [
                'id' => "customer_note_added:{$n->id}",
                'type' => 'customer_note_added',
                'title' => $n->is_internal ? 'Internal note added' : 'External note added',
                'subtitle' => $preview,
                'timestamp' => optional($n->created_at)->toIso8601String(),
                'actor_name' => optional($n->createdBy)->name,
                'tone' => 'default',
                'href' => null,
                'meta' => ['is_internal' => (bool) $n->is_internal],
            ];
        }

        // Sort merged events by timestamp DESC. Events with null
        // timestamps (shouldn't happen — every source has indexed
        // timestamps) sort last.
        usort($events, function ($a, $b) {
            return strcmp((string) ($b['timestamp'] ?? ''), (string) ($a['timestamp'] ?? ''));
        });

        return array_slice($events, 0, $cap);
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
