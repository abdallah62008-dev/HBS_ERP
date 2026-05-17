<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-1 — Customer Show quick action props.
 *
 * Pins the five new Inertia props ({@see CustomersController::show}):
 *   - `latest_order_id`     — most-recent non-Cancelled / non-Need-Review order
 *   - `total_orders`        — total count
 *   - `whatsapp_url`        — derived from Customer::whatsappUrl()
 *   - `can_create_order`    — orders.create permission
 *   - `can_view_orders`     — orders.view permission
 *
 * The buttons themselves are pure links (no POST), so we don't need a
 * separate "does not create order" assertion — the existing
 * `orders.store` test surface already proves the path requires items
 * and a Save submission.
 */
class CustomerShowQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_customer_show_ships_latest_order_id_when_safe_order_exists(): void
    {
        $customer = $this->makeCustomer();
        $delivered = $this->makeOrder($customer, 'Delivered');

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->where('latest_order_id', $delivered->id)
            );
    }

    public function test_customer_show_ships_null_latest_order_id_for_new_customer(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('latest_order_id', null));
    }

    public function test_customer_show_excludes_cancelled_and_need_review_from_latest_order(): void
    {
        // Build orders in this order:
        //   1) Delivered (safe)
        //   2) Cancelled  → must be excluded
        //   3) Need Review → must be excluded
        // The latest SAFE order is still the Delivered one (#1).
        $customer = $this->makeCustomer();
        $delivered = $this->makeOrder($customer, 'Delivered');
        $this->makeOrder($customer, 'Cancelled');
        $this->makeOrder($customer, 'Need Review');

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('latest_order_id', $delivered->id));
    }

    public function test_customer_show_ships_whatsapp_url_when_normalized_phone_is_available(): void
    {
        $customer = Customer::create([
            'name' => 'WA Customer',
            'primary_phone' => '01012345678',
            'normalized_phone' => '+201012345678',
            'primary_phone_whatsapp' => true,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('whatsapp_url', 'https://wa.me/201012345678'));
    }

    public function test_customer_show_omits_whatsapp_url_when_whatsapp_disabled(): void
    {
        $customer = Customer::create([
            'name' => 'Opted Out',
            'primary_phone' => '01012345678',
            'normalized_phone' => '+201012345678',
            'primary_phone_whatsapp' => false, // explicit opt-out
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('whatsapp_url', null));
    }

    public function test_customer_show_ships_order_permission_flags(): void
    {
        $customer = $this->makeCustomer();

        // Admin has every slug → both flags true.
        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('can_create_order', true)
                ->where('can_view_orders', true)
            );

        // A user with customers.view ONLY → both flags false. The page
        // still renders (customers.view authorizes the route); the
        // quick-action buttons just stay hidden client-side.
        $viewer = $this->userWith(['customers.view']);
        $this->actingAs($viewer)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('can_create_order', false)
                ->where('can_view_orders', false)
            );
    }

    /* ─── helpers ─── */

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-1 Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 C-1 Street',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(Customer $customer, string $status = 'Delivered'): Order
    {
        return Order::create([
            'order_number' => 'ORD-C1-' . uniqid(),
            'entry_code' => 'TST',
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->primary_phone,
            'customer_address' => $customer->default_address,
            'city' => $customer->city,
            'country' => $customer->country,
            'currency_code' => 'EGP',
            'status' => $status,
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'C-1 ' . uniqid(),
            'slug' => 'c1-' . uniqid(),
            'description' => 'C-1 test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'C-1 User',
            'email' => 'c1+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
