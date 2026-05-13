<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\Marketer;
use App\Models\MarketerPriceGroup;
use App\Models\Order;
use App\Models\OrderItem;
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
 * Cost/profit visibility gate (the `orders.view_profit` permission).
 *
 * Non-Super Admin / non-`orders.view_profit` users must NOT receive
 * cost/profit columns in Inertia page props for the Orders pages,
 * AND must NOT be able to call the marketer-profit-preview endpoint.
 *
 * Frontend hiding is not enough — the data must not ship to the browser.
 */
class OrderProfitVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private Product $product;
    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->product = Product::create([
            'sku' => 'OPV-001',
            'name' => 'Order Profit Visibility SKU',
            'description' => 'fixture',
            'cost_price' => 60,
            'selling_price' => 200,
            'marketer_trade_price' => 140,
            'minimum_selling_price' => 120,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'OPV Test Customer',
            'primary_phone' => '01055554444',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 OPV Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance so the Index page paginates an order with cost
        // values populated.
        app(InventoryService::class)->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 60,
            notes: 'OPV opening balance',
        );
    }

    /* ────────────────────── 1. Create page props ────────────────────── */

    public function test_create_page_exposes_can_view_profit_true_for_privileged_user(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create', 'orders.view_profit']);

        $this->actingAs($user)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Create')
                ->where('can_view_profit', true)
            );
    }

    public function test_create_page_exposes_can_view_profit_false_for_non_privileged_user(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', false)
            );
    }

    public function test_create_page_products_prop_does_not_expose_cost_price(): void
    {
        // Existing safe contract — productsForOrderEntry() only selects
        // selling_price, never cost_price. Verify it stays that way.
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->get('/orders/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('products.0', fn ($p) => $p
                    ->where('sku', 'OPV-001')
                    ->has('selling_price')
                    ->missing('cost_price')
                    ->missing('marketer_trade_price')
                    ->etc()
                )
            );
    }

    /* ────────────────────── 2. Marketer-profit-preview endpoint ────────────────────── */

    public function test_marketer_profit_preview_returns_403_without_view_profit(): void
    {
        $user = $this->userWith(['orders.create']);
        $marketer = $this->makeMarketer();

        $this->actingAs($user)
            ->postJson(route('orders.marketer-profit-preview'), [
                'marketer_id' => $marketer->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 200,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_marketer_profit_preview_returns_200_with_view_profit(): void
    {
        $user = $this->userWith(['orders.create', 'orders.view_profit']);
        $marketer = $this->makeMarketer();

        $this->actingAs($user)
            ->postJson(route('orders.marketer-profit-preview'), [
                'marketer_id' => $marketer->id,
                'items' => [[
                    'product_id' => $this->product->id,
                    'quantity' => 1,
                    'unit_price' => 200,
                ]],
            ])
            ->assertOk()
            ->assertJsonStructure([
                'marketer' => ['id', 'code'],
                'lines',
                'total',
            ]);
    }

    /* ────────────────────── 3. Show page props ────────────────────── */

    public function test_show_page_strips_profit_fields_for_non_privileged_user(): void
    {
        $order = $this->orderWithProfit();
        $user = $this->userWith(['orders.view']);

        $this->actingAs($user)->get('/orders/' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('can_view_profit', false)
                ->has('order', fn ($o) => $o
                    ->missing('net_profit')
                    ->missing('product_cost_total')
                    ->missing('marketer_profit')
                    ->missing('marketer_trade_total')
                    ->etc()
                )
            );
    }

    public function test_show_page_includes_profit_fields_for_privileged_user(): void
    {
        $order = $this->orderWithProfit();
        $user = $this->userWith(['orders.view', 'orders.view_profit']);

        $this->actingAs($user)->get('/orders/' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', true)
                ->has('order', fn ($o) => $o
                    ->has('net_profit')
                    ->has('product_cost_total')
                    ->etc()
                )
            );
    }

    public function test_show_page_order_items_strip_cost_fields_for_non_privileged_user(): void
    {
        $order = $this->orderWithProfit();
        $user = $this->userWith(['orders.view']);

        $this->actingAs($user)->get('/orders/' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('order.items.0', fn ($it) => $it
                    ->missing('marketer_trade_price')
                    ->missing('marketer_shipping_cost')
                    ->missing('marketer_vat_percent')
                    ->etc()
                )
            );
    }

    /* ────────────────────── 4. Edit page props ────────────────────── */

    public function test_edit_page_strips_profit_fields_for_non_privileged_user(): void
    {
        $order = $this->orderWithProfit();
        $user = $this->userWith(['orders.view', 'orders.edit']);

        $this->actingAs($user)->get('/orders/' . $order->id . '/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Edit')
                ->where('can_view_profit', false)
                ->has('order', fn ($o) => $o
                    ->missing('marketer_profit')
                    ->missing('net_profit')
                    ->missing('product_cost_total')
                    ->etc()
                )
            );
    }

    public function test_edit_page_includes_profit_fields_for_privileged_user(): void
    {
        $order = $this->orderWithProfit();
        $user = $this->userWith(['orders.view', 'orders.edit', 'orders.view_profit']);

        $this->actingAs($user)->get('/orders/' . $order->id . '/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', true)
                ->has('order.marketer_profit')
            );
    }

    /* ────────────────────── 5. Index page rows ────────────────────── */

    public function test_index_page_strips_profit_fields_for_non_privileged_user(): void
    {
        $this->orderWithProfit();
        $user = $this->userWith(['orders.view']);

        $this->actingAs($user)->get('/orders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_view_profit', false)
                ->has('orders.data.0', fn ($o) => $o
                    ->missing('net_profit')
                    ->missing('product_cost_total')
                    ->missing('marketer_profit')
                    ->etc()
                )
            );
    }

    /* ────────────────────── 6. Order creation still works ────────────────────── */

    public function test_non_privileged_user_can_still_create_order(): void
    {
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->post('/orders', [
            'customer_id' => $this->customer->id,
            'customer_address' => '1 OPV Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 200,
            ]],
            'shipping_amount' => 30,
        ])->assertRedirect();

        $this->assertGreaterThan(0, Order::count());
    }

    /* ────────────────────── 7. Cost/profit cannot be injected via POST ────────────────────── */

    public function test_cost_and_profit_fields_in_request_are_ignored(): void
    {
        // Defence-in-depth: even if a malicious client sends fake
        // cost/profit fields, the StoreOrderRequest rules don't accept
        // them. The order's actual cost/profit is calculated server-side
        // from Product::cost_price.
        $user = $this->userWith(['orders.view', 'orders.create']);

        $this->actingAs($user)->post('/orders', [
            'customer_id' => $this->customer->id,
            'customer_address' => '1 OPV Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 200,
                // Attempt to override:
                'cost_price' => 0,
                'profit' => 999999,
                'marketer_profit' => 999999,
            ]],
            'shipping_amount' => 30,
            // Top-level attempts:
            'net_profit' => 999999,
            'product_cost_total' => 0,
            'marketer_profit' => 999999,
        ])->assertRedirect();

        $order = Order::latest('id')->firstOrFail();
        // The injected fake cost / profit values must NOT have landed.
        $this->assertNotSame(999999.00, (float) $order->net_profit);
        $this->assertNotSame(0.00, (float) $order->product_cost_total);
        // OrderItem's snapshot cost is the Product's cost_price, not the
        // client's bogus value.
        $item = $order->items->first();
        if ($item) {
            $this->assertNotSame('0.00', (string) $item->marketer_trade_price);
        }
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function orderWithProfit(): Order
    {
        $order = Order::create([
            'order_number' => 'OPV-' . uniqid(),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => 'Delivered',
            'collection_status' => 'Collected',
            'shipping_status' => 'Delivered',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'subtotal' => 200,
            'total_amount' => 230,
            'shipping_amount' => 30,
            'product_cost_total' => 60,
            'net_profit' => 170,
            'marketer_profit' => 80,
            'marketer_trade_total' => 140,
            'created_by' => $this->admin->id,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'sku' => $this->product->sku,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'unit_price' => 200,
            'total_price' => 200,
            'marketer_trade_price' => 140,
            'marketer_shipping_cost' => 10,
            'marketer_vat_percent' => 5,
        ]);

        return $order->load('items');
    }

    private function makeMarketer(): Marketer
    {
        $group = MarketerPriceGroup::create([
            'name' => 'OPV Group ' . uniqid(),
            'code' => 'OPV' . substr(uniqid(), -4),
            'status' => 'Active',
        ]);
        $role = Role::where('slug', 'marketer')->firstOrFail();
        $user = User::create([
            'name' => 'OPV Marketer',
            'email' => 'opv-mkt-' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
        return Marketer::create([
            'user_id' => $user->id,
            'code' => 'MKT-OPV-' . strtoupper(substr(uniqid(), -4)),
            'price_group_id' => $group->id,
            'status' => 'Active',
            'shipping_deducted' => true,
            'tax_deducted' => true,
            'commission_after_delivery_only' => true,
            'settlement_cycle' => 'Weekly',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'OPV Test ' . uniqid(),
            'slug' => 'opv-test-' . uniqid(),
            'description' => 'Profit visibility test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'OPV Test User',
            'email' => 'opv+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
