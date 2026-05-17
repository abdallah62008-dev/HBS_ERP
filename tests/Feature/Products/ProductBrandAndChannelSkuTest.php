<?php

namespace Tests\Feature\Products;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductChannelSku;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders & Products P-1 — coverage for the Brand + Channel SKU
 * foundation. See docs/orders-products/PRODUCT_MASTER_DATA_ROADMAP.md
 * and docs/orders-products/CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md.
 *
 * Tests cover:
 *  - Brand admin CRUD (table seeded, products dropdown, deletion-safe)
 *  - Product create/edit with brand_id (nullable)
 *  - Channel SKU sync on Product Edit (insert + update + soft-retire)
 *  - In-form (variant, channel) duplicate guard
 *  - Variant ownership (defence-in-depth)
 *  - Product index search extension (variant + channel SKU)
 *  - Product show payload exposes brand + channel SKUs
 *  - Brand filter on product index
 *  - Existing product creation tests still pass (basic regression)
 *
 * NOTE: Order Create product search is NOT extended in P-1 (deferred to
 * a follow-up phase, per the IMPLEMENTATION_PHASES.md sequence). The
 * existing `tests/Feature/Orders/OrderProductSearchPerformanceTest.php`
 * still asserts the original (name/sku/barcode) contract.
 */
class ProductBrandAndChannelSkuTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Category $defaultCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->defaultCategory = Category::firstOrCreate(
            ['name' => 'P1 Test Category'],
            ['status' => 'Active', 'created_by' => $this->admin->id, 'updated_by' => $this->admin->id],
        );
    }

    /* ────────────────────── 1. Brand admin ────────────────────── */

    public function test_brand_can_be_created_via_admin_post(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('brands.store'), [
            'name' => 'Apple',
            'description' => 'Fruit company',
            'is_active' => true,
            'sort_order' => 1,
        ])->assertRedirect(route('brands.index'));

        $this->assertDatabaseHas('brands', [
            'name' => 'Apple',
            'slug' => 'apple',
            'is_active' => true,
        ]);
    }

    public function test_brand_can_be_created_via_quick_modal_json(): void
    {
        $this->actingAs($this->admin);

        $this->postJson(route('brands.store'), [
            'name' => 'Samsung',
        ])
            ->assertStatus(201)
            ->assertJsonStructure(['brand' => ['id', 'name', 'slug', 'is_active']])
            ->assertJsonPath('brand.name', 'Samsung')
            ->assertJsonPath('brand.is_active', true);

        $this->assertDatabaseHas('brands', ['name' => 'Samsung', 'slug' => 'samsung']);
    }

    public function test_duplicate_brand_name_is_rejected(): void
    {
        $this->actingAs($this->admin);
        Brand::create([
            'name' => 'Nike', 'slug' => 'nike', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->postJson(route('brands.store'), ['name' => 'Nike'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_brand_slug_collisions_resolve_with_suffix(): void
    {
        $this->actingAs($this->admin);
        // Same slug source but different display names so the unique-name
        // guard doesn't fire; we want to prove the slug uniqueness loop.
        Brand::create(['name' => 'Slug Test', 'slug' => 'slug-test', 'is_active' => true, 'sort_order' => 0]);

        $this->post(route('brands.store'), [
            'name' => 'slug-test', // distinct display name; slug source collides → expects "-2" suffix
        ])->assertRedirect();

        $this->assertDatabaseHas('brands', ['name' => 'Slug Test', 'slug' => 'slug-test']);
        $this->assertDatabaseHas('brands', ['name' => 'slug-test', 'slug' => 'slug-test-2']);
    }

    public function test_brand_delete_does_not_cascade_to_products(): void
    {
        $this->actingAs($this->admin);
        $brand = Brand::create(['name' => 'Disposable', 'slug' => 'disposable', 'is_active' => true, 'sort_order' => 0]);
        $product = $this->makeProduct(['brand_id' => $brand->id]);

        $this->delete(route('brands.destroy', $brand->id))->assertRedirect();

        $this->assertDatabaseMissing('brands', ['id' => $brand->id]);
        // Product survives; brand_id is nulled out by ON DELETE SET NULL.
        $this->assertDatabaseHas('products', ['id' => $product->id, 'brand_id' => null]);
    }

    /* ────────────────────── 2. Product create/edit with brand ────────────────────── */

    public function test_product_can_be_created_with_brand(): void
    {
        $this->actingAs($this->admin);
        $brand = Brand::create(['name' => 'BrandedCo', 'slug' => 'brandedco', 'is_active' => true, 'sort_order' => 0]);

        $this->post(route('products.store'), $this->validProductPayload([
            'sku' => 'BRD-001',
            'brand_id' => $brand->id,
        ]))->assertRedirect();

        $this->assertDatabaseHas('products', ['sku' => 'BRD-001', 'brand_id' => $brand->id]);
    }

    public function test_product_can_be_created_without_brand(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('products.store'), $this->validProductPayload([
            'sku' => 'BRD-002',
        ]))->assertRedirect();

        $this->assertDatabaseHas('products', ['sku' => 'BRD-002', 'brand_id' => null]);
    }

    public function test_product_can_be_retagged_to_a_different_brand(): void
    {
        $this->actingAs($this->admin);
        $brandA = Brand::create(['name' => 'BrandA', 'slug' => 'brand-a', 'is_active' => true, 'sort_order' => 0]);
        $brandB = Brand::create(['name' => 'BrandB', 'slug' => 'brand-b', 'is_active' => true, 'sort_order' => 0]);
        $product = $this->makeProduct(['brand_id' => $brandA->id]);

        $this->put(route('products.update', $product->id), [
            'name' => $product->name,
            'sku' => $product->sku,
            'cost_price' => '50',
            'selling_price' => '100',
            'brand_id' => $brandB->id,
        ])->assertRedirect();

        $this->assertSame($brandB->id, $product->fresh()->brand_id);
    }

    public function test_product_create_page_ships_brands_dropdown_active_only(): void
    {
        $this->actingAs($this->admin);
        Brand::create(['name' => 'ActiveOne', 'slug' => 'active-one', 'is_active' => true, 'sort_order' => 0]);
        Brand::create(['name' => 'InactiveOne', 'slug' => 'inactive-one', 'is_active' => false, 'sort_order' => 0]);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Create')
                ->has('brands')
                // Only active brands ship to the create dropdown.
                ->where('brands', fn ($brands) => collect($brands)->pluck('name')->all() === ['ActiveOne'])
            );
    }

    /* ────────────────────── 3. Channel SKUs — create + update ────────────────────── */

    public function test_channel_sku_row_can_be_created_for_a_variant(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $this->put(route('products.update', $product->id), $this->productUpdatePayload($product, [
            'channel_skus' => [[
                'product_variant_id' => $variant->id,
                'channel' => 'Amazon',
                'external_sku' => 'B08N5WRWNW',
                'external_barcode' => '1234567890123',
                'external_url' => 'https://amazon.eg/dp/B08N5WRWNW',
                'is_active' => true,
                'notes' => 'Main listing',
            ]],
        ]))->assertRedirect();

        $this->assertDatabaseHas('product_channel_skus', [
            'product_variant_id' => $variant->id,
            'channel' => 'Amazon',
            'external_sku' => 'B08N5WRWNW',
            'is_active' => true,
        ]);
    }

    public function test_channel_sku_row_can_be_updated_in_place(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);
        $row = ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Noon',
            'external_sku' => 'OLD-SKU',
            'is_active' => true,
        ]);

        $this->put(route('products.update', $product->id), $this->productUpdatePayload($product, [
            'channel_skus' => [[
                'id' => $row->id,
                'product_variant_id' => $variant->id,
                'channel' => 'Noon',
                'external_sku' => 'NEW-SKU',
                'is_active' => true,
            ]],
        ]))->assertRedirect();

        $this->assertDatabaseHas('product_channel_skus', [
            'id' => $row->id,
            'external_sku' => 'NEW-SKU',
        ]);
    }

    public function test_channel_sku_row_can_be_soft_retired_via_active_flag(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);
        $row = ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Jumia',
            'external_sku' => 'JU-1',
            'is_active' => true,
        ]);

        $this->put(route('products.update', $product->id), $this->productUpdatePayload($product, [
            'channel_skus' => [[
                'id' => $row->id,
                'product_variant_id' => $variant->id,
                'channel' => 'Jumia',
                'external_sku' => 'JU-1',
                'is_active' => false,
            ]],
        ]))->assertRedirect();

        // Row not deleted — only deactivated. Audit trail preserved.
        $this->assertDatabaseHas('product_channel_skus', [
            'id' => $row->id,
            'is_active' => false,
        ]);
    }

    public function test_duplicate_variant_channel_in_same_form_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $this->put(route('products.update', $product->id), $this->productUpdatePayload($product, [
            'channel_skus' => [
                [
                    'product_variant_id' => $variant->id,
                    'channel' => 'Amazon',
                    'external_sku' => 'A1',
                ],
                [
                    'product_variant_id' => $variant->id,
                    'channel' => 'Amazon', // same variant + channel — must 422
                    'external_sku' => 'A2',
                ],
            ],
        ]))->assertStatus(302) // PUT validation failure redirects back
            ->assertSessionHasErrors();
    }

    public function test_channel_sku_attached_to_other_products_variant_is_dropped(): void
    {
        // Defence-in-depth: even if the client tampers with the payload
        // to attach a foreign variant_id, the controller's variant
        // ownership check drops the row silently.
        $this->actingAs($this->admin);
        $myProduct = $this->makeProduct();
        $otherProduct = $this->makeProduct(['sku' => 'OTHER-001']);
        $foreignVariant = $this->makeVariant($otherProduct);

        $this->put(route('products.update', $myProduct->id), $this->productUpdatePayload($myProduct, [
            'channel_skus' => [[
                'product_variant_id' => $foreignVariant->id,
                'channel' => 'Amazon',
                'external_sku' => 'SHOULD-NOT-LAND',
            ]],
        ]))->assertRedirect();

        // No row landed under the foreign variant via this controller.
        $this->assertDatabaseMissing('product_channel_skus', [
            'external_sku' => 'SHOULD-NOT-LAND',
        ]);
    }

    public function test_db_unique_constraint_blocks_duplicate_variant_channel(): void
    {
        // The request validator catches in-form duplicates; this test
        // proves the DB-level unique index also prevents cross-request
        // duplicates if someone bypasses the validator (e.g. via a
        // direct SQL import).
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Amazon',
            'external_sku' => 'A1',
            'is_active' => true,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Amazon',
            'external_sku' => 'A2',
            'is_active' => true,
        ]);
    }

    public function test_invalid_channel_string_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct();
        $variant = $this->makeVariant($product);

        $this->put(route('products.update', $product->id), $this->productUpdatePayload($product, [
            'channel_skus' => [[
                'product_variant_id' => $variant->id,
                'channel' => 'TikTok', // not in ProductChannelSku::CHANNELS
                'external_sku' => 'TK-1',
            ]],
        ]))->assertSessionHasErrors();

        $this->assertDatabaseMissing('product_channel_skus', ['external_sku' => 'TK-1']);
    }

    /* ────────────────────── 4. Product index search extension ────────────────────── */

    public function test_product_index_search_matches_variant_sku(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct(['name' => 'VariantSkuParent', 'sku' => 'VSK-PARENT-1']);
        $this->makeVariant($product, ['sku' => 'VSK-VARIANT-RED']);
        // Decoy product to prove filtering.
        $this->makeProduct(['name' => 'Decoy', 'sku' => 'DCY-001']);

        $this->get(route('products.index', ['q' => 'VSK-VARIANT-RED']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Index')
                ->where('products.data', fn ($data) => collect($data)->pluck('name')->all() === ['VariantSkuParent'])
            );
    }

    public function test_product_index_search_matches_channel_external_sku(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct(['name' => 'ChannelSkuParent', 'sku' => 'CSK-PARENT-1']);
        $variant = $this->makeVariant($product);
        ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Amazon',
            'external_sku' => 'B08LOOKUPME',
            'is_active' => true,
        ]);
        $this->makeProduct(['name' => 'Decoy', 'sku' => 'DCY-002']);

        $this->get(route('products.index', ['q' => 'B08LOOKUPME']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data', fn ($data) => collect($data)->pluck('name')->all() === ['ChannelSkuParent'])
            );
    }

    public function test_product_index_search_matches_channel_external_barcode(): void
    {
        $this->actingAs($this->admin);
        $product = $this->makeProduct(['name' => 'BarcodeParent', 'sku' => 'BCD-PARENT-1']);
        $variant = $this->makeVariant($product);
        ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Noon',
            'external_sku' => 'N-1',
            'external_barcode' => '9999000011111',
            'is_active' => true,
        ]);

        $this->get(route('products.index', ['q' => '9999000011111']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data', fn ($data) => collect($data)->pluck('name')->all() === ['BarcodeParent'])
            );
    }

    public function test_product_index_filter_by_brand(): void
    {
        $this->actingAs($this->admin);
        $brandA = Brand::create(['name' => 'FilterBrandA', 'slug' => 'fba', 'is_active' => true, 'sort_order' => 0]);
        $brandB = Brand::create(['name' => 'FilterBrandB', 'slug' => 'fbb', 'is_active' => true, 'sort_order' => 0]);
        $this->makeProduct(['name' => 'ProductA', 'sku' => 'BR-A-1', 'brand_id' => $brandA->id]);
        $this->makeProduct(['name' => 'ProductB', 'sku' => 'BR-B-1', 'brand_id' => $brandB->id]);

        $this->get(route('products.index', ['brand_id' => $brandA->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('products.data', fn ($data) => collect($data)->pluck('name')->all() === ['ProductA'])
            );
    }

    /* ────────────────────── 5. Product show payload ────────────────────── */

    public function test_product_show_exposes_brand_and_channel_skus(): void
    {
        $this->actingAs($this->admin);
        $brand = Brand::create(['name' => 'ShowBrand', 'slug' => 'show-brand', 'is_active' => true, 'sort_order' => 0]);
        $product = $this->makeProduct(['brand_id' => $brand->id]);
        $variant = $this->makeVariant($product);
        ProductChannelSku::create([
            'product_variant_id' => $variant->id,
            'channel' => 'Amazon',
            'external_sku' => 'A-SHOW-1',
            'is_active' => true,
        ]);

        $this->get(route('products.show', $product->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Show')
                ->where('product.brand.name', 'ShowBrand')
                ->where('product.variants.0.channel_skus.0.external_sku', 'A-SHOW-1')
            );
    }

    /* ────────────────────── 6. Regression — existing OrderProductSearch still works ────────────────────── */

    public function test_order_product_search_endpoint_still_matches_by_product_sku(): void
    {
        // The Order Create product search is hot-path; this phase
        // deliberately leaves it on the existing (name/sku/barcode)
        // contract. Verify nothing regressed.
        $this->actingAs($this->admin);
        $this->makeProduct(['name' => 'OrderSearchProbe', 'sku' => 'OSP-001']);

        $this->getJson('/orders/products/search?q=OSP-001')
            ->assertOk()
            ->assertJsonPath('products.0.sku', 'OSP-001');
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'P1 Test Product ' . uniqid(),
            'sku' => 'P1-' . strtoupper(substr(uniqid(), -8)),
            'category_id' => $this->defaultCategory->id,
            'cost_price' => 50,
            'selling_price' => 100,
            'marketer_trade_price' => 80,
            'minimum_selling_price' => 90,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeVariant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'variant_name' => 'Default Variant',
            'sku' => 'V-' . strtoupper(substr(uniqid(), -8)),
            'cost_price' => 50,
            'selling_price' => 100,
            'marketer_trade_price' => 80,
            'minimum_selling_price' => 90,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * Minimum-viable Product STORE payload — supplies every required
     * field. Tests merge in overrides.
     */
    private function validProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Product ' . uniqid(),
            'sku' => 'TST-' . strtoupper(substr(uniqid(), -6)),
            'category_id' => $this->defaultCategory->id,
            'cost_price' => '50',
            'selling_price' => '100',
            'marketer_trade_price' => '80',
            'minimum_selling_price' => '90',
            'tax_enabled' => false,
            'tax_rate' => '0',
            'reorder_level' => '5',
            'status' => 'Active',
        ], $overrides);
    }

    /**
     * Minimum-viable Product UPDATE payload — UpdateProductRequest is
     * permissive (most fields are 'sometimes') so we only need the
     * fields the test changes plus the immutable name/sku for clarity.
     */
    private function productUpdatePayload(Product $product, array $overrides = []): array
    {
        return array_merge([
            'name' => $product->name,
            'sku' => $product->sku,
            'cost_price' => (string) $product->cost_price,
            'selling_price' => (string) $product->selling_price,
        ], $overrides);
    }
}
