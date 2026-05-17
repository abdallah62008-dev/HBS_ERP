<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer C-1 — `?customer_id=<id>` query-param prefill on Order Create.
 *
 * Pins:
 *   - Valid id → `prefill_customer` ships with the slim customer payload.
 *   - Unknown / soft-deleted id → `prefill_customer` is null; page still renders.
 *   - Loading the prefill page does NOT create an order.
 *   - The prefill payload carries NO cost / profit fields (safe-fields contract).
 *   - When BOTH `customer_id` and `duplicate_from` are sent, `duplicate_from` wins —
 *     the duplicate path already carries (possibly different) customer data and
 *     items, and overriding it would silently break operator intent.
 */
class OrderCreateCustomerPrefillTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->customer = Customer::create([
            'name' => 'Prefill Target',
            'primary_phone' => '01012345678',
            'normalized_phone' => '+201012345678',
            'primary_phone_whatsapp' => true,
            'city' => 'Cairo',
            'governorate' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '12 Prefill Street',
            'email' => 'prefill@example.com',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_customer_id_query_param_loads_prefill_customer(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => $this->customer->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Orders/Create')
                ->where('prefill_customer.id', $this->customer->id)
                ->where('prefill_customer.name', 'Prefill Target')
                ->where('prefill_customer.normalized_phone', '+201012345678')
                ->where('prefill_customer.city', 'Cairo')
                ->where('prefill_customer.default_address', '12 Prefill Street')
            );
    }

    public function test_unknown_customer_id_renders_empty_prefill(): void
    {
        $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => 999999]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill_customer', null));
    }

    public function test_soft_deleted_customer_id_renders_empty_prefill(): void
    {
        // Soft-deleted customers must not surface in the prefill — they
        // shouldn't be selectable for new orders either.
        $deleted = Customer::create([
            'name' => 'Deleted',
            'primary_phone' => '01099999999',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);
        $deleted->delete();

        $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => $deleted->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('prefill_customer', null));
    }

    public function test_customer_prefill_does_not_create_order(): void
    {
        $before = Order::count();

        $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => $this->customer->id]))
            ->assertOk();

        $this->assertSame($before, Order::count(), 'GET /orders/create must NEVER create an order.');
    }

    public function test_customer_prefill_does_not_expose_cost_or_profit_fields(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => $this->customer->id]))
            ->assertOk();

        $prefill = $response->viewData('page')['props']['prefill_customer'];
        $this->assertIsArray($prefill);

        // Allowed fields are present:
        $this->assertSame($this->customer->id, $prefill['id']);
        $this->assertSame('Prefill Target', $prefill['name']);

        // Cost / profit fields must NOT leak:
        foreach (['cost_price', 'marketer_trade_price', 'product_cost_total', 'net_profit', 'gross_profit', 'marketer_profit', 'marketer_trade_total'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $prefill, "Forbidden field {$forbidden} appeared in prefill_customer.");
        }
    }

    public function test_duplicate_from_takes_precedence_over_customer_id(): void
    {
        // Build a source order tied to a DIFFERENT customer.
        $sourceCustomer = Customer::create([
            'name' => 'Source Customer',
            'primary_phone' => '01088887777',
            'city' => 'Alex',
            'country' => 'Egypt',
            'default_address' => 'src addr',
            'created_by' => $this->admin->id,
        ]);
        $sourceOrder = Order::create([
            'order_number' => 'ORD-PRECEDENCE-1',
            'entry_code' => 'TST',
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $sourceCustomer->id,
            'customer_name' => $sourceCustomer->name,
            'customer_phone' => $sourceCustomer->primary_phone,
            'customer_address' => $sourceCustomer->default_address,
            'city' => $sourceCustomer->city,
            'country' => $sourceCustomer->country,
            'currency_code' => 'EGP',
            'status' => 'Delivered',
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);

        // Send BOTH params with conflicting customers.
        $this->actingAs($this->admin)
            ->get(route('orders.create', [
                'customer_id' => $this->customer->id,
                'duplicate_from' => $sourceOrder->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                // duplicate_from wins; prefill_customer is suppressed.
                ->where('prefill_customer', null)
                ->where('duplicate_from.source_order_id', $sourceOrder->id)
                ->where('duplicate_from.customer_id', $sourceCustomer->id)
            );
    }
}
