<?php

namespace App\Http\Controllers;

use App\Http\Requests\RefundRequest;
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
            'requested_amount' => (float) Refund::requested()->sum('amount'),
            'approved_amount' => (float) Refund::approved()->sum('amount'),
            'rejected_amount' => (float) Refund::rejected()->sum('amount'),
        ];

        return Inertia::render('Refunds/Index', [
            'refunds' => $refunds,
            'filters' => $filters,
            'totals' => $totals,
            'statuses' => Refund::STATUSES,
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
        // before the row is inserted.
        try {
            $this->service->assertRefundableAmount(
                excludeRefundId: null,
                collectionId: isset($data['collection_id']) ? (int) $data['collection_id'] : null,
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

        // Re-run the over-refund guard for the new amount, excluding
        // this refund from the existing-sum so we don't double-count.
        try {
            $this->service->assertRefundableAmount(
                excludeRefundId: $refund->id,
                collectionId: isset($data['collection_id']) ? (int) $data['collection_id'] : null,
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
}
