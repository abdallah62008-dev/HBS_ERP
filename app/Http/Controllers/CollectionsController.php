<?php

namespace App\Http\Controllers;

use App\Models\Cashbox;
use App\Models\Collection;
use App\Models\PaymentMethod;
use App\Models\ShippingCompany;
use App\Services\AuditLogService;
use App\Services\CollectionCashboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class CollectionsController extends Controller
{
    public function __construct(private CollectionCashboxService $cashboxService) {}

    public function index(Request $request): Response
    {
        $filters = $request->only([
            'status', 'shipping_company_id', 'q',
            'cashbox_id', 'payment_method_id', 'posted',
        ]);

        $collections = Collection::query()
            ->with([
                'order:id,order_number,customer_name,total_amount',
                'shippingCompany:id,name',
                'cashbox:id,name,currency_code,is_active',
                'paymentMethod:id,name,code,is_active',
            ])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('collection_status', $v))
            ->when($filters['shipping_company_id'] ?? null, fn ($q, $v) => $q->where('shipping_company_id', $v))
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->when(($filters['posted'] ?? null) === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
            ->when(($filters['posted'] ?? null) === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
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
            'posted_amount' => (float) Collection::posted()->sum('amount_collected'),
            'unposted_amount' => (float) Collection::unposted()
                ->whereIn('collection_status', ['Collected', 'Partially Collected', 'Settlement Received'])
                ->sum('amount_collected'),
        ];

        return Inertia::render('Collections/Index', [
            'collections' => $collections,
            'filters' => $filters,
            'companies' => ShippingCompany::orderBy('name')->get(['id', 'name']),
            'cashboxes' => Cashbox::orderBy('name')->get(['id', 'name', 'currency_code', 'is_active']),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code', 'is_active']),
            'totals' => $totals,
            'postable_statuses' => Collection::POSTABLE_STATUSES,
        ]);
    }

    /**
     * Update non-financial fields + optionally assign payment method
     * and cashbox. Does NOT post to the cashbox. Posting requires the
     * separate `postToCashbox` endpoint (different permission, explicit
     * action so the audit trail is unambiguous).
     */
    public function update(Request $request, Collection $collection): RedirectResponse
    {
        $data = $request->validate([
            'collection_status' => ['required', 'in:' . implode(',', Collection::STATUSES)],
            'amount_collected' => ['nullable', 'numeric', 'min:0'],
            'settlement_reference' => ['nullable', 'string', 'max:128'],
            'settlement_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            // Phase 3: optional assignment (does not post by itself).
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'cashbox_id' => ['nullable', 'integer', 'exists:cashboxes,id'],
        ]);

        // Once a collection is posted, every financial field becomes
        // read-only — those values describe a real ledger row. Editing
        // them now would drift the collection row from its
        // cashbox_transactions counterpart.
        // Reversal / correction belongs to a future refund / adjustment
        // phase; for now the operator must escalate.
        if ($collection->isPosted()) {
            unset(
                $data['cashbox_id'],
                $data['payment_method_id'],
                $data['amount_collected'],
                $data['settlement_date'],
                $data['settlement_reference'],
            );
        }

        $collection->fill([
            ...$data,
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($collection, 'updated', 'collections');

        return back()->with('success', 'Collection updated.');
    }

    /**
     * Post the collection's collected amount to the chosen cashbox.
     *
     * Service-layer guards prevent double-posting, inactive cashbox /
     * payment method, ineligible status, and zero amount. Failures are
     * surfaced as flash errors rather than 500s.
     */
    public function postToCashbox(Request $request, Collection $collection): RedirectResponse
    {
        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $this->cashboxService->postCollectionToCashbox($collection, $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Collection posted to cashbox.');
    }
}
