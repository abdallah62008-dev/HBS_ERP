<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ExpenseCategory;
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
use Illuminate\Support\Facades\DB;
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

    /* ────────────────────── Phase 2: Period selector ────────────────────── */

    public function test_period_range_resolves_each_selector_key(): void
    {
        $metrics = app(DashboardMetricsService::class);
        $today = CarbonImmutable::today();

        $todayRange = $metrics->periodRange('today');
        $this->assertSame('today', $todayRange['key']);
        $this->assertTrue($todayRange['from']->equalTo($today));
        $this->assertTrue($todayRange['to']->equalTo($today));

        $sevenD = $metrics->periodRange('7d');
        $this->assertSame('7d', $sevenD['key']);
        $this->assertTrue($sevenD['from']->equalTo($today->subDays(6)));
        $this->assertTrue($sevenD['to']->equalTo($today));

        $mtd = $metrics->periodRange('mtd');
        $this->assertSame('mtd', $mtd['key']);
        $this->assertTrue($mtd['from']->equalTo($today->startOfMonth()));

        $fytd = $metrics->periodRange('fytd');
        $this->assertSame('fytd', $fytd['key']);
        // FYTD start should be on/before today and on/before mtd.from
        // (the open fiscal year started at or before today).
        $this->assertTrue($fytd['from']->lessThanOrEqualTo($today));
    }

    public function test_period_range_falls_back_to_today_for_unknown_key(): void
    {
        $range = app(DashboardMetricsService::class)->periodRange('garbage-input');

        $this->assertSame('today', $range['key']);
        $this->assertSame('Today', $range['label']);
    }

    public function test_period_totals_filters_orders_by_period_window(): void
    {
        $today = CarbonImmutable::today();

        // In window (today).
        $this->makeOrder([
            'created_at' => $today->setTime(10, 0),
            'total_amount' => 250,
        ]);
        // Out of window (10 days ago).
        $this->makeOrder([
            'created_at' => $today->subDays(10)->setTime(10, 0),
            'total_amount' => 999,
        ]);

        $metrics = app(DashboardMetricsService::class);

        $todayTotals = $metrics->periodTotals($today, $today);
        $this->assertSame(1, $todayTotals['orders']);
        $this->assertSame(250.0, $todayTotals['sales']);

        $sevenDay = $metrics->periodTotals($today->subDays(6), $today);
        $this->assertSame(1, $sevenDay['orders'], '10-day-old order is outside the 7-day window.');
    }

    public function test_dashboard_accepts_period_query_param(): void
    {
        foreach (['today', '7d', 'mtd', 'fytd'] as $key) {
            $response = $this->actingAs($this->admin)->get('/dashboard?period=' . $key);
            $response->assertOk();
            $response->assertInertia(fn ($page) => $page->where('period.value', $key));
        }
    }

    public function test_dashboard_period_value_falls_back_for_invalid_input(): void
    {
        $response = $this->actingAs($this->admin)->get('/dashboard?period=junk');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('period.value', 'today'));
    }

    /* ────────────────────── Phase 2: Delivery rate ────────────────────── */

    public function test_delivery_rate_mtd_handles_zero_denominator(): void
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        // Only non-resolved orders this month.
        $this->makeOrder(['status' => 'New', 'created_at' => $today]);
        $this->makeOrder(['status' => 'Confirmed', 'created_at' => $today]);

        $rate = app(DashboardMetricsService::class)->deliveryRateMtd($monthStart);

        $this->assertNull($rate['rate'], 'Zero resolved orders → rate must be null, not 0%.');
        $this->assertSame(0, $rate['delivered']);
        $this->assertSame(0, $rate['resolved']);
    }

    public function test_delivery_rate_mtd_calculates_correctly(): void
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        // 3 delivered + 1 returned + 1 cancelled = 5 resolved, 3 delivered = 60%
        $this->makeOrder(['status' => 'Delivered', 'created_at' => $today, 'delivered_at' => $today]);
        $this->makeOrder(['status' => 'Delivered', 'created_at' => $today, 'delivered_at' => $today]);
        $this->makeOrder(['status' => 'Delivered', 'created_at' => $today, 'delivered_at' => $today]);
        $this->makeOrder(['status' => 'Returned', 'created_at' => $today]);
        $this->makeOrder(['status' => 'Cancelled', 'created_at' => $today]);
        // Open order — excluded from both numerator and denominator.
        $this->makeOrder(['status' => 'Ready to Ship', 'created_at' => $today]);

        $rate = app(DashboardMetricsService::class)->deliveryRateMtd($monthStart);

        $this->assertSame(60.0, $rate['rate']);
        $this->assertSame(3, $rate['delivered']);
        $this->assertSame(5, $rate['resolved']);
    }

    /* ────────────────────── Phase 2: AOV MTD ────────────────────── */

    public function test_avg_order_value_mtd_excludes_cancelled_orders(): void
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        // Counted: 100 + 300 = 400 / 2 = 200
        $this->makeOrder(['total_amount' => 100, 'status' => 'New', 'created_at' => $today]);
        $this->makeOrder(['total_amount' => 300, 'status' => 'Confirmed', 'created_at' => $today]);
        // Excluded: cancelled
        $this->makeOrder(['total_amount' => 9999, 'status' => 'Cancelled', 'created_at' => $today]);

        $aov = app(DashboardMetricsService::class)->avgOrderValueMtd($monthStart);

        $this->assertSame(2, $aov['count']);
        $this->assertSame(400.0, $aov['total']);
        $this->assertSame(200.0, $aov['avg']);
    }

    public function test_avg_order_value_mtd_handles_zero_orders(): void
    {
        $aov = app(DashboardMetricsService::class)
            ->avgOrderValueMtd(CarbonImmutable::today()->startOfMonth());

        $this->assertSame(0, $aov['count']);
        $this->assertSame(0.0, $aov['avg']);
    }

    /* ────────────────────── Phase 2: Out of stock ────────────────────── */

    public function test_out_of_stock_count_uses_on_hand_zero_threshold(): void
    {
        // The Returns inventory test fixture seeds Warehouses + Products
        // already; here we just create a single product with zero on-hand
        // movement so the SUM-CASE returns 0.
        $product = \App\Models\Product::create([
            'sku' => 'OOS-001',
            'name' => 'Out of Stock SKU',
            'description' => 'test',
            'cost_price' => 10,
            'selling_price' => 20,
            'marketer_trade_price' => 15,
            'minimum_selling_price' => 15,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        // No inventory_movements rows for this product → on_hand = 0.
        $count = app(DashboardMetricsService::class)->outOfStockCount();

        $this->assertGreaterThanOrEqual(1, $count, 'Product with no stock movement should be out-of-stock.');
    }

    /* ────────────────────── Phase 2: Expenses MTD ────────────────────── */

    public function test_expenses_mtd_sums_amounts_within_window(): void
    {
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $category = ExpenseCategory::firstOrFail();

        // Insert via DB::table to avoid the Eloquent date cast round-trip
        // — we want byte-exact control over what hits the date column so
        // the window assertion is deterministic.
        DB::table('expenses')->insert([
            [
                'expense_category_id' => $category->id,
                'title' => 'In window',
                'amount' => 250.00,
                'currency_code' => 'EGP',
                'expense_date' => $today->toDateString(),
                'created_by' => $this->admin->id,
                'created_at' => $today,
                'updated_at' => $today,
            ],
            [
                'expense_category_id' => $category->id,
                'title' => 'Out of window',
                'amount' => 999.00,
                'currency_code' => 'EGP',
                'expense_date' => $monthStart->subDay()->toDateString(),
                'created_by' => $this->admin->id,
                'created_at' => $today,
                'updated_at' => $today,
            ],
        ]);

        $total = app(DashboardMetricsService::class)->expensesTotal($monthStart, $today);

        $this->assertSame(250.0, $total);
    }

    public function test_expenses_mtd_kpi_is_absent_for_users_without_expenses_view(): void
    {
        $user = $this->makeRestrictedUser(['orders.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('kpis.expenses_mtd')
            ->where('permissions.expenses_view', false)
        );
    }

    public function test_expenses_mtd_kpi_is_present_for_users_with_expenses_view(): void
    {
        $user = $this->makeRestrictedUser(['expenses.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('kpis.expenses_mtd')
            ->where('permissions.expenses_view', true)
        );
    }

    /* ────────────────────── Phase 2: Shipments by status ────────────────────── */

    public function test_shipments_by_status_widget_is_gated_by_shipping_view(): void
    {
        $this->makeShipment('In Transit');

        $userWithout = $this->makeRestrictedUser(['orders.view']);
        $responseNo = $this->actingAs($userWithout)->get('/dashboard');
        $responseNo->assertOk();
        $responseNo->assertInertia(fn ($page) => $page
            ->missing('widgets.shipments_by_status')
            ->where('permissions.shipping_view', false)
        );

        $userWith = $this->makeRestrictedUser(['shipping.view']);
        $responseYes = $this->actingAs($userWith)->get('/dashboard');
        $responseYes->assertOk();
        $responseYes->assertInertia(fn ($page) => $page
            ->has('widgets.shipments_by_status')
            ->where('permissions.shipping_view', true)
        );
    }

    public function test_shipments_by_status_aggregates_by_shipping_status(): void
    {
        $this->makeShipment('In Transit');
        $this->makeShipment('In Transit');
        $this->makeShipment('Delivered');

        $rows = app(DashboardMetricsService::class)->shipmentsByStatus();

        $byStatus = collect($rows)->keyBy('status');
        $this->assertSame(2, $byStatus['In Transit']['count']);
        $this->assertSame(1, $byStatus['Delivered']['count']);
    }

    /* ────────────────────── Phase 2: Out-of-stock + delivery-rate permission ────────────────────── */

    public function test_out_of_stock_kpi_is_absent_for_users_without_inventory_view(): void
    {
        $user = $this->makeRestrictedUser(['orders.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('kpis.out_of_stock')
            ->where('permissions.inventory_view', false)
        );
    }

    public function test_delivery_rate_and_aov_kpis_require_orders_view(): void
    {
        $user = $this->makeRestrictedUser(['shipping.view']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('kpis.delivery_rate_mtd')
            ->missing('kpis.avg_order_value_mtd')
            ->where('permissions.orders_view', false)
        );
    }

    /* ────────────────────── Phase 2: Audit anomalies deferred ────────────────────── */

    public function test_audit_anomalies_tile_is_intentionally_not_present(): void
    {
        // Phase 2 deferred this tile because audit_logs has no severity /
        // event_type / failed flag, and AuditLogService is caller-driven
        // with no canonical permission-denied or login-failed log point.
        // This test pins the deferral so the prop tree doesn't silently
        // grow an unreviewed audit anomaly key.
        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->missing('kpis.audit_anomalies')
            ->missing('widgets.audit_anomalies')
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
     *
     * `created_at` is not in $fillable for Order, and Eloquent
     * unconditionally writes its own timestamp on insert. If the caller
     * supplies `created_at` we follow up with a raw DB::table update
     * so the row actually carries the test-controlled date — vital for
     * period-window assertions.
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

        $order = Order::create(array_merge($defaults, $overrides));

        if (array_key_exists('created_at', $overrides) && $overrides['created_at'] !== null) {
            DB::table('orders')
                ->where('id', $order->id)
                ->update(['created_at' => $overrides['created_at']]);
            $order->refresh();
        }

        return $order;
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
