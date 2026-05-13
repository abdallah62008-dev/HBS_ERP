<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Performance Phase 1 — product search endpoint + Orders/Create payload.
 *
 * Replaces the previous "ship the entire active catalogue in Inertia
 * page props" pattern with a server-side search endpoint capped at 25
 * (configurable up to 50) results. Maintains the cost/profit field
 * whitelist from commit ea3e6e5.
 */
class OrderProductSearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Category $catA;
    private Category $catB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();

        $this->catA = Category::firstOrCreate(['name' => 'Search Cat A'], ['status' => 'Active']);
        $this->catB = Category::firstOrCreate(['name' => 'Search Cat B'], ['status' => 'Active']);
    }

    /* ────────────────────── 1. Auth + permission ────────────────────── */

    public function test_endpoint_requires_authentication(): void
    {
        // For JSON requests Laravel returns 401 instead of redirecting.
        $this->getJson('/orders/products/search')
            ->assertStatus(401);
    }

    public function test_endpoint_requires_orders_create_permission(): void
    {
        $user = $this->userWith(['orders.view']);

        $this->actingAs($user)->getJson('/orders/products/search')
            ->assertForbidden();
    }

    /* ────────────────────── 2. Pagination contract ────────────────────── */

    public function test_endpoint_returns_max_25_results_by_default(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeProduct(name: "Result Product $i");
        }
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search')
            ->assertOk();

        $this->assertSame(25, count($resp->json('products')));
        $this->assertSame(25, $resp->json('limit'));
    }

    public function test_endpoint_caps_limit_at_50(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->makeProduct(name: "Capped Product $i");
        }
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?limit=99999')
            ->assertStatus(422); // validation: max 50

        $resp = $this->actingAs($user)->getJson('/orders/products/search?limit=50')
            ->assertOk();
        $this->assertSame(50, count($resp->json('products')));
    }

    /* ────────────────────── 3. Filtering ────────────────────── */

    public function test_endpoint_filters_by_name(): void
    {
        $this->makeProduct(name: 'Widget Alpha', sku: 'WGT-001');
        $this->makeProduct(name: 'Widget Beta', sku: 'WGT-002');
        $this->makeProduct(name: 'Gizmo Gamma', sku: 'GZM-001');
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=Widget')
            ->assertOk();

        $names = array_column($resp->json('products'), 'name');
        $this->assertContains('Widget Alpha', $names);
        $this->assertContains('Widget Beta', $names);
        $this->assertNotContains('Gizmo Gamma', $names);
    }

    public function test_endpoint_filters_by_sku(): void
    {
        $this->makeProduct(name: 'Widget Alpha', sku: 'WGT-001');
        $this->makeProduct(name: 'Widget Beta', sku: 'WGT-002');
        $this->makeProduct(name: 'Gizmo Gamma', sku: 'GZM-001');
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=GZM-001')
            ->assertOk();

        $skus = array_column($resp->json('products'), 'sku');
        $this->assertSame(['GZM-001'], $skus);
    }

    public function test_endpoint_filters_by_barcode(): void
    {
        $this->makeProduct(name: 'BarcodeProductA', sku: 'BC-001', barcode: '111111');
        $this->makeProduct(name: 'BarcodeProductB', sku: 'BC-002', barcode: '222222');
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=222222')
            ->assertOk();

        $skus = array_column($resp->json('products'), 'sku');
        $this->assertSame(['BC-002'], $skus);
    }

    public function test_endpoint_filters_by_category_id(): void
    {
        $this->makeProduct(name: 'A1', categoryId: $this->catA->id);
        $this->makeProduct(name: 'A2', categoryId: $this->catA->id);
        $this->makeProduct(name: 'B1', categoryId: $this->catB->id);
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?category_id=' . $this->catA->id)
            ->assertOk();

        $names = array_column($resp->json('products'), 'name');
        $this->assertCount(2, $names);
        $this->assertContains('A1', $names);
        $this->assertContains('A2', $names);
        $this->assertNotContains('B1', $names);
    }

    public function test_endpoint_excludes_inactive_products(): void
    {
        $this->makeProduct(name: 'ActiveOne');
        $inactive = $this->makeProduct(name: 'InactiveOne');
        $inactive->update(['status' => 'Inactive']);

        $user = $this->userWith(['orders.create']);
        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=One')
            ->assertOk();

        $names = array_column($resp->json('products'), 'name');
        $this->assertContains('ActiveOne', $names);
        $this->assertNotContains('InactiveOne', $names);
    }

    /* ────────────────────── 4. Cost/profit field whitelist ────────────────────── */

    public function test_endpoint_does_not_expose_cost_or_profit_fields(): void
    {
        $this->makeProduct(name: 'Safe Test', sku: 'SAFE-001', costPrice: 50, sellingPrice: 200);
        $user = $this->userWith(['orders.create']);

        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=Safe Test')
            ->assertOk();

        $product = $resp->json('products.0');
        $this->assertNotNull($product);
        // Allowed fields:
        $this->assertArrayHasKey('id', $product);
        $this->assertArrayHasKey('sku', $product);
        $this->assertArrayHasKey('name', $product);
        $this->assertArrayHasKey('selling_price', $product);
        $this->assertArrayHasKey('available', $product);
        // Forbidden fields:
        $this->assertArrayNotHasKey('cost_price', $product);
        $this->assertArrayNotHasKey('marketer_trade_price', $product);
        $this->assertArrayNotHasKey('marketer_shipping_cost', $product);
        $this->assertArrayNotHasKey('marketer_vat_percent', $product);
        $this->assertArrayNotHasKey('product_cost', $product);
        $this->assertArrayNotHasKey('net_profit', $product);
        $this->assertArrayNotHasKey('profit', $product);
        $this->assertArrayNotHasKey('marketer_profit', $product);
        $this->assertArrayNotHasKey('margin', $product);
    }

    /* ────────────────────── 5. Available-stock computation ────────────────────── */

    public function test_endpoint_returns_available_stock(): void
    {
        $product = $this->makeProduct(name: 'StockTest', sku: 'STK-001');
        $warehouse = Warehouse::firstOrFail();
        // 10 on hand via opening balance, 3 reserved.
        $inventory = app(InventoryService::class);
        $inventory->record(
            productId: $product->id, variantId: null, warehouseId: $warehouse->id,
            movementType: 'Opening Balance', signedQuantity: 10, unitCost: 50,
            notes: 'fixture',
        );
        $inventory->record(
            productId: $product->id, variantId: null, warehouseId: $warehouse->id,
            movementType: 'Reserve', signedQuantity: 3,
            notes: 'fixture',
        );

        $user = $this->userWith(['orders.create']);
        $resp = $this->actingAs($user)->getJson('/orders/products/search?q=StockTest')->assertOk();
        $product = $resp->json('products.0');

        $this->assertSame(7, $product['available']); // 10 - 3
    }

    /* ────────────────────── 6. Orders/Create payload — no full catalogue ────────────────────── */

    public function test_create_page_initial_products_prop_is_capped_at_25(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $this->makeProduct(name: "Cat Page Product $i");
        }
        $user = $this->userWith(['orders.create']);

        $this->actingAs($user)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Create')
                ->has('products', 25)
            );
    }

    public function test_create_page_marketers_prop_empty_for_non_view_profit_user(): void
    {
        $user = $this->userWith(['orders.create']);

        $this->actingAs($user)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', false)
                ->has('marketers', 0)
            );
    }

    public function test_create_page_marketers_prop_populated_for_view_profit_user(): void
    {
        // Use the seeded admin which already has orders.view_profit
        // via super-admin behaviour.
        $this->actingAs($this->admin)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', true)
                ->has('marketers')
            );
    }

    /* ────────────────────── 7. Order creation still works ────────────────────── */

    public function test_order_creation_still_works_with_searched_product(): void
    {
        // Operator: search returns the product, then we create the
        // order via the existing /orders POST. The store endpoint is
        // unchanged — `data.items[*].product_id` is the only product
        // identifier that hits the server-side createFromPayload.
        $product = $this->makeProduct(name: 'CreatableProduct', sku: 'CRT-001');
        $warehouse = Warehouse::firstOrFail();
        app(InventoryService::class)->record(
            productId: $product->id, variantId: null, warehouseId: $warehouse->id,
            movementType: 'Opening Balance', signedQuantity: 50, unitCost: 50,
            notes: 'fixture',
        );

        $customer = Customer::create([
            'name' => 'Search Test Customer',
            'primary_phone' => '01066667777',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Search Street',
            'created_by' => $this->admin->id,
        ]);

        $user = $this->userWith(['orders.create']);

        $this->actingAs($user)->post('/orders', [
            'customer_id' => $customer->id,
            'customer_address' => '1 Search Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 200,
            ]],
            'shipping_amount' => 30,
        ])->assertRedirect();

        $this->assertSame(1, \App\Models\Order::count());
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeProduct(
        string $name,
        ?string $sku = null,
        ?string $barcode = null,
        ?int $categoryId = null,
        float $costPrice = 50,
        float $sellingPrice = 200,
    ): Product {
        static $counter = 0;
        $counter++;
        return Product::create([
            'sku' => $sku ?? ('SEARCH-' . str_pad((string) $counter, 4, '0', STR_PAD_LEFT)),
            'barcode' => $barcode,
            'name' => $name,
            'description' => 'fixture',
            'category_id' => $categoryId,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'marketer_trade_price' => $costPrice + 90,
            'minimum_selling_price' => $sellingPrice - 50,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Search Perf Test ' . uniqid(),
            'slug' => 'search-perf-test-' . uniqid(),
            'description' => 'Performance Phase 1 test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Search Perf Test User',
            'email' => 'search-perf+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
