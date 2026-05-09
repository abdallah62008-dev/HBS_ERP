<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class CategoriesController extends Controller
{
    public function index(): Response
    {
        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount('products')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get();

        return Inertia::render('Categories/Index', [
            'categories' => $categories,
        ]);
    }

    public function store(StoreCategoryRequest $request)
    {
        $category = Category::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($category, 'created', 'products');

        // Inline modal flow (e.g. Quick Category from the Product form)
        // expects JSON back so it can append to local state and auto-select
        // the new category without leaving the page.
        if ($request->wantsJson()) {
            return response()->json([
                'category' => $category->only(['id', 'name', 'parent_id', 'status']),
            ], 201);
        }

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category created.');
    }

    public function update(UpdateCategoryRequest $request, Category $category): RedirectResponse
    {
        $category->fill([
            ...$request->validated(),
            'updated_by' => Auth::id(),
        ])->save();

        AuditLogService::logModelChange($category, 'updated', 'products');

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category updated.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        // Reparent any direct children to the deleted category's parent so
        // the tree doesn't get orphaned. Then null-out the products' FK.
        Category::where('parent_id', $category->id)
            ->update(['parent_id' => $category->parent_id]);

        $category->products()->update(['category_id' => null]);

        $category->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'products',
            recordType: Category::class,
            recordId: $category->id,
        );

        return redirect()
            ->route('categories.index')
            ->with('success', 'Category deleted.');
    }
}
