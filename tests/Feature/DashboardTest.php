<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\Ticket;
use App\Models\User;
use App\Services\DashboardMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 1 dashboard coverage. Asserts the new KPIs (delivered today,
 * active shipments, open tickets) read from the correct columns, and
 * that the `latest_orders` payload is gated server-side by `orders.view`
 * so users without that permission cannot see customer names, totals,
 * or statuses through Inertia props.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ShippingCompany $carrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->carrier = ShippingCompany::firstOrFail();

        $this->customer = Customer::create([
            'name' => 'Dashboard Test Customer',
            'primary_phone' => '01099990000',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Dashboard Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── Delivered today ────────────────────── */

    public function test_delivered_today_uses_delivered_at_not_created_at(): void
    {
        $today = CarbonImmutable::today();
        $yesterday = $today->subDay();

        // Counted: created last week, delivered today.
        $this->makeOrder([
            'created_at' => $today->subDays(5),
            'status' => 'Delivered',
            'delivered_at' => $today->setTime(10, 0),
        ]);

        // Not counted: created today but never delivered (delivered_at null).
        $this->makeOrder([
            'created_at' => $today->setTime(9, 0),
            'status' => 'New',
            'delivered_at' => null,
        ]);

        // Not counted: created today, status = 'Delivered', but delivered_at
        // is yesterday — only delivered_at decides "today".
        $this->makeOrder([
            'created_at' => $today->setTime(8, 0),
            'status' => 'Delivered',
            'delivered_at' => $yesterday->setTime(20, 0),
        ]);

        $metrics = app(DashboardMetricsService::class);
        $counts = $metrics->deliveredCounts($today, $today->startOfMonth());

        $this->assertSame(1, $counts['today'], 'Only the order with delivered_at=today should count.');
    }

    public function test_delivered_mtd_counts_all_deliveries_this_month(): void
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        $this->makeOrder(['delivered_at' => $monthStart->setTime(12, 0)]);
        $this->makeOrder(['delivered_at' => $today->setTime(9, 0)]);
        // Before the month — not counted.
        $this->makeOrder(['delivered_at' => $monthStart->subDay()->setTime(12, 0)]);
        // Null delivered_at — not counted.
        $this->makeOrder(['delivered_at' => null]);

        $counts = app(DashboardMetricsService::class)->deliveredCounts($today, $monthStart);

        $this->assertSame(2, $counts['mtd']);
    }

    /* ────────────────────── Active shipments ────────────────────── */

    public function test_active_shipments_excludes_delivered_returned_lost_and_not_shipped(): void
    {
        // Active set (5 distinct active statuses).
        foreach (['Assigned', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delayed'] as $status) {
            $this->makeShipment($status);
        }
        // Excluded set.
        foreach (['Delivered', 'Returned', 'Lost', 'Not Shipped'] as $status) {
            $this->makeShipment($status);
        }

        $count = app(DashboardMetricsService::class)->activeShipmentsCount();

        $this->assertSame(5, $count);
    }

    /* ────────────────────── Open tickets ────────────────────── */

    public function test_open_tickets_counts_open_and_in_progress_only(): void
    {
        Ticket::create([
            'user_id' => $this->admin->id,
            'subject' => 'Open ticket',
            'message' => 'open issue',
            'status' => Ticket::STATUS_OPEN,
        ]);
        Ticket::create([
            'user_id' => $this->admin->id,
            'subject' => 'In progress ticket',
            'message' => 'in progress issue',
            'status' => Ticket::STATUS_IN_PROGRESS,
        ]);
        Ticket::create([
            'user_id' => $this->admin->id,
            'subject' => 'Closed ticket',
            'message' => 'resolved',
            'status' => Ticket::STATUS_CLOSED,
        ]);

        $count = app(DashboardMetricsService::class)->openTicketsCount();

        $this->assertSame(2, $count);
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_dashboard_page_loads_successfully_for_admin(): void
    {
        $this->makeOrder(['delivered_at' => CarbonImmutable::today()->setTime(12, 0)]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->has('kpis.delivered_today')
            ->has('kpis.active_shipments')
            ->has('kpis.open_tickets')
        );
    }

    public function test_latest_orders_is_returned_to_users_with_orders_view(): void
    {
        $this->makeOrder([
            'customer_name' => 'Visible Customer',
            'status' => 'Confirmed',
        ]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('tables.latest_orders.0.customer_name', 'Visible Customer')
            ->where('permissions.orders_view', true)
        );
    }

    public function test_latest_orders_is_empty_for_users_without_orders_view(): void
    {
        $this->makeOrder([
            'customer_name' => 'Sensitive Customer',
            'status' => 'Confirmed',
        ]);

        $userWithoutOrdersView = $this->makeRestrictedUser(['shipping.view']);

        $response = $this->actingAs($userWithoutOrdersView)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('tables.latest_orders', [])
            ->where('permissions.orders_view', false)
        );

        // Belt-and-braces: the customer name must not appear anywhere in
        // the rendered response (Inertia serialises the full prop tree
        // into the page payload on first load).
        $this->assertStringNotContainsString('Sensitive Customer', $response->getContent());
    }

    public function test_open_tickets_kpi_is_absent_for_users_without_tickets_view(): void
    {
        $userWithoutTicketsView = $this->makeRestrictedUser(['orders.view']);

        $response = $this->actingAs($userWithoutTicketsView)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('kpis.open_tickets')
            ->where('permissions.tickets_view', false)
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    /**
     * Creates a User with a fresh role granting only the listed
     * permission slugs. Used to test permission-gated server props.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Test Restricted ' . uniqid(),
            'slug' => 'test-restricted-' . uniqid(),
            'description' => 'Temporary role for dashboard permission tests.',
            'is_system' => false,
        ]);

        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted User',
            'email' => 'restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    /**
     * Minimal Order::create() — only sets the columns the dashboard
     * cares about. Order_number is generated per call to avoid the
     * unique constraint.
     */
    private function makeOrder(array $overrides = []): Order
    {
        static $counter = 0;
        $counter++;

        $defaults = [
            'order_number' => 'DASH-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => 'New',
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => 100,
            'created_by' => $this->admin->id,
        ];

        return Order::create(array_merge($defaults, $overrides));
    }

    private function makeShipment(string $status): Shipment
    {
        $order = $this->makeOrder();
        return Shipment::create([
            'order_id' => $order->id,
            'shipping_company_id' => $this->carrier->id,
            'tracking_number' => 'TRK-' . uniqid(),
            'shipping_status' => $status,
            'assigned_at' => now(),
            'created_by' => $this->admin->id,
        ]);
    }
}
