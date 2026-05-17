<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer C-2 — Customer 360 stats cards + duplicate alert + risk
 * recommendation.
 *
 * Pins:
 *   - The `stats` prop ships with every aggregate the cards consume.
 *   - Each numeric formula uses the data layer correctly (delivered
 *     vs returned vs cancelled; total_spent off Delivered only; AOV;
 *     COD success rate; return rate; outstanding balance).
 *   - A customer with no orders gets a sane all-zero / null payload
 *     instead of NaN / division-by-zero.
 *   - `duplicate_customers` exposes other customers sharing the same
 *     `normalized_phone` but never the current customer itself.
 *   - `risk_recommendation` reflects the risk level mapping.
 *
 * No business-logic assertions — the controller helper is a SUM-CASE
 * aggregate over existing columns; we prove the shape, not the
 * inventory / payment / collection lifecycle.
 */
class Customer360StatsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_customer_show_ships_stats_payload(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->has('stats')
                ->has('stats.total_orders')
                ->has('stats.delivered_orders')
                ->has('stats.returned_orders')
                ->has('stats.cancelled_orders')
                ->has('stats.total_spent')
                ->has('stats.outstanding_balance')
                ->has('stats.cod_success_rate')
                ->has('stats.return_rate')
                ->has('stats.average_order_value')
                ->has('stats.last_order_at')
            );
    }

    public function test_customer_stats_count_total_delivered_returned_cancelled_orders(): void
    {
        $customer = $this->makeCustomer();
        // 2 Delivered, 1 Returned, 1 Cancelled, 1 New → 5 total, of
        // which 2 delivered / 1 returned / 1 cancelled / 1 other.
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100);
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 200);
        $this->makeOrder($customer, status: 'Returned', totalAmount: 300);
        $this->makeOrder($customer, status: 'Cancelled', totalAmount: 400);
        $this->makeOrder($customer, status: 'New', totalAmount: 500);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('stats.total_orders', 5)
                ->where('stats.delivered_orders', 2)
                ->where('stats.returned_orders', 1)
                ->where('stats.cancelled_orders', 1)
            );
    }

    public function test_customer_stats_total_spent_uses_delivered_orders_only(): void
    {
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100);
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 250);
        // These should NOT contribute:
        $this->makeOrder($customer, status: 'Returned', totalAmount: 999);
        $this->makeOrder($customer, status: 'Cancelled', totalAmount: 999);
        $this->makeOrder($customer, status: 'New', totalAmount: 999);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            // JSON has no float/int split — whole numbers decode as int.
            ->assertInertia(fn ($p) => $p->where('stats.total_spent', 350));
    }

    public function test_customer_stats_average_order_value_uses_delivered_orders(): void
    {
        $customer = $this->makeCustomer();
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100);
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 300);
        // Non-delivered must not affect AOV.
        $this->makeOrder($customer, status: 'Returned', totalAmount: 999);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                // (100 + 300) / 2 = 200.00 — JSON decodes whole numbers as int.
                ->where('stats.average_order_value', 200)
            );
    }

    public function test_customer_stats_cod_success_rate_is_computed_safely(): void
    {
        $customer = $this->makeCustomer();
        // 3 COD orders: 2 Collected, 1 Not Collected → 66.7%.
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100, codAmount: 100, collectionStatus: 'Collected');
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100, codAmount: 100, collectionStatus: 'Collected');
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 100, codAmount: 100, collectionStatus: 'Not Collected');
        // Non-COD order — must NOT enter the denominator.
        $this->makeOrder($customer, status: 'Delivered', totalAmount: 50, codAmount: 0, collectionStatus: 'Collected');

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('stats.cod_orders', 3)
                ->where('stats.cod_collected_orders', 2)
                ->where('stats.cod_success_rate', 66.7)
            );
    }

    public function test_customer_stats_handles_customer_with_no_orders(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('stats.total_orders', 0)
                ->where('stats.delivered_orders', 0)
                ->where('stats.returned_orders', 0)
                ->where('stats.cancelled_orders', 0)
                ->where('stats.total_spent', 0)
                ->where('stats.outstanding_balance', 0)
                // Ratios + AOV are NULL when the denominator is zero —
                // pin so we never accidentally start dividing by 0 or
                // returning NaN.
                ->where('stats.cod_success_rate', null)
                ->where('stats.return_rate', null)
                ->where('stats.average_order_value', null)
                ->where('stats.last_order_at', null)
            );
    }

    public function test_customer_show_ships_duplicate_customers_by_normalized_phone(): void
    {
        $primary = $this->makeCustomer(['normalized_phone' => '+201012345678']);
        $dupe = $this->makeCustomer([
            'name' => 'Duplicate Sibling',
            'primary_phone' => '+201012345678',
            'normalized_phone' => '+201012345678',
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $primary->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('duplicate_customers', 1)
                ->where('duplicate_customers.0.id', $dupe->id)
                ->where('duplicate_customers.0.name', 'Duplicate Sibling')
            );
    }

    public function test_customer_show_excludes_self_from_duplicate_customers(): void
    {
        // Customer is the only one with this normalized phone — must
        // never appear as a duplicate of itself.
        $primary = $this->makeCustomer(['normalized_phone' => '+201099998888']);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $primary->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('duplicate_customers', 0));
    }

    public function test_customer_show_risk_recommendation_is_shipped_or_renderable(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('risk_recommendation')
                ->where('risk_recommendation', 'Normal order flow.')
            );
    }

    /* ─── helpers ─── */

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-2 Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(
        Customer $customer,
        string $status = 'Delivered',
        float $totalAmount = 100,
        float $codAmount = 0,
        string $collectionStatus = 'Not Collected',
    ): Order {
        return Order::create([
            'order_number' => 'ORD-C2-' . uniqid(),
            'entry_code' => 'TST',
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->primary_phone,
            'customer_address' => $customer->default_address,
            'city' => $customer->city,
            'country' => $customer->country,
            'currency_code' => 'EGP',
            'total_amount' => $totalAmount,
            'cod_amount' => $codAmount,
            'status' => $status,
            'collection_status' => $collectionStatus,
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
    }
}
