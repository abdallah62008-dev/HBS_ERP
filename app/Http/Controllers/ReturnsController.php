<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderReturnRequest;
use App\Http\Requests\ReturnInspectRequest;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Services\RefundService;
use App\Services\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ReturnsController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
        private readonly RefundService $refunds,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'q']);

        $returnsList = OrderReturn::query()
            ->with([
                'order:id,order_number,customer_name,customer_phone,total_amount',
                'returnReason:id,name',
                'inspectedBy:id,name',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('return_status', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%"));
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Returns/Index', [
            'returns' => $returnsList,
            'filters' => $filters,
            'reasons' => ReturnReason::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        // Allow ?order_id=X for "create return for this order" workflow.
        // If that order already has a return, the validation rule on
        // OrderReturnRequest will reject the submission — but the UI
        // shouldn't even surface it as a candidate. We pre-resolve the
        // preselected order *only if* it has no existing return.
        $order = null;
        $alreadyReturnedNotice = null;
        if ($request->filled('order_id')) {
            $candidate = Order::with('customer:id,name,primary_phone')->find($request->order_id);
            if ($candidate) {
                if ($candidate->returns()->exists()) {
                    $alreadyReturnedNotice = "Order {$candidate->order_number} already has a return record and cannot be returned again.";
                } else {
                    $order = $candidate;
                }
            }
        }

        return Inertia::render('Returns/Create', [
            'preselected_order' => $order,
            'already_returned_notice' => $alreadyReturnedNotice,
            // Exclude orders that already have a return from the
            // dropdown. Backend validation will also reject duplicates
            // (defense in depth) — see OrderReturnRequest::rules().
            'recent_orders' => $order ? null : Order::query()
                ->whereIn('status', ['Shipped', 'Delivered', 'Returned'])
                ->whereDoesntHave('returns')
                ->with('customer:id,name')
                ->latest('id')->limit(50)
                ->get(['id', 'order_number', 'customer_name', 'status']),
            'reasons' => ReturnReason::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(OrderReturnRequest $request): RedirectResponse
    {
        try {
            $return = $this->returns->open($request->validated());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('returns.show', $return)->with('success', 'Return opened.');
    }

    public function show(OrderReturn $return): Response
    {
        $return->load([
            'order.items',
            'order.customer',
            'returnReason',
            'shippingCompany:id,name',
            'inspectedBy:id,name',
            // Phase 5C — surface refunds linked to this return so the
            // UI can show their lifecycle status alongside the return.
            'refunds' => fn ($q) => $q->with('requestedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name', 'paidBy:id,name')
                ->orderBy('id'),
        ]);

        // Compute eligibility + remaining refundable amount so the UI
        // can decide whether to surface the "Request refund" action.
        $activeRefundsTotal = (float) $return->refunds()
            ->whereIn('status', Refund::ACTIVE_STATUSES)
            ->sum('amount');
        $refundable = max(0.0, (float) $return->refund_amount - $activeRefundsTotal);

        return Inertia::render('Returns/Show', [
            'return' => $return,
            'reasons' => ReturnReason::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            // Phase 5C — refund context for the show page.
            'refund_context' => [
                'can_request_refund' => $return->canRequestRefund() && $refundable > 0,
                'eligible_statuses' => OrderReturn::REFUND_ELIGIBLE_STATUSES,
                'refundable_amount' => $refundable,
                'active_refund_total' => $activeRefundsTotal,
                'refund_base_amount' => (float) $return->refund_amount,
            ],
        ]);
    }

    /**
     * Phase 5C — create a `requested` refund linked to this return.
     *
     * Always creates the refund in `requested` status. Approval and
     * payment continue to flow through the existing Refund module
     * (`refunds.approve` then `refunds.pay`). No cashbox transaction
     * is created here.
     *
     * Permission: `refunds.create` (reused; no new slug introduced).
     */
    public function requestRefund(Request $request, OrderReturn $return): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $refund = $this->refunds->createFromReturn($return, $request->user(), $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('refunds.index')
            ->with('success', "Refund #{$refund->id} requested for return #{$return->id}.");
    }

    public function inspect(ReturnInspectRequest $request, OrderReturn $return): RedirectResponse
    {
        $data = $request->validated();
        try {
            $this->returns->inspect(
                return: $return,
                condition: $data['product_condition'],
                restockable: (bool) $data['restockable'],
                refundAmount: $data['refund_amount'] ?? null,
                notes: $data['notes'] ?? null,
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Return inspected.');
    }

    public function close(Request $request, OrderReturn $return): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->returns->close($return, $data['note'] ?? null);

        return back()->with('success', 'Return closed.');
    }
}
