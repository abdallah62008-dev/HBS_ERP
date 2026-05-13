<?php

namespace App\Http\Controllers;

use App\Http\Requests\CashboxRequest;
use App\Models\Cashbox;
use App\Services\CashboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance Phase 1 — Cashboxes UI controller.
 *
 * Routes are gated per-action via the `permission:` middleware in
 * routes/web.php. This controller does NOT define a destroy() method
 * — by design, cashboxes are retired via deactivate(), not deleted.
 *
 * The Statement page is rendered by `show()` so URL `/cashboxes/{id}`
 * reads naturally as "view the cashbox", which in practice means its
 * statement.
 */
class CashboxesController extends Controller
{
    public function __construct(private CashboxService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'type', 'status']);

        $cashboxes = Cashbox::query()
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->ofType($filters['type'] ?? null)
            ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency_code', 'opening_balance', 'is_active'])
            ->map(fn (Cashbox $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'currency_code' => $c->currency_code,
                'opening_balance' => (float) $c->opening_balance,
                'balance' => $c->balance(),
                'is_active' => $c->is_active,
            ])
            ->values()
            ->all();

        return Inertia::render('Cashboxes/Index', [
            'cashboxes' => $cashboxes,
            'filters' => $filters,
            'types' => Cashbox::TYPES,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Cashboxes/Create', [
            'types' => Cashbox::TYPES,
        ]);
    }

    public function store(CashboxRequest $request): RedirectResponse
    {
        $cashbox = $this->service->createCashbox($request->validated());

        return redirect()
            ->route('cashboxes.index')
            ->with('success', 'Cashbox created.');
    }

    /**
     * Statement page. Lists every transaction with date-range, direction,
     * and source_type filters. The "balance" shown is computed live from
     * the transactions of this cashbox.
     */
    public function show(Request $request, Cashbox $cashbox): Response
    {
        $filters = $request->only(['from', 'to', 'direction', 'source_type']);

        $transactions = $cashbox->transactions()
            ->with('createdBy:id,name')
            ->between($filters['from'] ?? null, $filters['to'] ?? null)
            ->direction($filters['direction'] ?? null)
            ->ofSourceType($filters['source_type'] ?? null)
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Cashboxes/Statement', [
            'cashbox' => [
                'id' => $cashbox->id,
                'name' => $cashbox->name,
                'type' => $cashbox->type,
                'currency_code' => $cashbox->currency_code,
                'opening_balance' => (float) $cashbox->opening_balance,
                'allow_negative_balance' => $cashbox->allow_negative_balance,
                'is_active' => $cashbox->is_active,
                'description' => $cashbox->description,
                'balance' => $cashbox->balance(),
                'has_transactions' => $cashbox->hasTransactions(),
            ],
            'transactions' => $transactions->through(fn ($tx) => [
                'id' => $tx->id,
                'occurred_at' => $tx->occurred_at?->toDateTimeString(),
                'direction' => $tx->direction,
                'amount' => (float) $tx->amount,
                'source_type' => $tx->source_type,
                'source_id' => $tx->source_id,
                'notes' => $tx->notes,
                'created_by' => $tx->createdBy?->only(['id', 'name']),
            ]),
            'filters' => $filters,
            'phase1_source_types' => \App\Models\CashboxTransaction::PHASE_1_SOURCE_TYPES,
        ]);
    }

    public function edit(Cashbox $cashbox): Response
    {
        return Inertia::render('Cashboxes/Edit', [
            'cashbox' => [
                'id' => $cashbox->id,
                'name' => $cashbox->name,
                'type' => $cashbox->type,
                'currency_code' => $cashbox->currency_code,
                'opening_balance' => (float) $cashbox->opening_balance,
                'allow_negative_balance' => $cashbox->allow_negative_balance,
                'is_active' => $cashbox->is_active,
                'description' => $cashbox->description,
                'has_transactions' => $cashbox->hasTransactions(),
            ],
            'types' => Cashbox::TYPES,
        ]);
    }

    public function update(CashboxRequest $request, Cashbox $cashbox): RedirectResponse
    {
        $this->service->updateCashbox($cashbox, $request->validated());

        return redirect()
            ->route('cashboxes.index')
            ->with('success', 'Cashbox updated.');
    }

    public function deactivate(Cashbox $cashbox): RedirectResponse
    {
        $this->service->deactivateCashbox($cashbox);

        return back()->with('success', 'Cashbox deactivated.');
    }

    public function reactivate(Cashbox $cashbox): RedirectResponse
    {
        $this->service->reactivateCashbox($cashbox);

        return back()->with('success', 'Cashbox reactivated.');
    }

    /**
     * Create a manual adjustment transaction. Gated by
     * `cashbox_transactions.create` in routes/web.php.
     */
    public function storeTransaction(Request $request, Cashbox $cashbox): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', Rule::in(['in', 'out'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'notes' => ['required', 'string', 'min:1', 'max:500'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $this->service->createAdjustmentTransaction($cashbox, $data);

        return back()->with('success', 'Adjustment recorded.');
    }
}
