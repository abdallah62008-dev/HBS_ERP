<?php

namespace App\Http\Controllers;

use App\Models\ExpenseCategory;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseCategoriesController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Expenses/Categories', [
            'categories' => ExpenseCategory::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ]);

        $category = ExpenseCategory::create($data);
        AuditLogService::logModelChange($category, 'created', 'expenses');

        return back()->with('success', 'Category added.');
    }

    public function update(Request $request, ExpenseCategory $expenseCategory): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('expense_categories', 'name')->ignore($expenseCategory->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:Active,Inactive'],
        ]);

        $expenseCategory->fill($data)->save();
        AuditLogService::logModelChange($expenseCategory, 'updated', 'expenses');

        return back()->with('success', 'Category updated.');
    }

    public function destroy(ExpenseCategory $expenseCategory): RedirectResponse
    {
        if ($expenseCategory->id && \App\Models\Expense::where('expense_category_id', $expenseCategory->id)->exists()) {
            return back()->with('error', 'Cannot delete a category that has expenses. Mark it Inactive instead.');
        }
        $expenseCategory->delete();
        AuditLogService::log('deleted', 'expenses', ExpenseCategory::class, $expenseCategory->id);
        return back()->with('success', 'Category deleted.');
    }
}
