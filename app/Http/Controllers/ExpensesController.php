<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\AdCampaign;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ExpensesController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'category_id', 'from', 'to']);

        $expenses = Expense::query()
            ->with(['category:id,name', 'relatedOrder:id,order_number', 'relatedCampaign:id,name', 'createdBy:id,name'])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('title', 'like', "%{$term}%")->orWhere('notes', 'like', "%{$term}%");
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->latest('expense_date')
            ->paginate(30)
            ->withQueryString();

        $totalAmount = (float) Expense::query()
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->sum('amount');

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'total_amount' => $totalAmount,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Expenses/Create', [
            'categories' => ExpenseCategory::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'campaigns' => AdCampaign::where('status', '!=', 'Ended')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = Expense::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($expense, 'created', 'expenses');

        return redirect()->route('expenses.index')->with('success', 'Expense recorded.');
    }

    public function edit(Expense $expense): Response
    {
        return Inertia::render('Expenses/Edit', [
            'expense' => $expense,
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'campaigns' => AdCampaign::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $expense->fill([...$request->validated(), 'updated_by' => Auth::id()])->save();
        AuditLogService::logModelChange($expense, 'updated', 'expenses');
        return redirect()->route('expenses.index')->with('success', 'Expense updated.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->update(['deleted_by' => Auth::id()]);
        $expense->delete();
        AuditLogService::log('soft_deleted', 'expenses', Expense::class, $expense->id);
        return back()->with('success', 'Expense deleted.');
    }
}
