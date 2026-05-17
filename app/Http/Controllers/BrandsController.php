<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBrandRequest;
use App\Http\Requests\UpdateBrandRequest;
use App\Models\Brand;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Orders & Products P-1 — Brand admin CRUD.
 *
 * Mirrors CategoriesController in structure (single-page master data
 * with create + inline edit + delete). Permissions piggyback on the
 * existing `products.*` slugs per the P-1 brief; no new slugs added.
 *
 * Brands are referenced by `products.brand_id` with ON DELETE SET NULL,
 * so deleting a brand is non-destructive — products simply lose their
 * brand attribution.
 */
class BrandsController extends Controller
{
    public function index(): Response
    {
        $brands = Brand::query()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Brands/Index', [
            'brands' => $brands,
        ]);
    }

    public function store(StoreBrandRequest $request)
    {
        $data = $request->validated();
        // Slug is derived from name and made unique. Operators never
        // edit it directly; the URL stays stable across renames because
        // we route by ID, not by slug.
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $brand = Brand::create($data);

        AuditLogService::logModelChange($brand, 'created', 'products');

        // Inline modal flow (Quick Brand from the Product form) expects
        // JSON back. Mirrors the CategoriesController pattern so the
        // Product form can append + auto-select the new brand without
        // navigating away.
        if ($request->wantsJson()) {
            return response()->json([
                'brand' => $brand->only(['id', 'name', 'slug', 'is_active', 'sort_order']),
            ], 201);
        }

        return redirect()
            ->route('brands.index')
            ->with('success', 'Brand created.');
    }

    public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
    {
        $data = $request->validated();
        if (array_key_exists('name', $data) && $data['name'] !== $brand->name) {
            $data['slug'] = $this->uniqueSlug($data['name'], $brand->id);
        }
        $data['updated_by'] = Auth::id();

        $brand->fill($data)->save();

        AuditLogService::logModelChange($brand, 'updated', 'products');

        return redirect()
            ->route('brands.index')
            ->with('success', 'Brand updated.');
    }

    public function destroy(Brand $brand): RedirectResponse
    {
        // FK is ON DELETE SET NULL — products keep existing, just lose
        // the brand tag. No reparenting needed.
        $brand->delete();

        AuditLogService::log(
            action: 'deleted',
            module: 'products',
            recordType: Brand::class,
            recordId: $brand->id,
        );

        return redirect()
            ->route('brands.index')
            ->with('success', 'Brand deleted.');
    }

    /**
     * Build a unique slug for a brand. Appends `-2`, `-3`… on collision.
     * Cheap: brand count stays small (< 1k in practice).
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'brand';
        $slug = $base;
        $i = 2;
        while (
            Brand::where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
