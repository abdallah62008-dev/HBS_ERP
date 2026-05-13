<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefundRequest;
use App\Models\Cashbox;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Services\AuditLogService;
use App\Services\RefundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5A — Refunds CRUD + approve / reject actions.
 *
 * Phase 5A is paperwork-only. NO `pay()` action and NO route to it —
 * Phase 5B owns the payment side and the cashbox OUT transaction.
 *
 * Edit / delete are gated to `requested` status only; the controller
 * surfaces a flash error if the user tries to mutate an approved or
 * rejected refund. The model has a defence-in-depth deleting hook
 * (see `Refund::booted()`).
 */
class RefundsController extends Controller
{
    public function __construct(private RefundService $service) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'q', 'collection_id', 'order_id', 'customer_id']);

        $refunds = Refund::query()
            ->with([
                'order:id,order_number,customer_name',
                'collection:id,order_id,amount_collected',
                'customer:id,name',
                'requestedBy:id,name',
                'approvedBy:id,name',
                'rejectedBy:id,name',
                // Phase 5B: surface payment metadata for paid refunds.
                'paidBy:id,name',
                'cashbox:id,name,currency_code',
                'paymentMethod:id,name,code',
            ])
            ->status($filters['status'] ?? null)
            ->when($filters['collection_id'] ?? null, fn ($q, $v) => $q->where('collection_id', $v))
            ->when($filters['order_id'] ?? null, fn ($q, $v) => $q->where('order_id', $v))
            ->when($filters['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('reason', 'like', "%{$term}%")
                      ->orWhereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")
                          ->orWhere('customer_name', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $totals = [
            'requested_count' => Refund::requested()->count(),
            'approved_count' => Refund::approved()->count(),
            'rejected_count' => Refund::rejected()->count(),
            'paid_count' => Refund::where('status', Refund::STATUS_PAID)->count(),
            'requested_amount' => (float) Refund::requested()->sum('amount'),
            'approved_amount' => (float) Refund::approved()->sum('amount'),
            'rejected_amount' => (float) Refund::rejected()->sum('amount'),
            'paid_amount' => (float) Refund::where('status', Refund::STATUS_PAID)->sum('amount'),
        ];

        return Inertia::render('Refunds/Index', [
            'refunds' => $refunds,
            'filters' => $filters,
            'totals' => $totals,
            'statuses' => Refund::STATUSES,
            // Phase 5B — Pay form needs the active cashboxes (with live
            // balance so the UI can warn before submit) + payment methods.
            'cashboxes' => Cashbox::active()
                ->orderBy('name')
                ->get(['id', 'name', 'currency_code', 'allow_negative_balance'])
                ->map(fn (Cashbox $c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'currency_code' => $c->currency_code,
                    'allow_negative_balance' => $c->allow_negative_balance,
                    'balance' => $c->balance(),
                ])
                ->all(),
            'payment_methods' => PaymentMethod::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Refunds/Create');
    }

    public function store(RefundRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Over-refund guard up-front so the operator gets a clear error
        // before the row is inserted. Both the collection-level guard
        // (Phase 5A) and the return-level guard (Phase 5C) run when
        // their respective linkage IDs are present.
        try {
            $this->service->assertRefundableAmount(
                excludeRefundId: null,
                collectionId: isset($data['collection_id']) ? (int) $data['collection_id'] : null,
                proposedAmount: (float) $data['amount'],
            );
            $this->service->assertReturnRefundableAmount(
                excludeRefundId: null,
                orderReturnId: isset($data['order_return_id']) ? (int) $data['order_return_id'] : null,
                proposedAmount: (float) $data['amount'],
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $refund = Refund::create([
            ...$data,
            'status' => Refund::STATUS_REQUESTED,
            'requested_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($refund, 'refund_created', RefundService::MODULE);

        return redirect()->route('refunds.index')->with('success', 'Refund requested.');
    }

    public function edit(Refund $refund): Response|RedirectResponse
    {
        if (! $refund->canBeEdited()) {
            return redirect()
                ->route('refunds.index')
                ->with('error', "Refund #{$refund->id} cannot be edited (status: {$refund->status}).");
        }

        return Inertia::render('Refunds/Edit', [
            'refund' => $refund->load([
                'order:id,order_number',
                'collection:id,order_id,amount_collected',
                'customer:id,name',
            ]),
        ]);
    }

    public function update(RefundRequest $request, Refund $refund): RedirectResponse
    {
        if (! $refund->canBeEdited()) {
            return back()->with(
                'error',
                "Refund #{$refund->id} cannot be edited (status: {$refund->status})."
            );
        }

        $data = $request->validated();

        // Re-run the over-refund guards for the new amount, excluding
        // this refund from the existing-sum so we don't double-count.
        try {
            $this->service->assertRefundableAmount(
                excludeRefundId: $refund->id,
                collectionId: isset($data['collection_id']) ? (int) $data['collection_id'] : null,
                proposedAmount: (float) $data['amount'],
            );
            $this->service->assertReturnRefundableAmount(
                excludeRefundId: $refund->id,
                orderReturnId: isset($data['order_return_id']) ? (int) $data['order_return_id'] : null,
                proposedAmount: (float) $data['amount'],
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $refund->fill($data)->save();
        AuditLogService::logModelChange($refund, 'refund_updated', RefundService::MODULE);

        return redirect()->route('refunds.index')->with('success', 'Refund updated.');
    }

    public function destroy(Refund $refund): RedirectResponse
    {
        if (! $refund->canBeDeleted()) {
            return back()->with(
                'error',
                "Refund #{$refund->id} cannot be deleted (status: {$refund->status})."
            );
        }

        $id = $refund->id;
        $refund->delete();
        AuditLogService::log(
            action: 'refund_deleted',
            module: RefundService::MODULE,
            recordType: Refund::class,
            recordId: $id,
        );

        return redirect()->route('refunds.index')->with('success', 'Refund deleted.');
    }

    public function approve(Request $request, Refund $refund): RedirectResponse
    {
        try {
            $this->service->approve($refund, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Refund approved.');
    }

    public function reject(Request $request, Refund $refund): RedirectResponse
    {
        try {
            $this->service->reject($refund, $request->user());
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Refund rejected.');
    }

    /**
     * Phase 5B — pay an approved refund.
     *
     * Writes a cashbox OUT transaction and marks the refund `paid`.
     * Gated by `refunds.pay` permission in the route. Service-layer
     * exceptions (overdraft, double-pay race, inactive cashbox /
     * payment method, ineligible status, over-refund) surface as
     * flash errors rather than 500s.
     */
    public function pay(Request $request, Refund $refund): RedirectResponse
    {
        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $this->service->pay($refund, $request->user(), $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Refund paid from cashbox.');
    }
}
