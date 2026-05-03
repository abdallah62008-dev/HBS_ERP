<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class SuppliersController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status']);

        $suppliers = Supplier::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->withCount(['purchaseInvoices', 'products'])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers,
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Suppliers/Create');
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($supplier, 'created', 'purchases');

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', 'Supplier created.');
    }

    public function show(Supplier $supplier): Response
    {
        $supplier->load([
            'purchaseInvoices' => fn ($q) => $q->latest('id')->limit(20),
            'payments' => fn ($q) => $q->latest('payment_date')->limit(20),
        ]);

        return Inertia::render('Suppliers/Show', [
            'supplier' => $supplier,
            'balance' => $supplier->balance(),
        ]);
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('Suppliers/Edit', [
            'supplier' => $supplier,
        ]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->fill([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($supplier, 'updated', 'purchases');

        return redirect()->route('suppliers.show', $supplier)
            ->with('success', 'Supplier updated.');
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $supplier->update(['deleted_by' => Auth::id()]);
        $supplier->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'purchases',
            recordType: Supplier::class,
            recordId: $supplier->id,
        );

        return redirect()->route('suppliers.index')->with('success', 'Supplier deleted.');
    }
}
