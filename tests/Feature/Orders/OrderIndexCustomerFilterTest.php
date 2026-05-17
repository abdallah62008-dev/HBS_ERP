<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer C-1 — `?customer_id=<id>` filter on Orders Index.
 *
 * Pins:
 *   - Filter narrows the result set to the chosen customer.
 *   - Filter composes with the existing `status` filter (and others).
 *   - Invalid customer_id (non-numeric / unknown) doesn't crash —
 *     filter is silently ignored AND `filter_customer` prop is null.
 *   - `filter_customer` prop is populated with the slim customer
 *     summary the UI pill consumes.
 */
class OrderIndexCustomerFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $custA;
    private Customer $custB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();

        $this->custA = $this->makeCustomer('Customer A', '01011111111');
        $this->custB = $this->makeCustomer('Customer B', '01022222222');

        // 2 orders for A, 1 for B, with different statuses to test combos.
        $this->makeOrder($this->custA, 'Delivered');
        $this->makeOrder($this->custA, 'New');
        $this->makeOrder($this->custB, 'Delivered');
    }

    public function test_customer_id_filter_narrows_orders(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.index', ['customer_id' => $this->custA->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Orders/Index')
                ->where('orders.total', 2)
                ->where('filter_customer.id', $this->custA->id)
                ->where('filter_customer.name', 'Customer A')
            );
    }

    public function test_customer_id_filter_combines_with_existing_status_filter(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.index', [
                'customer_id' => $this->custA->id,
                'status' => 'Delivered',
            ]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                // A has 2 orders; only 1 is Delivered.
                ->where('orders.total', 1)
                ->where('filter_customer.id', $this->custA->id)
            );
    }

    public function test_invalid_customer_id_filter_does_not_crash(): void
    {
        // Non-numeric — cast defensively to 0 → filter skipped, all
        // orders return, filter_customer is null.
        $this->actingAs($this->admin)
            ->get(route('orders.index', ['customer_id' => 'not-a-number']))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('orders.total', 3)
                ->where('filter_customer', null)
            );

        // Unknown numeric id — filter is applied but returns no rows;
        // filter_customer is null because the lookup miss.
        $this->actingAs($this->admin)
            ->get(route('orders.index', ['customer_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('orders.total', 0)
                ->where('filter_customer', null)
            );
    }

    public function test_orders_index_ships_filter_customer_prop(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.index', ['customer_id' => $this->custB->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('filter_customer.id', $this->custB->id)
                ->where('filter_customer.name', 'Customer B')
                ->where('filter_customer.primary_phone', '01022222222')
            );
    }

    public function test_orders_index_without_customer_id_ships_null_filter_customer(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('filter_customer', null));
    }

    /* ─── helpers ─── */

    private function makeCustomer(string $name, string $phone): Customer
    {
        return Customer::create([
            'name' => $name,
            'primary_phone' => $phone,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeOrder(Customer $customer, string $status): Order
    {
        return Order::create([
            'order_number' => 'ORD-CFLT-' . uniqid(),
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
}
