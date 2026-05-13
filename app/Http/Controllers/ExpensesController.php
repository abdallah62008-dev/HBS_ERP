<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExpenseRequest;
use App\Models\AdCampaign;
use App\Models\Cashbox;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Services\AuditLogService;
use App\Services\ExpenseCashboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

class ExpensesController extends Controller
{
    public function __construct(private ExpenseCashboxService $cashboxService) {}

    public function index(Request $request): Response
    {
        $filters = $request->only([
            'q', 'category_id', 'from', 'to',
            'cashbox_id', 'payment_method_id', 'posted',
        ]);

        $expenses = Expense::query()
            ->with([
                'category:id,name',
                'relatedOrder:id,order_number',
                'relatedCampaign:id,name',
                'createdBy:id,name',
                'cashbox:id,name,currency_code,is_active',
                'paymentMethod:id,name,code,is_active',
            ])
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('title', 'like', "%{$term}%")->orWhere('notes', 'like', "%{$term}%");
                });
            })
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->when(($filters['posted'] ?? null) === 'posted', fn ($q) => $q->whereNotNull('cashbox_transaction_id'))
            ->when(($filters['posted'] ?? null) === 'unposted', fn ($q) => $q->whereNull('cashbox_transaction_id'))
            ->latest('expense_date')
            ->paginate(30)
            ->withQueryString();

        $totalAmount = (float) Expense::query()
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('expense_category_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v))
            ->when($filters['cashbox_id'] ?? null, fn ($q, $v) => $q->where('cashbox_id', $v))
            ->when($filters['payment_method_id'] ?? null, fn ($q, $v) => $q->where('payment_method_id', $v))
            ->sum('amount');

        return Inertia::render('Expenses/Index', [
            'expenses' => $expenses,
            'filters' => $filters,
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'cashboxes' => Cashbox::orderBy('name')->get(['id', 'name', 'currency_code', 'is_active']),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code', 'is_active']),
            'total_amount' => $totalAmount,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Expenses/Create', [
            'categories' => ExpenseCategory::where('status', 'Active')->orderBy('name')->get(['id', 'name']),
            'campaigns' => AdCampaign::where('status', '!=', 'Ended')->orderBy('name')->get(['id', 'name']),
            'cashboxes' => Cashbox::active()->orderBy('name')->get(['id', 'name', 'currency_code', 'allow_negative_balance']),
            'payment_methods' => PaymentMethod::active()->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    /**
     * Create + atomically post to cashbox.
     *
     * Phase 4 requires cashbox_id + payment_method_id on every new
     * expense (ExpenseRequest enforces). The whole flow is wrapped
     * in DB::transaction so a posting failure rolls back the expense
     * row too — the system never holds a half-recorded expense.
     */
    public function store(ExpenseRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $cashboxId = $validated['cashbox_id'];
        $paymentMethodId = $validated['payment_method_id'];
        // These two will be set by the service; remove from the create payload
        // so the expense row is unposted until the service stamps it.
        unset($validated['cashbox_id'], $validated['payment_method_id']);

        try {
            DB::transaction(function () use ($validated, $cashboxId, $paymentMethodId) {
                $expense = Expense::create([
                    ...$validated,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);

                AuditLogService::logModelChange($expense, 'created', 'expenses');

                $this->cashboxService->postExpenseToCashbox($expense, [
                    'cashbox_id' => $cashboxId,
                    'payment_method_id' => $paymentMethodId,
                ]);
            });
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('expenses.index')->with('success', 'Expense recorded and posted to cashbox.');
    }

    public function edit(Expense $expense): Response
    {
        return Inertia::render('Expenses/Edit', [
            'expense' => $expense->load([
                'cashbox:id,name,currency_code,is_active',
                'paymentMethod:id,name,code,is_active',
                'cashboxTransaction:id,occurred_at,amount,direction',
            ]),
            'categories' => ExpenseCategory::orderBy('name')->get(['id', 'name']),
            'campaigns' => AdCampaign::orderBy('name')->get(['id', 'name']),
            'cashboxes' => Cashbox::orderBy('name')->get(['id', 'name', 'currency_code', 'is_active', 'allow_negative_balance']),
            'payment_methods' => PaymentMethod::orderBy('name')->get(['id', 'name', 'code', 'is_active']),
        ]);
    }

    /**
     * Update an expense.
     *
     * Posted expenses are append-only: the financial fields (amount,
     * cashbox_id, payment_method_id, expense_date) are stripped server
     * side — the operator can still edit title, notes, attachment, and
     * the related order/campaign links. Reversal flow waits for Phase 5.
     */
    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $data = $request->validated();

        if ($expense->isPosted()) {
            // Defense in depth: even if the FormRequest had defaults,
            // never let the operator drift the financial fields of a
            // posted expense.
            unset($data['amount'], $data['cashbox_id'], $data['payment_method_id'], $data['expense_date'], $data['currency_code']);
        }

        $expense->fill([...$data, 'updated_by' => Auth::id()])->save();
        AuditLogService::logModelChange($expense, 'updated', 'expenses');

        return redirect()->route('expenses.index')->with('success', 'Expense updated.');
    }

    /**
     * Soft-delete an expense.
     *
     * Posted expenses cannot be deleted in Phase 4 — the cashbox
     * transaction they wrote is append-only, and the reversal mechanism
     * is a future-phase concern (refunds/adjustments). Until then,
     * deleting a posted expense would orphan a real cashbox row.
     */
    public function destroy(Expense $expense): RedirectResponse
    {
        if ($expense->isPosted()) {
            return back()->with(
                'error',
                'This expense is posted to a cashbox and cannot be deleted. Use a future reversal flow to correct it.'
            );
        }

        $expense->update(['deleted_by' => Auth::id()]);
        $expense->delete();
        AuditLogService::log('soft_deleted', 'expenses', Expense::class, $expense->id);
        return back()->with('success', 'Expense deleted.');
    }

    /**
     * Retroactively post a historical / null-cashbox expense to a cashbox.
     *
     * Use case: legacy rows imported from before Phase 4, or expenses
     * created by an admin tool that bypassed the new requirement.
     * Permission: `expenses.post_to_cashbox` (Phase 4 slug).
     */
    public function postToCashbox(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'cashbox_id' => ['required', 'integer', 'exists:cashboxes,id'],
            'payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        try {
            $this->cashboxService->postExpenseToCashbox($expense, $data);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Expense posted to cashbox.');
    }
}
