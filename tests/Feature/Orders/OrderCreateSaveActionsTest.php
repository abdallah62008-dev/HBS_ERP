<?php

namespace Tests\Feature\Orders;

use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
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
 * Orders & Products O-1 — Order Create save action variants + duplicate
 * prefill. See docs/orders-products/ORDER_LIFECYCLE_AND_CREATE_UX.md §3.
 *
 * Scope of this test class:
 *  1. `submit_action` field is validated against the allow-list.
 *  2. Each allow-listed value routes the post-save redirect to the
 *     correct endpoint.
 *  3. The order itself is created identically regardless of variant —
 *     the only side-effect difference is the redirect target.
 *  4. `Save as Draft` is NOT in the allow-list (deferred — would need
 *     a status enum migration).
 *  5. `?duplicate_from={id}` ships a prefill payload to Create.jsx with
 *     customer + items + non-financial fields, no totals/profit columns.
 *  6. Ownership: a marketer can't peek at another marketer's order
 *     via `?duplicate_from`.
 *  7. `Save & Print Label` falls back to Order Show if the user lacks
 *     `shipping.print_label` (no 403, no lost order).
 */
class OrderCreateSaveActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->product = Product::create([
            'name' => 'O-1 Test Widget',
            'sku' => 'O1-WIDGET-001',
            'cost_price' => 50,
            'selling_price' => 200,
            'marketer_trade_price' => 140,
            'minimum_selling_price' => 150,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        app(InventoryService::class)->record(
            productId: $this->product->id, variantId: null, warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance', signedQuantity: 100, unitCost: 50,
            notes: 'O-1 fixture',
        );

        $this->customer = Customer::create([
            'name' => 'O-1 Test Customer',
            'primary_phone' => '0100000O1',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 O-1 Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ───────────────────── 1. submit_action allow-list ───────────────────── */

    public function test_submit_action_accepts_save_default(): void
    {
        // No submit_action sent → backend defaults to `save` → redirects
        // to Order Show.
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload());

        $resp->assertStatus(302);
        $order = Order::firstOrFail();
        $resp->assertRedirect(route('orders.show', $order));
    }

    public function test_submit_action_save_explicit_redirects_to_show(): void
    {
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save']));

        $order = Order::firstOrFail();
        $resp->assertRedirect(route('orders.show', $order));
    }

    public function test_submit_action_save_add_new_redirects_to_create(): void
    {
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save_add_new']));

        $resp->assertRedirect(route('orders.create'));
        $resp->assertSessionHas('success');
        $this->assertSame(1, Order::count()); // order WAS created
    }

    public function test_submit_action_save_duplicate_redirects_with_query(): void
    {
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save_duplicate']));

        $order = Order::firstOrFail();
        $resp->assertRedirect(route('orders.create', ['duplicate_from' => $order->id]));
    }

    public function test_submit_action_save_print_label_redirects_to_label_when_permitted(): void
    {
        // Admin has all permissions including shipping.print_label.
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save_print_label']));

        $order = Order::firstOrFail();
        $resp->assertRedirect(route('shipping-labels.print', $order));
    }

    public function test_submit_action_save_print_label_falls_back_to_show_when_not_permitted(): void
    {
        // Build a user with orders.create but NOT shipping.print_label.
        $user = $this->userWith(['orders.create']);
        $this->actingAs($user);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save_print_label']));

        $order = Order::firstOrFail();
        $resp->assertRedirect(route('orders.show', $order));
        // Flash message hints that label printing was unavailable.
        $resp->assertSessionHas('success', fn ($msg) => str_contains($msg, 'shipping.print_label'));
    }

    public function test_submit_action_save_draft_is_rejected(): void
    {
        // Draft is deferred in O-1 — the allow-list must not include it.
        // The validator returns 302 + session errors (Laravel's default
        // form-request behaviour for non-JSON requests).
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'save_draft']));

        $resp->assertSessionHasErrors(['submit_action']);
        $this->assertSame(0, Order::count());
    }

    public function test_submit_action_unknown_value_is_rejected(): void
    {
        $this->actingAs($this->admin);

        $resp = $this->post('/orders', $this->validPayload(['submit_action' => 'launch_rocket']));

        $resp->assertSessionHasErrors(['submit_action']);
        $this->assertSame(0, Order::count());
    }

    /* ───────────────────── 2. Order creation is identical across variants ───────────────────── */

    public function test_save_add_new_creates_the_same_order_data_as_save(): void
    {
        $this->actingAs($this->admin);

        $this->post('/orders', $this->validPayload(['submit_action' => 'save_add_new']));
        $order = Order::firstOrFail();

        // The order itself must be identical to a plain `save` order —
        // submit_action only changes redirects, never order data.
        $this->assertSame((int) $this->customer->id, (int) $order->customer_id);
        $this->assertSame('New', $order->status);
        $this->assertSame(1, $order->items()->count());
        $this->assertEqualsWithDelta(400.0, (float) $order->subtotal, 0.01);
    }

    public function test_submit_action_is_not_persisted_to_order(): void
    {
        // Defence-in-depth — `submit_action` must not leak into the
        // OrderService payload. Order model has no such column anyway;
        // this asserts the controller strips it before delegating.
        $this->actingAs($this->admin);

        $this->post('/orders', $this->validPayload(['submit_action' => 'save_duplicate']));
        $order = Order::firstOrFail();

        $this->assertArrayNotHasKey('submit_action', $order->toArray());
    }

    /* ───────────────────── 3. duplicate_from prefill ───────────────────── */

    public function test_create_page_with_duplicate_from_loads_prefill_payload(): void
    {
        // First save a source order so we have something to duplicate.
        $this->actingAs($this->admin);
        $this->post('/orders', $this->validPayload());
        $source = Order::firstOrFail();

        $resp = $this->actingAs($this->admin)
            ->get(route('orders.create', ['duplicate_from' => $source->id]));

        $resp->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Create')
                ->where('duplicate_from.source_order_id', $source->id)
                ->where('duplicate_from.source_order_number', $source->order_number)
                ->where('duplicate_from.customer_id', $this->customer->id)
                ->where('duplicate_from.customer.name', $this->customer->name)
                ->has('duplicate_from.items', 1)
                ->where('duplicate_from.items.0.product_id', $this->product->id)
                ->where('duplicate_from.items.0.quantity', 2)
            );
    }

    public function test_duplicate_from_does_not_expose_cost_or_profit_columns(): void
    {
        // The duplicate payload is intentionally cost-free — it's safe to
        // surface even to users without `orders.view_profit`. This test
        // pins the contract.
        $this->actingAs($this->admin);
        $this->post('/orders', $this->validPayload());
        $source = Order::firstOrFail();

        $resp = $this->actingAs($this->admin)
            ->get(route('orders.create', ['duplicate_from' => $source->id]));

        $duplicate = $resp->viewData('page')['props']['duplicate_from'];
        $this->assertIsArray($duplicate);
        $this->assertArrayNotHasKey('net_profit', $duplicate);
        $this->assertArrayNotHasKey('product_cost_total', $duplicate);
        $this->assertArrayNotHasKey('gross_profit', $duplicate);
        $this->assertArrayNotHasKey('marketer_trade_total', $duplicate);
        $this->assertArrayNotHasKey('marketer_profit', $duplicate);
        // Items: no cost field on the line either.
        $this->assertArrayNotHasKey('cost_price', $duplicate['items'][0]);
        $this->assertArrayNotHasKey('marketer_trade_price', $duplicate['items'][0]);
    }

    public function test_duplicate_from_returns_null_for_nonexistent_order(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.create', ['duplicate_from' => 999999]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('duplicate_from', null)
            );
    }

    public function test_duplicate_from_returns_null_when_marketer_targets_other_marketers_order(): void
    {
        // Create an order owned by a different marketer, then have a
        // marketer user try to duplicate it. Ownership is enforced by
        // authorizeOwnership(); the controller traps the abort and
        // returns null prefill rather than 403'ing the whole page.
        $this->actingAs($this->admin);
        $this->post('/orders', $this->validPayload());
        $otherOrder = Order::firstOrFail();

        // Marketer fixture mirrors OrderProfitVisibilityTest — needs a
        // marketer_price_group (NOT NULL on the schema) and a backing
        // user with the 'marketer' role.
        $group = \App\Models\MarketerPriceGroup::create([
            'name' => 'O-1 Group',
            'code' => 'O1G',
            'status' => 'Active',
        ]);
        $mkrUser = User::create([
            'name' => 'O-1 Marketer User',
            'email' => 'o1-marketer-' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', 'marketer')->firstOrFail()->id,
            'status' => 'Active',
        ]);
        $marketer = \App\Models\Marketer::create([
            'code' => 'O1-MKR-' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $mkrUser->id,
            'price_group_id' => $group->id,
            'status' => 'Active',
            'shipping_deducted' => true,
            'tax_deducted' => true,
            'commission_after_delivery_only' => true,
            'settlement_cycle' => 'Weekly',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $marketerUser = $mkrUser;
        $marketerUser->update(['marketer_id' => $marketer->id]);

        // Force the source order to belong to a DIFFERENT marketer so
        // the ownership check fails. Create a second real marketer to
        // satisfy the FK constraint.
        $otherUser = User::create([
            'name' => 'O-1 Other Marketer User',
            'email' => 'o1-other-marketer-' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => Role::where('slug', 'marketer')->firstOrFail()->id,
            'status' => 'Active',
        ]);
        $otherMarketer = \App\Models\Marketer::create([
            'code' => 'O1-OTH-' . strtoupper(substr(uniqid(), -4)),
            'user_id' => $otherUser->id,
            'price_group_id' => $group->id,
            'status' => 'Active',
            'shipping_deducted' => true,
            'tax_deducted' => true,
            'commission_after_delivery_only' => true,
            'settlement_cycle' => 'Weekly',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $otherOrder->update(['marketer_id' => $otherMarketer->id]);

        $this->actingAs($marketerUser)
            ->get(route('orders.create', ['duplicate_from' => $otherOrder->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('duplicate_from', null));
    }

    /* ───────────────────── 4. can_print_label gate prop ───────────────────── */

    public function test_create_page_ships_can_print_label_true_for_admin(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_print_label', true)
            );
    }

    public function test_create_page_ships_can_print_label_false_without_permission(): void
    {
        $user = $this->userWith(['orders.create']);

        $this->actingAs($user)
            ->get(route('orders.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_print_label', false)
            );
    }

    /* ───────────────────── Helpers ───────────────────── */

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'customer_address' => '1 O-1 Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'items' => [[
                'product_id' => $this->product->id,
                'quantity' => 2,
                'unit_price' => 200,
            ]],
            'shipping_amount' => 30,
        ], $overrides);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'O-1 Test ' . uniqid(),
            'slug' => 'o1-test-' . uniqid(),
            'description' => 'O-1 test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'O-1 Test User',
            'email' => 'o1-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
