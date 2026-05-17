<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Brand;
use App\Models\Category;
use App\Models\MarketerPriceGroup;
use App\Models\MarketerProductPrice;
use App\Models\Product;
use App\Models\ProductChannelSku;
use App\Models\ProductVariant;
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
        $filters = $request->only(['q', 'status', 'category_id', 'brand_id']);

        $products = Product::query()
            ->with(['category:id,name', 'brand:id,name'])
            ->when($filters['q'] ?? null, function ($q, $term) {
                // P-1: extend product search beyond product-level
                // name/sku/barcode to match by variant SKU/barcode and
                // by channel SKU/barcode via EXISTS sub-queries. The
                // outer table and grouping are unchanged so the result
                // shape and pagination contract stay identical.
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('products.name', 'like', $like)
                        ->orWhere('products.sku', 'like', $like)
                        ->orWhere('products.barcode', 'like', $like)
                        ->orWhereExists(function ($sq) use ($like) {
                            $sq->select(DB::raw(1))
                                ->from('product_variants')
                                ->whereColumn('product_variants.product_id', 'products.id')
                                ->where(function ($w2) use ($like) {
                                    $w2->where('product_variants.sku', 'like', $like)
                                        ->orWhere('product_variants.barcode', 'like', $like);
                                });
                        })
                        ->orWhereExists(function ($sq) use ($like) {
                            $sq->select(DB::raw(1))
                                ->from('product_channel_skus')
                                ->join('product_variants', 'product_variants.id', '=', 'product_channel_skus.product_variant_id')
                                ->whereColumn('product_variants.product_id', 'products.id')
                                ->where(function ($w2) use ($like) {
                                    $w2->where('product_channel_skus.external_sku', 'like', $like)
                                        ->orWhere('product_channel_skus.external_barcode', 'like', $like);
                                });
                        });
                });
            })
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('products.status', $v))
            ->when($filters['category_id'] ?? null, fn ($q, $v) => $q->where('products.category_id', $v))
            ->when($filters['brand_id'] ?? null, fn ($q, $v) => $q->where('products.brand_id', $v))
            ->latest('products.id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'filters' => $filters,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            // Show every brand (active + inactive) on the filter so ops
            // can still filter by a retired brand. The create/edit form
            // separately ships only active brands.
            'brands' => Brand::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Products/Create', [
            'categories' => Category::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'brands' => $this->activeBrands(),
            'channels' => ProductChannelSku::CHANNELS,
            'marketer_tiers' => $this->marketerTiers(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $tierPrices = $data['tier_prices'] ?? [];
        $channelSkus = $data['channel_skus'] ?? [];
        unset($data['tier_prices'], $data['channel_skus']);

        $product = DB::transaction(function () use ($data, $tierPrices, $channelSkus) {
            $product = Product::create([
                ...$data,
                'created_by' => Auth::id(),
                'updated_by' => Auth::id(),
            ]);
            $this->syncTierPrices($product, $tierPrices);
            $this->syncChannelSkus($product, $channelSkus);
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
            'brand:id,name,is_active',
            'variants',
            'variants.channelSkus',
            'priceHistory.changedBy:id,name',
        ]);

        return Inertia::render('Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(Product $product): Response
    {
        $product->load([
            'category:id,name',
            'brand:id,name,is_active',
            'variants:id,product_id,variant_name,sku',
            'variants.channelSkus',
        ]);

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
                'collection_cost' => $r->collection_cost,
                'return_cost' => $r->return_cost,
            ]])
            ->all();

        // Flatten current channel SKU rows for the form. The shape
        // mirrors the create/update payload exactly so the same
        // component handles both create and edit.
        $channelSkus = $product->variants->flatMap(function ($variant) {
            return $variant->channelSkus->map(fn ($row) => [
                'id' => $row->id,
                'product_variant_id' => $row->product_variant_id,
                'channel' => $row->channel,
                'external_sku' => $row->external_sku,
                'external_barcode' => $row->external_barcode,
                'external_url' => $row->external_url,
                'is_active' => (bool) $row->is_active,
                'notes' => $row->notes,
            ]);
        })->values()->all();

        return Inertia::render('Products/Edit', [
            'product' => $product,
            'categories' => Category::where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'brands' => $this->activeBrands(),
            'channels' => ProductChannelSku::CHANNELS,
            'channel_skus' => $channelSkus,
            'marketer_tiers' => $this->marketerTiers(),
            'tier_prices' => $tierPrices,
        ]);
    }

    public function update(UpdateProductRequest $request, Product $product): RedirectResponse
    {
        $data = $request->validated();
        $reason = $data['price_change_reason'] ?? null;
        $tierPrices = $data['tier_prices'] ?? [];
        // `channel_skus` is keyed `null` when the client doesn't ship the
        // section at all (e.g. older form payload). Distinguish that
        // from "explicit empty list" so we don't accidentally retire
        // every channel SKU on an unrelated edit.
        $channelSkusProvided = $request->has('channel_skus');
        $channelSkus = $data['channel_skus'] ?? [];
        unset($data['price_change_reason'], $data['tier_prices'], $data['channel_skus']);

        DB::transaction(function () use ($product, $data, $reason, $tierPrices, $channelSkus, $channelSkusProvided) {
            $this->productService->update($product, $data, $reason);
            $this->syncTierPrices($product, $tierPrices);
            if ($channelSkusProvided) {
                $this->syncChannelSkus($product, $channelSkus);
            }
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
     * - If every numeric field for a tier is empty/null, the row is
     *   deleted (clean slate). Matches "save row only if at least one
     *   value exists" from the brief.
     * - VAT % defaults to 14 when at least one other field is present and
     *   the user did not enter a VAT explicitly.
     * - The product's variant column is left null because tier pricing in
     *   this phase is product-level only; per-variant tier pricing is a
     *   future enhancement.
     *
     * @param  array<string, array{marketer_cost_price?:mixed, shipping_cost?:mixed, vat_percent?:mixed, collection_cost?:mixed, return_cost?:mixed}>  $tierPrices
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
            $collection = $this->numberOrNull($row['collection_cost'] ?? null);
            $return = $this->numberOrNull($row['return_cost'] ?? null);

            $allEmpty = $cost === null && $shipping === null && $vat === null
                && $collection === null && $return === null;
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
                'vat_percent' => $vat ?? 14,
                'collection_cost' => $collection,
                'return_cost' => $return,
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

    /**
     * Active brands for the create/edit dropdown. Sorted by `sort_order`
     * first (so ops can pin frequently-used brands to the top), then
     * alphabetically. P-1.
     *
     * @return array<int, array{id:int, name:string}>
     */
    private function activeBrands(): array
    {
        return Brand::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name])
            ->all();
    }

    /**
     * Save the product's channel SKU rows (P-1).
     *
     * Sync rules — kept deliberately simple to stay low-risk:
     *
     *  - New rows (no `id`) are validated to belong to a variant of THIS
     *    product and inserted.
     *  - Existing rows (with `id`) are updated in place. Editing the
     *    (variant, channel) pair on an existing row would risk
     *    colliding with the unique key; we treat such an edit as
     *    legitimate and let the unique constraint surface a clear 422
     *    via the request validator (which pre-checks duplicates).
     *  - Rows in the DB but not in the payload are NOT auto-retired.
     *    To stop using a mapping, ops toggles `is_active = false` on
     *    the row explicitly. Matches the soft-retire policy in the
     *    P-1 design docs.
     *  - All rows have `is_active` honoured exactly as sent.
     *
     * Variant ownership is enforced server-side: a row's
     * `product_variant_id` MUST resolve to a variant under this product.
     * Otherwise the row is silently dropped — defence in depth on top
     * of the request validator.
     *
     * @param  array<int, array{id?:int|null, product_variant_id:int, channel:string,
     *                          external_sku:string, external_barcode?:?string,
     *                          external_url?:?string, is_active?:?bool, notes?:?string}>  $rows
     */
    private function syncChannelSkus(Product $product, array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $variantIds = ProductVariant::where('product_id', $product->id)
            ->pluck('id')
            ->all();

        if (empty($variantIds)) {
            // Product has no variants yet — channel SKUs cannot attach.
            // Drop silently; the UI hides the section when there are no
            // variants.
            return;
        }

        foreach ($rows as $row) {
            $variantId = isset($row['product_variant_id']) ? (int) $row['product_variant_id'] : 0;
            if (! in_array($variantId, $variantIds, true)) {
                continue; // not a variant of this product → skip
            }
            $channel = (string) ($row['channel'] ?? '');
            $externalSku = trim((string) ($row['external_sku'] ?? ''));
            if ($channel === '' || $externalSku === '' || ! in_array($channel, ProductChannelSku::CHANNELS, true)) {
                continue;
            }

            $payload = [
                'product_variant_id' => $variantId,
                'channel' => $channel,
                'external_sku' => $externalSku,
                'external_barcode' => isset($row['external_barcode']) && $row['external_barcode'] !== ''
                    ? (string) $row['external_barcode'] : null,
                'external_url' => isset($row['external_url']) && $row['external_url'] !== ''
                    ? (string) $row['external_url'] : null,
                'is_active' => array_key_exists('is_active', $row)
                    ? (bool) $row['is_active'] : true,
                'notes' => isset($row['notes']) && $row['notes'] !== ''
                    ? (string) $row['notes'] : null,
                'updated_by' => Auth::id(),
            ];

            $existingId = isset($row['id']) && $row['id'] ? (int) $row['id'] : null;
            if ($existingId) {
                ProductChannelSku::where('id', $existingId)
                    ->whereIn('product_variant_id', $variantIds)
                    ->update($payload);
            } else {
                ProductChannelSku::create($payload + ['created_by' => Auth::id()]);
            }
        }
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
