<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProductsController extends Controller
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['q', 'status', 'category_id']);

        $products = Product::query()
            ->with('category:id,name')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(function ($w) use ($term) {
                    $w->where('name', 'like', "%{$term}%")
                        ->orWhere('sku', 'like', "%{$term}%")
                        ->orWhere('barcode', 'like', "%{$term}%");
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('category_id', $v))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create', [
            'categories' => Category::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $product = Product::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        AuditLogService::logModelChange($product, 'created', 'products');

        return redirect()
            ->route('products.show', $product)
            ->with('success', 'Product created.');
    }

    public function show(Product $product): Response
    {
        $product->load([
            'category:id,name',
            'variants',
            'priceHistory.changedBy:id,name',
        ]);

        return Inertia::render('Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load('category:id,name');

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => Category::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $reason = $data['price_change_reason'] ?? null;
        unset($data['price_change_reason']);

        $this->productService->update($product, $data, $reason);

        return redirect()
            ->route('products.show', $product)
            ->with('success', 'Product updated.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['deleted_by' => Auth::id()]);
        $product->delete();

        AuditLogService::log(
            action: 'soft_deleted',
            module: 'products',
            recordType: Product::class,
            recordId: $product->id,
        );

        return redirect()
            ->route('products.index')
            ->with('success', 'Product deleted.');
    }
}
