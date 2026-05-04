<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Category;
use App\Models\MarketerPriceGroup;
use App\Models\MarketerProductPrice;
use App\Models\Product;
use App\Services\AuditLogService;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            'marketer_tiers' => $this->marketerTiers(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $tierPrices = $data['tier_prices'] ?? [];
        unset($data['tier_prices']);

        $product = DB::transaction(function () use ($data, $tierPrices) {
            $product = Product::create([
                ...$data,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $this->syncTierPrices($product, $tierPrices);
            AuditLogService::logModelChange($product, 'created', 'products');
            return $product;
        });

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

        // Existing tier prices for this product (by tier code).
        $tierPrices = MarketerProductPrice::query()
            ->where('product_id', $product->id)
            ->whereNull('product_variant_id')
            ->whereHas('priceGroup', fn ($q) => $q->whereNotNull('code'))
            ->with('priceGroup:id,code')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->priceGroup->code => [
                'marketer_cost_price' => $r->trade_price,
                'shipping_cost' => $r->shipping_cost,
                'vat_percent' => $r->vat_percent,
            ]])
            ->all();

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => Category::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'marketer_tiers' => $this->marketerTiers(),
            'tier_prices' => $tierPrices,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $reason = $data['price_change_reason'] ?? null;
        $tierPrices = $data['tier_prices'] ?? [];
        unset($data['price_change_reason'], $data['tier_prices']);

        DB::transaction(function () use ($product, $data, $reason, $tierPrices) {
            $this->productService->update($product, $data, $reason);
            $this->syncTierPrices($product, $tierPrices);
        });

        return redirect()
            ->route('products.show', $product)
            ->with('success', 'Product updated.');
    }

    /**
     * Active tiers (codes A/B/D/E) for the product create/edit form.
     *
     * @return array<int, array{id:int, code:string, name:string, sort_order:int}>
     */
    private function marketerTiers(): array
    {
        return MarketerPriceGroup::query()
            ->whereNotNull('code')
            ->where('status', 'Active')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'code', 'name', 'sort_order'])
            ->map(fn ($g) => [
                'id' => (int) $g->id,
                'code' => $g->code,
                'name' => $g->name,
                'sort_order' => (int) ($g->sort_order ?? 0),
            ])
            ->all();
    }

    /**
     * Save the per-product marketer tier prices.
     *
     * - Each row is keyed on (product_id, marketer_price_group_id) — the
     *   join already enforces uniqueness via product_id + group + variant.
     * - If all three numeric fields for a tier are empty/null, the row is
     *   deleted (clean slate). This matches "save row only if at least
     *   one value exists" from the brief.
     * - The product's variant column is left null because tier pricing in
     *   this phase is product-level only; per-variant tier pricing is a
     *   future enhancement.
     *
     * @param  array<string, array{marketer_cost_price?:mixed, shipping_cost?:mixed, vat_percent?:mixed}>  $tierPrices
     */
    private function syncTierPrices(Product $product, array $tierPrices): void
    {
        if (empty($tierPrices)) {
            return;
        }

        $tiers = MarketerPriceGroup::whereNotNull('code')
            ->whereIn('code', array_keys($tierPrices))
            ->get(['id', 'code']);

        foreach ($tiers as $tier) {
            $row = $tierPrices[$tier->code] ?? [];
            $cost = $this->numberOrNull($row['marketer_cost_price'] ?? null);
            $shipping = $this->numberOrNull($row['shipping_cost'] ?? null);
            $vat = $this->numberOrNull($row['vat_percent'] ?? null);

            $allEmpty = $cost === null && $shipping === null && $vat === null;
            $key = [
                'product_id' => $product->id,
                'marketer_price_group_id' => $tier->id,
                'product_variant_id' => null,
            ];

            if ($allEmpty) {
                MarketerProductPrice::where($key)->delete();
                continue;
            }

            MarketerProductPrice::updateOrCreate($key, [
                // marketer_product_prices.trade_price column already
                // existed — it stores the marketer cost price for this
                // (group, product) pairing. minimum_selling_price is NOT
                // NULL on the existing schema with no default; we pass 0
                // since this phase doesn't expose a tier-level minimum
                // (the product-level minimum is the binding floor).
                'trade_price' => $cost ?? 0,
                'minimum_selling_price' => 0,
                'shipping_cost' => $shipping,
                'vat_percent' => $vat,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
        }
    }

    private function numberOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === 'null') return null;
        if (! is_numeric($value)) return null;
        return (float) $value;
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
