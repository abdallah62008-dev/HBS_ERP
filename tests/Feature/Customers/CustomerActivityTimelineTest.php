<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\OrderStatusHistory;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customer C-3 — read-only activity timeline.
 *
 * Pins:
 *   - `timeline` prop ships from Customer Show.
 *   - Includes customer_created anchor (always).
 *   - Includes order_created events from `orders.customer_id` query.
 *   - Includes order_status_changed events from
 *     `order_status_history` filtered by this customer's order ids.
 *   - Includes return_created and refund_created (+ approved/rejected/paid)
 *     events keyed off the indexed customer_id column.
 *   - Sorts newest-first.
 *   - Hard-capped at 30 events to keep payload + render bounded.
 *   - Events for OTHER customers' orders / returns / refunds NEVER leak
 *     into this customer's timeline.
 *   - Order Show links are wired correctly.
 */
class CustomerActivityTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_customer_show_ships_timeline_payload(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->has('timeline')
            );
    }

    public function test_timeline_includes_customer_created_event(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('timeline.0.type', 'customer_created')
                ->where('timeline.0.title', 'Customer profile created')
            );
    }

    public function test_timeline_includes_order_created_events(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'New');

        $timeline = $this->timelineFor($customer);
        $types = array_column($timeline, 'type');
        $this->assertContains('order_created', $types);
        $created = collect($timeline)->firstWhere('type', 'order_created');
        $this->assertNotNull($created);
        $this->assertSame(route('orders.show', $order->id), $created['href']);
    }

    public function test_timeline_includes_order_status_history_events(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'New');
        OrderStatusHistory::create([
            'order_id' => $order->id,
            'old_status' => 'New',
            'new_status' => 'Confirmed',
            'changed_by' => $this->admin->id,
            'created_at' => now(),
        ]);

        $timeline = $this->timelineFor($customer);
        $statusChanges = collect($timeline)->where('type', 'order_status_changed');
        $this->assertCount(1, $statusChanges);
        $this->assertStringContainsString('changed from New to Confirmed', $statusChanges->first()['title']);
    }

    public function test_timeline_sorts_events_newest_first(): void
    {
        // The customer_created event is the OLDEST; any subsequent order
        // is newer. After sorting, the order_created event should sit
        // ahead of customer_created.
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'New');
        // Force the order's created_at to be 60s after the customer's so
        // the sort is unambiguous. `update()` ignores `created_at`
        // because it's a managed timestamp and not in `$fillable` —
        // `forceFill` bypasses that. `unguarded` would also work.
        $order->forceFill(['created_at' => $customer->created_at->copy()->addMinute()])->save();

        $timeline = $this->timelineFor($customer);
        // First event is the order_created (newer); customer_created
        // sits at index 1.
        $this->assertSame('order_created', $timeline[0]['type']);
        $this->assertSame('customer_created', $timeline[1]['type']);
    }

    public function test_timeline_limits_event_count(): void
    {
        // Create a customer with 50 orders → 50 order_created +
        // 1 customer_created = 51 raw events. Helper caps the merged
        // list at 30.
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 50; $i++) {
            $this->makeOrder($customer, status: 'New');
        }

        $timeline = $this->timelineFor($customer);
        $this->assertLessThanOrEqual(30, count($timeline));
    }

    public function test_timeline_events_include_safe_links(): void
    {
        // Order events MUST link to the order show route.
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'Delivered');

        $timeline = $this->timelineFor($customer);
        $orderEvt = collect($timeline)->firstWhere('type', 'order_created');
        $this->assertSame(route('orders.show', $order->id), $orderEvt['href']);
    }

    public function test_timeline_does_not_include_other_customer_events(): void
    {
        // Customer A has an order, customer B does not. B's timeline
        // must NEVER contain A's order event.
        $a = $this->makeCustomer(['name' => 'A']);
        $b = $this->makeCustomer(['name' => 'B']);
        $this->makeOrder($a, status: 'New');

        $timeline = $this->timelineFor($b);
        $types = array_column($timeline, 'type');
        $this->assertNotContains('order_created', $types, "B's timeline leaked an A-only order event.");
        $this->assertContains('customer_created', $types);
    }

    public function test_customer_with_no_orders_still_has_customer_created_event(): void
    {
        $customer = $this->makeCustomer();

        $timeline = $this->timelineFor($customer);
        $this->assertCount(1, $timeline);
        $this->assertSame('customer_created', $timeline[0]['type']);
    }

    public function test_timeline_includes_return_created_event(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'Delivered');
        $reason = ReturnReason::firstOrCreate(
            ['name' => 'Test Reason'],
            ['status' => 'Active'],
        );
        OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'return_reason_id' => $reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Good',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $timeline = $this->timelineFor($customer);
        $returnEvents = collect($timeline)->where('type', 'return_created');
        $this->assertCount(1, $returnEvents);
        $this->assertStringContainsString('Return RET-', $returnEvents->first()['title']);
    }

    public function test_timeline_includes_refund_created_event(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'Delivered');
        Refund::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'reason' => 'Test',
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $timeline = $this->timelineFor($customer);
        $refundEvents = collect($timeline)->where('type', 'refund_created');
        $this->assertCount(1, $refundEvents);
        $this->assertStringContainsString('Refund request', $refundEvents->first()['title']);
    }

    public function test_timeline_emits_refund_state_events_when_timestamps_set(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeOrder($customer, status: 'Delivered');
        Refund::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'amount' => 100.00,
            'reason' => 'Test',
            'status' => 'approved',
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $timeline = $this->timelineFor($customer);
        $types = array_column($timeline, 'type');
        $this->assertContains('refund_created', $types);
        $this->assertContains('refund_approved', $types);
    }

    /* ─── helpers ─── */

    /**
     * GET Customer Show as the admin and return the `timeline` prop.
     * Centralises the assertion that the route returned 200 + the
     * prop is present so each test reads cleanly.
     */
    private function timelineFor(Customer $customer): array
    {
        $response = $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk();
        $page = $response->viewData('page');
        $this->assertIsArray($page);
        $this->assertArrayHasKey('props', $page);
        $this->assertArrayHasKey('timeline', $page['props']);
        return $page['props']['timeline'];
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-3 Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(Customer $customer, string $status = 'New'): Order
    {
        return Order::create([
            'order_number' => 'ORD-C3-' . uniqid(),
            'entry_code' => 'TST',
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->primary_phone,
            'customer_address' => $customer->default_address,
            'city' => $customer->city,
            'country' => $customer->country,
            'currency_code' => 'EGP',
            'total_amount' => 100,
            'status' => $status,
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
    }
}
