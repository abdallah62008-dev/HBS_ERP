<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderService;
use App\Services\ReportsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R16 — Fulfilment SLA report.
 *
 * Pins both layers:
 *   1. `ReportsService::sla()` — the percentile + on-time/exceeded math,
 *      the not-yet-reached-stage exclusion, and the date-range cohort.
 *   2. The `GET /reports/sla` route — permission gate + Inertia page.
 *
 * Default thresholds (hours): confirm 24, ship 48, deliver 120 (5 days).
 */
class SlaReportTest extends TestCase
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
            'sku' => 'R16-SLA-001',
            'name' => 'R16 SLA Test SKU',
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
            'name' => 'R16 SLA Test Customer',
            'primary_phone' => '01066665555',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 SLA Street',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
    }

    /* ───────────────────── service math ───────────────────── */

    public function test_sla_service_computes_confirm_percentiles_and_on_time_split(): void
    {
        $base = Carbon::parse('2026-05-05 08:00:00');

        // confirm durations 10h, 20h, 30h, 50h vs threshold 24h.
        foreach ([10, 20, 30, 50] as $h) {
            $this->orderWithTimings($base, $base->copy()->addHours($h), null, null);
        }

        $m = app(ReportsService::class)->sla('2026-05-01', '2026-05-31')['metrics']['confirm'];

        $this->assertSame(4, $m['count']);
        $this->assertSame(25.0, $m['p50_hours']);   // median of [10,20,30,50]
        $this->assertSame(47.0, $m['p95_hours']);   // interp at rank 2.85 of [10,20,30,50]
        $this->assertSame(27.5, $m['avg_hours']);
        $this->assertSame(2, $m['on_time']);        // 10h, 20h ≤ 24h
        $this->assertSame(2, $m['exceeded']);       // 30h, 50h > 24h
        $this->assertSame(50.0, $m['on_time_rate']);
        $this->assertSame(24.0, $m['threshold_hours']);
    }

    public function test_sla_service_ship_and_deliver_stages_compute_against_thresholds(): void
    {
        $base = Carbon::parse('2026-05-05 08:00:00');

        // order 1: ship 24h (on time ≤48h), deliver 48h (on time ≤120h)
        $this->orderWithTimings($base, $base->copy()->addHours(2), $base->copy()->addHours(26), $base->copy()->addHours(74));
        // order 2: ship 72h (exceeded), deliver 168h (exceeded)
        $this->orderWithTimings($base, $base->copy()->addHours(2), $base->copy()->addHours(74), $base->copy()->addHours(242));

        $result = app(ReportsService::class)->sla('2026-05-01', '2026-05-31');

        $ship = $result['metrics']['ship'];
        $this->assertSame(2, $ship['count']);
        $this->assertSame(1, $ship['on_time']);
        $this->assertSame(1, $ship['exceeded']);

        $deliver = $result['metrics']['deliver'];
        $this->assertSame(2, $deliver['count']);
        $this->assertSame(1, $deliver['on_time']);
        $this->assertSame(1, $deliver['exceeded']);
    }

    public function test_sla_service_excludes_orders_that_have_not_reached_a_stage(): void
    {
        $base = Carbon::parse('2026-05-05 08:00:00');

        // Confirmed but never shipped.
        $this->orderWithTimings($base, $base->copy()->addHours(5), null, null);
        // Fully delivered.
        $this->orderWithTimings($base, $base->copy()->addHours(5), $base->copy()->addHours(20), $base->copy()->addHours(60));

        $result = app(ReportsService::class)->sla('2026-05-01', '2026-05-31');

        $this->assertSame(2, $result['metrics']['confirm']['count']);
        $this->assertSame(1, $result['metrics']['ship']['count']);
        $this->assertSame(1, $result['metrics']['deliver']['count']);
    }

    public function test_sla_service_returns_nulls_for_an_empty_stage(): void
    {
        $m = app(ReportsService::class)->sla('2026-05-01', '2026-05-31')['metrics']['deliver'];

        $this->assertSame(0, $m['count']);
        $this->assertNull($m['p50_hours']);
        $this->assertNull($m['p95_hours']);
        $this->assertNull($m['avg_hours']);
        $this->assertNull($m['on_time_rate']);
    }

    public function test_date_range_filter_narrows_the_cohort(): void
    {
        $inRange = Carbon::parse('2026-05-10 08:00:00');
        $outOfRange = Carbon::parse('2026-04-10 08:00:00');

        $this->orderWithTimings($inRange, $inRange->copy()->addHours(5), null, null);
        $this->orderWithTimings($outOfRange, $outOfRange->copy()->addHours(5), null, null);

        $result = app(ReportsService::class)->sla('2026-05-01', '2026-05-31');

        $this->assertSame(1, $result['metrics']['confirm']['count']);
    }

    /* ───────────────────── route + page ───────────────────── */

    public function test_sla_report_requires_reports_shipping_permission(): void
    {
        $denied = $this->userWith(['reports.view']); // no reports.shipping
        $this->actingAs($denied)->get('/reports/sla')->assertForbidden();

        $allowed = $this->userWith(['reports.view', 'reports.shipping']);
        $this->actingAs($allowed)->get('/reports/sla')->assertOk();
    }

    public function test_sla_report_renders_inertia_page_with_metrics(): void
    {
        $base = Carbon::parse('2026-05-05 08:00:00');
        $this->orderWithTimings($base, $base->copy()->addHours(10), null, null);

        $this->actingAs($this->admin)
            ->get('/reports/sla?from=2026-05-01&to=2026-05-31')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Sla')
                ->where('metrics.confirm.count', 1)
                ->has('metrics.ship')
                ->has('metrics.deliver')
                ->has('thresholds')
            );
    }

    public function test_opening_the_report_does_not_mutate_orders(): void
    {
        $base = Carbon::parse('2026-05-05 08:00:00');
        $order = $this->orderWithTimings($base, $base->copy()->addHours(10), null, null);
        $updatedBefore = (string) $order->updated_at;

        app(ReportsService::class)->sla('2026-05-01', '2026-05-31');

        $this->assertSame(1, Order::count());
        $this->assertSame($updatedBefore, (string) $order->fresh()->updated_at);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Build a real order then force its four lifecycle timestamps.
     * `sla()` reads only order timestamps, so no inventory / status walk
     * is needed — forceFill lets a test place an order anywhere on the
     * timeline deterministically.
     */
    private function orderWithTimings(
        Carbon $createdAt,
        ?Carbon $confirmedAt,
        ?Carbon $shippedAt,
        ?Carbon $deliveredAt,
    ): Order {
        $order = $this->orderService->createFromPayload([
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

        $order->forceFill([
            'created_at' => $createdAt,
            'confirmed_at' => $confirmedAt,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
        ])->save();

        return $order->fresh();
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'SLA Report Test ' . uniqid(),
            'slug' => 'sla-report-test-' . uniqid(),
            'description' => 'Test scope.',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );

        return User::create([
            'name' => 'SLA Report Test User',
            'email' => 'sla-report+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
