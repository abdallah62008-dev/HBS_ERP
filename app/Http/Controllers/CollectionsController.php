<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\ShippingCompany;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CollectionsController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['status', 'shipping_company_id', 'q']);

        $collections = Collection::query()
            ->with(['order:id,order_number,customer_name,total_amount', 'shippingCompany:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('collection_status', $v))
            ->when($filters['shipping_company_id'] ?? null, fn ($q, $v) => $q->where('shipping_company_id', $v))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereHas('order', fn ($o) => $o->where('order_number', 'like', "%{$term}%")
                    ->orWhere('customer_name', 'like', "%{$term}%"));
            })
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        $totals = [
            'pending_amount' => (float) Collection::whereIn('collection_status', ['Not Collected', 'Pending Settlement', 'Partially Collected'])
                ->sum('amount_due'),
            'collected_amount' => (float) Collection::whereIn('collection_status', ['Collected', 'Settlement Received'])
                ->sum('amount_collected'),
        ];

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'filters' => $filters,
            'companies' => ShippingCompany::orderBy('name')->get(['id', 'name']),
            'totals' => $totals,
        ]);
    }

    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $data = $request->validate([
            'collection_status' => ['required', 'in:'.implode(',', Collection::STATUSES)],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'settlement_reference' => ['nullable', 'string', 'max:128'],
            'settlement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $collection->fill([
            ...$data,
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($collection, 'updated', 'collections');

        return back()->with('success', 'Collection updated.');
    }
}
