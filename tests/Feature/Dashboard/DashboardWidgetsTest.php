<?php

namespace Tests\Feature\Dashboard;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\DashboardMetricsService;
use App\Services\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R15 — the four fulfilment dashboard widgets:
 *   1. SLA summary        (widgets.sla — sourced from ReportsService::sla)
 *   2. on-time-ship %     (derived from widgets.sla.metrics.ship)
 *   3. return rate        (widgets.return_rate)
 *   4. ageing-by-status   (widgets.ageing_by_status)
 *
 * Pins the controller wiring + permission gate, and the math of the two
 * new DashboardMetricsService methods. SLA percentile math itself is
 * pinned by SlaReportTest — here we only confirm the dashboard reuses
 * ReportsService::sla() rather than recomputing it.
 */
class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;
    private Customer $customer;
    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->orderService = app(OrderService::class);

        $this->product = Product::create([
            'sku' => 'R15-DASH-001',
            'name' => 'R15 Dashboard Test SKU',
            'description' => 'fixture',
            'cost_price' => 50,
            'selling_price' => 200,
            'marketer_trade_price' => 150,
            'minimum_selling_price' => 100,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'R15 Dashboard Test Customer',
            'primary_phone' => '01055554444',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Dashboard Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ───────────────────── controller wiring ───────────────────── */

    public function test_dashboard_emits_all_three_fulfilment_widgets_for_orders_viewer(): void
    {
        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('widgets.sla.metrics.confirm')
                ->has('widgets.sla.metrics.ship')
                ->has('widgets.sla.metrics.deliver')
                ->has('widgets.return_rate')
                ->has('widgets.ageing_by_status')
            );
    }

    public function test_fulfilment_widgets_absent_without_orders_view_permission(): void
    {
        $user = $this->userWith([]); // authenticated, non-marketer, zero permissions

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->missing('widgets.sla')
                ->missing('widgets.return_rate')
                ->missing('widgets.ageing_by_status')
            );
    }

    public function test_sla_widget_reflects_reports_service_output(): void
    {
        // One order created TODAY, confirmed 5h later — proves the
        // dashboard genuinely runs ReportsService::sla() over live data.
        // (Anchored to today, not startOfMonth+1, so the cohort window
        // [monthStart..today] always covers the fixture even on day 1.)
        $base = CarbonImmutable::today()->setTime(8, 0);
        $order = $this->placeOrder();
        $order->forceFill([
            'created_at' => $base,
            'confirmed_at' => $base->addHours(5),
        ])->save();

        $this->actingAs($this->admin)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('widgets.sla.metrics.confirm.count', 1)
            );
    }

    /* ───────────────────── returnRateMtd math ───────────────────── */

    public function test_return_rate_mtd_computes_over_terminal_cohort(): void
    {
        // Terminal cohort: 2 Delivered, 1 Returned, 1 Cancelled.
        $this->orderWithStatus('Delivered');
        $this->orderWithStatus('Delivered');
        $this->orderWithStatus('Returned');
        $this->orderWithStatus('Cancelled');
        // An open order — excluded from the cohort entirely.
        $this->orderWithStatus('Confirmed');

        $r = app(DashboardMetricsService::class)
            ->returnRateMtd(CarbonImmutable::today()->startOfMonth());

        $this->assertSame(4, $r['resolved']);
        $this->assertSame(1, $r['returned']);
        $this->assertSame(25.0, $r['rate']);
    }

    public function test_return_rate_mtd_is_null_for_an_empty_cohort(): void
    {
        $r = app(DashboardMetricsService::class)
            ->returnRateMtd(CarbonImmutable::today()->startOfMonth());

        $this->assertSame(0, $r['resolved']);
        $this->assertNull($r['rate']);
    }

    /* ───────────────────── ageingByStatus math ───────────────────── */

    public function test_ageing_by_status_buckets_open_orders_by_age(): void
    {
        $this->orderWithStatusAndAge('New', 5);        // over 3d
        $this->orderWithStatusAndAge('New', 1);
        $this->orderWithStatusAndAge('Confirmed', 10); // over 3d

        $rows = collect(app(DashboardMetricsService::class)->ageingByStatus())
            ->keyBy('status');

        $new = $rows['New'];
        $this->assertSame(2, $new['count']);
        $this->assertSame(3.0, $new['avg_age_days']); // (5 + 1) / 2
        $this->assertSame(5, $new['max_age_days']);
        $this->assertSame(1, $new['over_3d']);

        $confirmed = $rows['Confirmed'];
        $this->assertSame(1, $confirmed['count']);
        $this->assertSame(10, $confirmed['max_age_days']);
        $this->assertSame(1, $confirmed['over_3d']);
    }

    public function test_ageing_by_status_excludes_terminal_orders(): void
    {
        $this->orderWithStatus('Delivered');
        $this->orderWithStatus('Returned');
        $this->orderWithStatus('Cancelled');
        $this->orderWithStatus('New');

        $rows = collect(app(DashboardMetricsService::class)->ageingByStatus());

        // ageingByStatus zero-fills every open status; only the open
        // 'New' order should contribute a count.
        $this->assertSame(1, $rows->sum('count'));
        $this->assertSame(1, $rows->firstWhere('status', 'New')['count']);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function placeOrder(): Order
    {
        return $this->orderService->createFromPayload([
            'customer_id' => $this->customer->id,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'source' => 'phpunit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'quantity' => 1,
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);
    }

    private function orderWithStatus(string $status): Order
    {
        $order = $this->placeOrder();
        $order->forceFill(['status' => $status])->save();
        return $order->fresh();
    }

    private function orderWithStatusAndAge(string $status, int $daysOld): Order
    {
        $order = $this->placeOrder();
        $order->forceFill([
            'status' => $status,
            'created_at' => CarbonImmutable::now()->subDays($daysOld),
        ])->save();
        return $order->fresh();
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Dashboard Test ' . uniqid(),
            'slug' => 'dashboard-test-' . uniqid(),
            'description' => 'Test scope.',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );

        return User::create([
            'name' => 'Dashboard Test User',
            'email' => 'dashboard+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
