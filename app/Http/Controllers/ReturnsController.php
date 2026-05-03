<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderReturnRequest;
use App\Http\Requests\ReturnInspectRequest;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\ReturnReason;
use App\Services\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ReturnsController extends Controller
{
    public function __construct(
        private readonly ReturnService $returns,
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
        $order = null;
        if ($request->filled('order_id')) {
            $order = Order::with('customer:id,name,primary_phone')->find($request->order_id);
        }

        return Inertia::render('Returns/Create', [
            'preselected_order' => $order,
            'recent_orders' => $order ? null : Order::query()
                ->whereIn('status', ['Shipped', 'Delivered', 'Returned'])
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
        ]);

        return Inertia::render('Returns/Show', [
            'return' => $return,
            'reasons' => ReturnReason::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
        ]);
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
