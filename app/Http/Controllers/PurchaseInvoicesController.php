<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseInvoiceRequest;
use App\Http\Requests\SupplierPaymentRequest;
use App\Models\Product;
use App\Models\PurchaseInvoice;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseInvoicesController extends Controller
{
    public function __construct(
        private readonly PurchaseInvoiceService $service,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status', 'supplier_id']);

        $invoices = PurchaseInvoice::query()
            ->with(['supplier:id,name', 'warehouse:id,name'])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('invoice_number', 'like', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->latest('invoice_date')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('PurchaseInvoices/Index', [
            'invoices' => $invoices,
            'filters' => $filters,
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PurchaseInvoices/Create', [
            'suppliers' => Supplier::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
        ]);
    }

    public function store(PurchaseInvoiceRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $items = $data['items'];
        unset($data['items']);

        $invoice = $this->service->createDraft($data, $items);

        return redirect()->route('purchase-invoices.show', $invoice)
            ->with('success', "Draft invoice {$invoice->invoice_number} created.");
    }

    public function show(PurchaseInvoice $purchaseInvoice): Response
    {
        $purchaseInvoice->load([
            'supplier', 'warehouse',
            'items.product:id,sku,name',
            'payments.createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return Inertia::render('PurchaseInvoices/Show', [
            'invoice' => $purchaseInvoice,
        ]);
    }

    public function edit(PurchaseInvoice $purchaseInvoice): Response
    {
        if (! $purchaseInvoice->isDraft()) {
            abort(403, 'Approved invoices cannot be edited without an approval request (Phase 8).');
        }

        $purchaseInvoice->load(['items.product:id,sku,name']);

        return Inertia::render('PurchaseInvoices/Edit', [
            'invoice' => $purchaseInvoice,
            'suppliers' => Supplier::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('status', 'Active')->orderBy('name')->get(['id', 'sku', 'name', 'cost_price']),
        ]);
    }

    public function update(PurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validated();
        $items = $data['items'] ?? null;
        unset($data['items']);

        $this->service->updateDraft($purchaseInvoice, $data, $items);

        return redirect()->route('purchase-invoices.show', $purchaseInvoice)
            ->with('success', 'Invoice updated.');
    }

    public function approve(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $this->service->approve($purchaseInvoice);

        return back()->with('success', "Invoice approved. Stock has been added to warehouse.");
    }

    public function destroy(PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        if (! $purchaseInvoice->isDraft()) {
            return back()->with('error', 'Only draft invoices can be deleted. Use the Cancel action for received invoices.');
        }

        $purchaseInvoice->update(['deleted_by' => Auth::id()]);
        $purchaseInvoice->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'purchases',
            recordType: PurchaseInvoice::class,
            recordId: $purchaseInvoice->id,
        );

        return redirect()->route('purchase-invoices.index')
            ->with('success', 'Draft invoice deleted.');
    }

    /**
     * Record a payment against an invoice (or the supplier's account if
     * no invoice is specified).
     */
    public function recordPayment(SupplierPaymentRequest $request, PurchaseInvoice $purchaseInvoice): RedirectResponse
    {
        $data = $request->validated();

        $this->service->recordPayment(
            invoice: $purchaseInvoice,
            amount: (float) $data['amount'],
            method: $data['payment_method'] ?? null,
            notes: $data['notes'] ?? null,
            paymentDate: $data['payment_date'] ?? null,
        );

        return back()->with('success', 'Payment recorded.');
    }
}
