<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerTag;
use App\Services\AuditLogService;
use App\Services\CustomerRiskService;
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
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'risk_level', 'customer_type']);

        $customers = Customer::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('primary_phone', 'like', "%{$term}%")
                        ->orWhere('secondary_phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
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
        return Inertia::render('Customers/Create');
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? [];
        unset($data['tags']);

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

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', 'Customer created.');
    }

    public function show(Customer $customer): Response
    {
        $customer->load([
            'tags',
            'addresses',
            'orders' => fn ($q) => $q->latest('id')->limit(20),
        ]);

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'risk_breakdown' => $this->riskService->calculate($customer),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $customer->load('tags');

        return Inertia::render('Customers/Edit', [
            'customer' => $customer,
            'tags' => $customer->tags->pluck('tag'),
        ]);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): RedirectResponse
    {
        $data = $request->validated();
        $tags = $data['tags'] ?? null; // null => leave alone
        unset($data['tags']);

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
