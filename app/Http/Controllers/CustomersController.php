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

        // M4 Must-Fix: when this customer is a merged tombstone, the
        // stats query would return all zeros (records were reassigned
        // away). Pivot to the merge-time snapshot from the latest
        // `customer_merges` row so operators see the historical scale
        // of the merged record, not a misleading 0/0/0.
        if ($customer->merged_into_customer_id) {
            $stats = $this->customerStatsFromMergeLog($customer);
        } else {
            $stats = $this->customerStats($customer);
        }

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

            // C-5B: merge tombstone payload. Non-null only when this
            // customer was merged INTO another. The Show page renders
            // a banner pointing operators to the surviving customer.
            'merge_tombstone' => $customer->merged_into_customer_id ? [
                'merged_into_customer_id' => (int) $customer->merged_into_customer_id,
                'merged_into_customer_name' => optional($customer->mergedInto)->name,
                'merged_at' => optional($customer->merged_at)->toIso8601String(),
                'merged_by_name' => optional($customer->mergedBy)->name,
            ] : null,
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
        // M1: block note creation on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
        // M1: block note deletion on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
        // M1: block address creation on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
        // M1: block address edits on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
        // M1: block default changes on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
        // M1: block address removal on merged tombstones.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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

    /* ──────────────────── C-5B Must-Fix: merge guards ──────────────────── */

    /**
     * M1: Reject mutating actions on a customer that was merged away.
     *
     * A merged-away customer is a tombstone — the surviving customer
     * is the canonical record. New notes / addresses / orders against
     * the tombstone would silently drift from audit reality.
     *
     * Returns a redirect when the caller is on a merged customer;
     * null otherwise. Caller pattern:
     *
     *     if ($redirect = $this->blockIfMerged($customer)) return $redirect;
     */
    private function blockIfMerged(Customer $customer): ?RedirectResponse
    {
        if (! $customer->merged_into_customer_id) {
            return null;
        }
        $target = $customer->merged_into_customer_id;
        AuditLogService::log(
            action: 'write_blocked_merged_source',
            module: 'customers',
            recordType: Customer::class,
            recordId: $customer->id,
            newValues: [
                'merged_into_customer_id' => $target,
                'attempted_url' => request()->fullUrl(),
                'method' => request()->method(),
            ],
        );
        return redirect()
            ->route('customers.show', $target)
            ->with('error', "Customer #{$customer->id} was merged into Customer #{$target}. Continue on the surviving customer.");
    }

    /* ──────────────────── C-5A: Duplicate Merge Preview ──────────────────── */

    /**
     * Active-order statuses for the "source has active orders" warning.
     * Pinned as a class constant so the warning logic and any future
     * tests reference the same list (avoids drift when the order
     * lifecycle is extended).
     */
    private const PREVIEW_ACTIVE_ORDER_STATUSES = [
        'Pending Confirmation', 'Confirmed', 'Ready to Pack', 'Packed',
        'Ready to Ship', 'Shipped', 'Out for Delivery',
    ];

    /** Return statuses considered open (operator action required). */
    private const PREVIEW_OPEN_RETURN_STATUSES = ['Pending', 'Received', 'Inspected'];

    /** Refund statuses considered open (workflow not yet terminal). */
    private const PREVIEW_OPEN_REFUND_STATUSES = ['requested', 'approved'];

    /**
     * C-5A: render a read-only side-by-side merge preview for two
     * customers flagged as duplicates by the C-2 alert.
     *
     * Pure read path. NEVER writes to any table — verified by the
     * `preview_does_not_write_anything` test that counts every relevant
     * table before and after the GET.
     *
     * Validation surfaces:
     *   - source.id !== target.id (422 — "Source and target must differ.")
     *   - neither row is soft-deleted (the route binding uses the
     *     default {customer} model which already excludes soft-deleted
     *     rows; the explicit guard belt-and-braces against future
     *     changes that switch to `withTrashed()`).
     *
     * `merged_into_customer_id` validation is intentionally skipped —
     * that column doesn't exist yet (C-5B introduces it). When it
     * lands, extend the guards.
     */
    public function previewDuplicateMerge(Customer $source, Customer $target, Request $request): Response|RedirectResponse
    {
        if ((int) $source->id === (int) $target->id) {
            return redirect()
                ->route('customers.show', $source)
                ->with('error', 'Source and target must differ.');
        }
        if ($source->trashed() || $target->trashed()) {
            return redirect()
                ->route('customers.index')
                ->with('error', 'One of the customers has been deleted.');
        }
        // C-5B: redirect away from previews that target an already-
        // merged customer (the source row would just be a tombstone).
        if ($source->merged_into_customer_id || $target->merged_into_customer_id) {
            return redirect()
                ->route('customers.show', $target->merged_into_customer_id ? $target->merged_into_customer_id : $target)
                ->with('error', 'One of the customers has already been merged.');
        }

        $sourceSummary = $this->slimCustomerSummary($source);
        $targetSummary = $this->slimCustomerSummary($target);

        // Affected-record counts. Each side gets one query per table —
        // all customer_id columns are indexed so the cost is small. We
        // use COUNT (not eager-load) to keep the payload tiny.
        $affectedRecords = [
            'source' => $this->customerRelatedCounts($source),
            'target' => $this->customerRelatedCounts($target),
        ];

        // Conflicts: per-field policy summary. See C-5 review §4 for
        // the canonical policy table.
        $conflicts = $this->buildMergeConflicts($source, $target);

        // Recommended-target heuristic: more orders, then older
        // `created_at` (more history → more authoritative). Falls back
        // to the URL `target` parameter on a tie.
        $recommendedTargetId = $this->recommendMergeTarget($source, $target);

        // Warnings — read-only safety flags. Never block in C-5A
        // because there is no execute action.
        $warnings = $this->buildMergeWarnings($source, $target);

        // C-5B: surface the merge gate so the page can render the
        // execute form. Cross-phone merges escalate to super-admin
        // only — flagged separately so the UI can disable the form
        // for non-super-admin operators.
        //
        // M3a Must-Fix: the feature flag is the master switch — when
        // off, the page renders an information banner and the execute
        // form stays out of reach. Default false ships the workflow
        // dark; ops enables via SettingsService.
        $user = $request->user();
        $featureEnabled = (bool) \App\Services\SettingsService::get('customer_merge_enabled', false);
        $canMerge = (bool) $user?->hasPermission('customers.merge');
        $isSuperAdmin = (bool) $user?->isSuperAdmin();
        $crossPhone = $source->normalized_phone && $target->normalized_phone
            && $source->normalized_phone !== $target->normalized_phone;
        $wrongDirection = $recommendedTargetId !== $target->id;
        $canExecute = $featureEnabled && $canMerge && (! $crossPhone || $isSuperAdmin);

        // M3a + M5: assemble a single "blocked reason" string so the
        // UI doesn't have to know about the priority order.
        $blockedReason = null;
        if (! $featureEnabled) {
            $blockedReason = 'Customer merge workflow is currently disabled.';
        } elseif ($canMerge && $crossPhone && ! $isSuperAdmin) {
            $blockedReason = 'Cross-phone merge requires super-admin.';
        }

        return Inertia::render('Customers/MergePreview', [
            'source' => $sourceSummary,
            'target' => $targetSummary,
            'affected_records' => $affectedRecords,
            'conflicts' => $conflicts,
            'recommended_target_id' => $recommendedTargetId,
            'warnings' => $warnings,
            // Swap navigation target.
            'swap_url' => route('customers.duplicates.preview', [
                'source' => $target->id, 'target' => $source->id,
            ]),
            // C-5B execute gate.
            'can_merge' => $canMerge,
            'can_execute_merge' => $canExecute,
            'merge_blocked_reason' => $blockedReason,
            'feature_enabled' => $featureEnabled,
            // M5 Must-Fix: surface wrong-direction state so the UI
            // can render the amber warning + an explicit acknowledge
            // checkbox above the Execute button.
            'wrong_direction' => $wrongDirection,
            'execute_url' => route('customers.duplicates.merge', [
                'source' => $source->id, 'target' => $target->id,
            ]),
        ]);
    }

    /**
     * C-5B: execute a duplicate merge.
     *
     * Permission `customers.merge` is enforced at the route layer.
     * This method validates input + delegates the entire reassignment
     * + audit-log + tombstone-marking to {@see CustomerMergeService}.
     *
     * Any service-side validation failure throws RuntimeException and
     * we surface it as a redirect-back with error. The transaction is
     * inside the service — partial states never escape.
     *
     * Post-merge: redirect to the target customer with a success flash
     * summarising the affected counts.
     */
    public function executeMerge(
        Request $request,
        Customer $source,
        Customer $target,
        \App\Services\CustomerMergeService $service,
    ): RedirectResponse {
        $user = $request->user();

        // M3a + M6 Must-Fix: enforce the feature flag at the
        // controller layer (the service repeats the check). Reject
        // attempts even reach the validator when the flag is off.
        if (! (bool) \App\Services\SettingsService::get('customer_merge_enabled', false)) {
            $this->auditMergeRejection($user, $source, $target, 'feature_flag_off');
            return back()->withErrors([
                'reason' => 'Customer merge workflow is currently disabled.',
            ])->withInput();
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'string'],
            // M5 Must-Fix: explicit acknowledgement when the operator
            // is merging away from the recommended survivor. Default
            // false; UI sets to "1" via the checkbox.
            'wrong_direction_ack' => ['nullable', 'string'],
        ]);

        if ($data['confirmation'] !== 'MERGE') {
            $this->auditMergeRejection($user, $source, $target, 'wrong_confirmation');
            return back()->withErrors([
                'confirmation' => 'Type MERGE exactly to confirm.',
            ])->withInput();
        }

        // M5 Must-Fix: if the recommended survivor is the source
        // (i.e. operator is merging the wrong direction), require
        // explicit acknowledgement.
        $recommendedTargetId = $this->recommendMergeTarget($source, $target);
        if ($recommendedTargetId !== $target->id
            && empty($data['wrong_direction_ack'])) {
            $this->auditMergeRejection($user, $source, $target, 'wrong_direction_unacknowledged');
            return back()->withErrors([
                'wrong_direction_ack' => "The recommended survivor is Customer #{$recommendedTargetId}, not #{$target->id}. Tick the acknowledgement box or swap source↔target before proceeding.",
            ])->withInput();
        }

        try {
            $merge = $service->merge($source, $target, [
                'reason' => $data['reason'],
                'actor_id' => $user?->id,
                'actor_is_super_admin' => (bool) $user?->isSuperAdmin(),
            ]);
        } catch (\RuntimeException $e) {
            // M6 Must-Fix: audit every rejection from the service.
            $reasonCode = $this->classifyMergeException($e);
            $this->auditMergeRejection($user, $source, $target, $reasonCode, $e->getMessage());
            return back()->withErrors([
                'reason' => $e->getMessage(),
            ])->withInput();
        }

        $summary = sprintf(
            'Merged customer #%d into #%d. Reassigned %d order(s), %d return(s), %d refund(s), %d note(s), %d address(es), %d tag(s).',
            $source->id, $target->id,
            $merge->affected_orders_count,
            $merge->affected_returns_count,
            $merge->affected_refunds_count,
            $merge->affected_notes_count,
            $merge->affected_addresses_count,
            $merge->affected_tags_count,
        );

        return redirect()
            ->route('customers.show', $target)
            ->with('success', $summary);
    }

    /**
     * M6 Must-Fix: write an audit_log row for a rejected merge
     * attempt. Code is one of: `feature_flag_off`,
     * `wrong_confirmation`, `wrong_direction_unacknowledged`,
     * `source_equals_target`, `soft_deleted`, `already_merged_source`,
     * `already_merged_target`, `short_reason`, `cross_phone_non_super_admin`,
     * `unknown`. Allows audit dashboards to detect probing or
     * recurring operator confusion.
     */
    private function auditMergeRejection(
        ?\App\Models\User $user,
        Customer $source,
        Customer $target,
        string $reasonCode,
        ?string $detail = null,
    ): void {
        AuditLogService::log(
            action: 'merge_rejected',
            module: 'customers',
            recordType: Customer::class,
            recordId: $source->id,
            newValues: [
                'source_customer_id' => $source->id,
                'target_customer_id' => $target->id,
                'actor_id' => $user?->id,
                'reason_code' => $reasonCode,
                'detail' => $detail,
            ],
        );
    }

    /**
     * Map a `CustomerMergeService` RuntimeException message into one of
     * the reason codes for the audit log. Pattern-matches the string
     * the service throws — keep in sync.
     */
    private function classifyMergeException(\RuntimeException $e): string
    {
        $msg = $e->getMessage();
        if (str_contains($msg, 'must differ')) return 'source_equals_target';
        if (str_contains($msg, 'soft-deleted')) return 'soft_deleted';
        if (str_contains($msg, 'Source customer has already')) return 'already_merged_source';
        if (str_contains($msg, 'Target customer has already')) return 'already_merged_target';
        if (str_contains($msg, 'reason must be at least')) return 'short_reason';
        if (str_contains($msg, 'requires super-admin')) return 'cross_phone_non_super_admin';
        if (str_contains($msg, 'workflow is disabled')) return 'feature_flag_off';
        return 'unknown';
    }

    /**
     * @return array<string,mixed>
     */
    private function slimCustomerSummary(Customer $c): array
    {
        $latestOrderDate = \App\Models\Order::query()
            ->where('customer_id', $c->id)
            ->max('created_at');

        return [
            'id' => (int) $c->id,
            'name' => $c->name,
            'primary_phone' => $c->primary_phone,
            'secondary_phone' => $c->secondary_phone,
            'normalized_phone' => $c->normalized_phone,
            'email' => $c->email,
            'country' => $c->country,
            'governorate' => $c->governorate,
            'city' => $c->city,
            'default_address' => $c->default_address,
            'customer_type' => $c->customer_type,
            'risk_level' => $c->risk_level,
            'risk_score' => (int) $c->risk_score,
            'primary_phone_whatsapp' => (bool) ($c->primary_phone_whatsapp ?? true),
            'created_at' => optional($c->created_at)->toIso8601String(),
            'orders_count' => (int) $c->orders()->count(),
            'latest_order_date' => $latestOrderDate
                ? \Illuminate\Support\Carbon::parse($latestOrderDate)->toIso8601String()
                : null,
        ];
    }

    /**
     * @return array<string,int>
     */
    private function customerRelatedCounts(Customer $c): array
    {
        return [
            'orders' => (int) \App\Models\Order::where('customer_id', $c->id)->count(),
            'returns' => (int) \App\Models\OrderReturn::where('customer_id', $c->id)->count(),
            'refunds' => (int) \App\Models\Refund::where('customer_id', $c->id)->count(),
            'customer_notes' => (int) \App\Models\CustomerNote::where('customer_id', $c->id)->count(),
            'customer_addresses' => (int) \App\Models\CustomerAddress::where('customer_id', $c->id)->count(),
            'customer_tags' => (int) \App\Models\CustomerTag::where('customer_id', $c->id)->count(),
        ];
    }

    /**
     * Per-field conflict descriptor for the preview's conflict strip.
     *
     * "Conflict" means BOTH sides have a non-null value AND the values
     * differ. Same-value fields are silent. Null-on-one-side fields are
     * also silent — the policy is "copy when target's slot is empty",
     * which the UI explains globally without per-row noise.
     *
     * @return array<int, array{field:string, source_value:?string, target_value:?string, policy:string}>
     */
    private function buildMergeConflicts(Customer $source, Customer $target): array
    {
        $fields = [
            'name' => 'Keep target name. Source name will be preserved in the merge log (and as a customer note in C-5B).',
            'primary_phone' => 'Keep target phone. Source phone preserved in merge log.',
            'normalized_phone' => 'Different normalized phones flagged as HIGH RISK — super-admin confirmation will be required in C-5B.',
            'secondary_phone' => 'Keep target. If target had none, source value will be copied in C-5B.',
            'email' => 'Keep target. If target had none, source value will be copied in C-5B.',
            'country' => 'Keep target.',
            'governorate' => 'Keep target.',
            'city' => 'Keep target.',
            'default_address' => 'Keep target default. Source address moved into target address book in C-5B.',
            'customer_type' => 'Take the more-restrictive value (Blacklist > Watchlist > VIP > Normal).',
            'risk_level' => 'Take the higher value (High > Medium > Low). Risk score recomputed from orders post-merge.',
            'notes' => 'Source legacy notes appended to target as a structured customer_notes row in C-5B.',
        ];

        $out = [];
        foreach ($fields as $field => $policy) {
            $columnOnModel = $field === 'notes' ? 'notes' : $field;
            $s = $source->{$columnOnModel};
            $t = $target->{$columnOnModel};

            // Treat null and empty-string as the same "no value".
            $sEmpty = $s === null || $s === '';
            $tEmpty = $t === null || $t === '';
            if ($sEmpty || $tEmpty) continue;
            if ((string) $s === (string) $t) continue;

            $out[] = [
                'field' => $field === 'notes' ? 'legacy_notes' : $field,
                'source_value' => (string) $s,
                'target_value' => (string) $t,
                'policy' => $policy,
            ];
        }
        return $out;
    }

    private function recommendMergeTarget(Customer $source, Customer $target): int
    {
        $sourceOrders = (int) $source->orders()->count();
        $targetOrders = (int) $target->orders()->count();
        if ($sourceOrders !== $targetOrders) {
            return $sourceOrders > $targetOrders ? $source->id : $target->id;
        }
        // Older customer wins on tiebreak (more history → more
        // authoritative). When timestamps tie too, the URL target wins.
        $sourceCreated = $source->created_at?->getTimestamp() ?? PHP_INT_MAX;
        $targetCreated = $target->created_at?->getTimestamp() ?? PHP_INT_MAX;
        if ($sourceCreated < $targetCreated) return $source->id;
        return $target->id;
    }

    /**
     * Build the warnings array for the preview. Each warning is a
     * read-only flag — the page renders them but never blocks. C-5B
     * will translate the same set into block / require-super-admin
     * decisions on execute.
     *
     * @return array<int, array{type:string, severity:string, message:string}>
     */
    private function buildMergeWarnings(Customer $source, Customer $target): array
    {
        $warnings = [];

        // 1. Different normalized phones.
        if ($source->normalized_phone && $target->normalized_phone
            && $source->normalized_phone !== $target->normalized_phone) {
            $warnings[] = [
                'type' => 'cross_phone',
                'severity' => 'high',
                'message' => "Source and target have different normalized phones ({$source->normalized_phone} vs {$target->normalized_phone}). C-5B will require super-admin confirmation.",
            ];
        }

        // 2. Source has active orders.
        $activeOrdersCount = \App\Models\Order::query()
            ->where('customer_id', $source->id)
            ->whereIn('status', self::PREVIEW_ACTIVE_ORDER_STATUSES)
            ->count();
        if ($activeOrdersCount > 0) {
            $warnings[] = [
                'type' => 'source_active_orders',
                'severity' => 'medium',
                'message' => "Source has {$activeOrdersCount} active order(s) that will be reassigned to target.",
            ];
        }

        // 3. Source has unpaid COD / outstanding balance.
        $outstandingOrders = \App\Models\Order::query()
            ->where('customer_id', $source->id)
            ->where('cod_amount', '>', 0)
            ->whereIn('collection_status', ['Not Collected', 'Partially Collected', 'Pending Settlement', 'Rejected'])
            ->count();
        if ($outstandingOrders > 0) {
            $warnings[] = [
                'type' => 'source_outstanding_balance',
                'severity' => 'medium',
                'message' => "Source has {$outstandingOrders} order(s) with open COD balance. The balance follows the merge.",
            ];
        }

        // 4. Source has open returns.
        $openReturns = \App\Models\OrderReturn::query()
            ->where('customer_id', $source->id)
            ->whereIn('return_status', self::PREVIEW_OPEN_RETURN_STATUSES)
            ->count();
        if ($openReturns > 0) {
            $warnings[] = [
                'type' => 'source_open_returns',
                'severity' => 'medium',
                'message' => "Source has {$openReturns} open return(s).",
            ];
        }

        // 5. Source has open refunds.
        $openRefunds = \App\Models\Refund::query()
            ->where('customer_id', $source->id)
            ->whereIn('status', self::PREVIEW_OPEN_REFUND_STATUSES)
            ->count();
        if ($openRefunds > 0) {
            $warnings[] = [
                'type' => 'source_open_refunds',
                'severity' => 'medium',
                'message' => "Source has {$openRefunds} open refund request(s).",
            ];
        }

        // 6. Target high risk / restricted.
        if ($target->risk_level === 'High') {
            $warnings[] = [
                'type' => 'target_high_risk',
                'severity' => 'medium',
                'message' => 'Target customer is flagged HIGH risk. Review before merging.',
            ];
        }
        if (in_array($target->customer_type, ['Blacklist', 'Watchlist'], true)) {
            $warnings[] = [
                'type' => 'target_restricted_type',
                'severity' => 'high',
                'message' => "Target customer type is {$target->customer_type}.",
            ];
        }

        return $warnings;
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
     * M4 Must-Fix: build the C-2 stats payload for a merged tombstone
     * from the latest `customer_merges` row instead of live queries.
     *
     * Live queries on a merged source return 0/0/0 because every
     * related record was reassigned to the surviving customer — which
     * is correct but misleading on the operator-facing page. The
     * merge log preserved the at-time-of-merge counts; we expose them
     * with an explicit `from_merge_log` flag the UI can use to swap
     * card titles to "Orders at time of merge" and hide cards we
     * can't reconstruct (delivered/returned split, COD math, AOV).
     *
     * @return array<string,mixed>
     */
    private function customerStatsFromMergeLog(Customer $customer): array
    {
        /** @var ?\App\Models\CustomerMerge $merge */
        $merge = $customer->sourceMerges()->latest('id')->first();
        if (! $merge) {
            // Tombstone without a log row — shouldn't happen, but fall
            // back to live stats so the page still renders.
            return $this->customerStats($customer);
        }
        return [
            'total_orders' => (int) $merge->affected_orders_count,
            'delivered_orders' => null,
            'returned_orders' => (int) $merge->affected_returns_count,
            'cancelled_orders' => null,
            'total_spent' => null,
            'outstanding_balance' => null,
            'cod_orders' => null,
            'cod_collected_orders' => null,
            'cod_success_rate' => null,
            'return_rate' => null,
            'average_order_value' => null,
            'last_order_at' => null,
            'from_merge_log' => true,
            'merge_id' => (int) $merge->id,
            'merge_at' => optional($merge->created_at)->toIso8601String(),
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
            // C-5B: hide rows that were already merged away — they'd
            // otherwise resurface as "duplicates" of new customers
            // that share the same normalized phone.
            ->whereNull('merged_into_customer_id')
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

        // 8. Customer merges (C-5B). Emit one `customer_merged_in`
        //    event for every merge where this customer was the target,
        //    and one `customer_merged_out` event for the source-side
        //    timeline. Both go through `customer_merges` indexed on
        //    the relevant FK so the per-customer scan is cheap.
        $merges = \App\Models\CustomerMerge::query()
            ->where(function ($q) use ($customer) {
                $q->where('target_customer_id', $customer->id)
                    ->orWhere('source_customer_id', $customer->id);
            })
            ->with(['mergedBy:id,name', 'sourceCustomer:id,name', 'targetCustomer:id,name'])
            ->latest('id')
            ->limit($cap)
            ->get();

        foreach ($merges as $m) {
            $isTarget = (int) $m->target_customer_id === (int) $customer->id;
            $other = $isTarget ? $m->sourceCustomer : $m->targetCustomer;
            $otherName = $other?->name ?? ('Customer #' . ($isTarget ? $m->source_customer_id : $m->target_customer_id));
            $events[] = [
                'id' => ($isTarget ? 'customer_merged_in:' : 'customer_merged_out:') . $m->id,
                'type' => $isTarget ? 'customer_merged_in' : 'customer_merged_out',
                'title' => $isTarget
                    ? "Merged from {$otherName}"
                    : "Merged into {$otherName}",
                'subtitle' => sprintf(
                    '%d order(s), %d return(s), %d refund(s) reassigned',
                    $m->affected_orders_count,
                    $m->affected_returns_count,
                    $m->affected_refunds_count,
                ),
                'timestamp' => optional($m->created_at)->toIso8601String(),
                'actor_name' => optional($m->mergedBy)->name,
                'tone' => 'slate',
                // Link to the OTHER side of the merge so the operator
                // can navigate the merge graph.
                'href' => $other ? route('customers.show', $other->id) : null,
                'meta' => ['merge_id' => $m->id, 'is_target' => $isTarget],
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
        // M1 (C-5B must-fix): block edits to a merged-away customer.
        if ($redirect = $this->blockIfMerged($customer)) return $redirect;

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
