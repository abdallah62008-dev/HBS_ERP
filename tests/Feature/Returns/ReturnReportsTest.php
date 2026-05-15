<?php

namespace Tests\Feature\Returns;

use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Returns Phase 7 — Reporting & QA.
 *
 * Pins the `/reports/returns` analytics endpoint:
 *   - permission gating (`reports.view` parent group + `reports.profit` leaf)
 *   - the prop shape (totals, by_status, by_condition, by_reason, top_products)
 *   - the date-range filter narrows every metric the same way
 *   - the report is read-only — no rows are mutated by viewing it
 *
 * No new permission slug is introduced; the existing `reports.profit`
 * gate is retained because the page surfaces financial exposure totals
 * (`refund_amount` + `shipping_loss_amount` sums) which were already
 * sensitive enough to live behind that slug.
 */
class ReturnReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ReturnReason $reason;
    private ReturnReason $secondReason;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->reason = ReturnReason::firstOrFail();
        $this->secondReason = ReturnReason::firstOrCreate(
            ['name' => 'Reports Test Second Reason'],
            ['status' => 'Active'],
        );
        $this->customer = Customer::create([
            'name' => 'Reports Test Customer',
            'primary_phone' => '01099996666',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Report Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Permission gating ────────────────────── */

    public function test_user_with_reports_profit_can_open_returns_report(): void
    {
        $user = $this->userWith(['reports.view', 'reports.profit']);

        $this->actingAs($user)
            ->get('/reports/returns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Returns'));
    }

    public function test_user_without_reports_view_is_blocked_at_the_parent_group(): void
    {
        $user = $this->userWith(['returns.view']); // no reports.* slugs at all

        $this->actingAs($user)
            ->get('/reports/returns')
            ->assertForbidden();
    }

    public function test_user_with_reports_view_but_not_reports_profit_is_blocked_at_the_leaf(): void
    {
        // `reports.view` opens the /reports/* group but the returns
        // leaf is gated by `reports.profit`. Pin the SoD between
        // operational sales reports and financial-exposure reports.
        $user = $this->userWith(['reports.view', 'reports.sales']);

        $this->actingAs($user)
            ->get('/reports/returns')
            ->assertForbidden();
    }

    /* ────────────────────── 2. Prop shape ────────────────────── */

    public function test_report_exposes_totals_with_active_and_resolved_split(): void
    {
        // 2 active, 1 resolved.
        $this->makeReturn(status: 'Pending', refundAmount: 100, shippingLoss: 10);
        $this->makeReturn(status: 'Inspected', refundAmount: 50, shippingLoss: 5);
        $this->makeReturn(status: 'Restocked', refundAmount: 200, shippingLoss: 20);

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertInertia(fn ($page) => $page
                ->component('Reports/Returns')
                ->where('totals.total', fn ($v) => (int) $v === 3)
                ->where('totals.active', fn ($v) => (int) $v === 2)
                ->where('totals.resolved', fn ($v) => (int) $v === 1)
                ->where('totals.restocked', fn ($v) => (int) $v === 1)
                ->where('totals.refund_total', fn ($v) => (float) $v === 350.0)
                ->where('totals.shipping_loss_total', fn ($v) => (float) $v === 35.0)
            );
    }

    public function test_by_status_breakdown_is_zero_filled_for_every_status(): void
    {
        $this->makeReturn(status: 'Pending');
        $this->makeReturn(status: 'Pending');
        $this->makeReturn(status: 'Restocked');

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertInertia(fn ($page) => $page
                ->where('by_status', function ($rows) {
                    // Every status in OrderReturn::STATUSES must appear,
                    // including the zero-count buckets.
                    $byName = collect($rows)->keyBy('status');
                    foreach (OrderReturn::STATUSES as $status) {
                        if (! $byName->has($status)) {
                            return false;
                        }
                    }
                    return $byName->get('Pending')['count'] === 2
                        && $byName->get('Restocked')['count'] === 1
                        && $byName->get('Closed')['count'] === 0
                        && $byName->get('Pending')['bucket'] === 'active'
                        && $byName->get('Restocked')['bucket'] === 'resolved';
                })
            );
    }

    public function test_by_condition_breakdown_includes_every_condition_value(): void
    {
        $this->makeReturn(status: 'Pending', condition: 'Good');
        $this->makeReturn(status: 'Pending', condition: 'Good');
        $this->makeReturn(status: 'Inspected', condition: 'Damaged');

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertInertia(fn ($page) => $page
                ->where('by_condition', function ($rows) {
                    $byName = collect($rows)->keyBy('condition');
                    foreach (OrderReturn::CONDITIONS as $condition) {
                        if (! $byName->has($condition)) {
                            return false;
                        }
                    }
                    return $byName->get('Good')['count'] === 2
                        && $byName->get('Damaged')['count'] === 1
                        && $byName->get('Missing Parts')['count'] === 0
                        && $byName->get('Unknown')['count'] === 0;
                })
            );
    }

    public function test_by_reason_breakdown_groups_and_orders_by_count_desc(): void
    {
        $this->makeReturn(status: 'Pending', reasonId: $this->reason->id);
        $this->makeReturn(status: 'Pending', reasonId: $this->reason->id);
        $this->makeReturn(status: 'Pending', reasonId: $this->secondReason->id);

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertInertia(fn ($page) => $page
                ->where('by_reason', function ($rows) {
                    $rows = collect($rows);
                    if ($rows->count() < 2) {
                        return false;
                    }
                    // Reason "1" has 2 returns; reason "2" has 1.
                    // The query orders DESC, so reason "1" must come first.
                    $first = $rows->first();
                    $second = $rows->skip(1)->first();
                    return $first['count'] === 2
                        && $second['count'] === 1
                        && (int) $first['count'] >= (int) $second['count'];
                })
            );
    }

    public function test_top_products_aggregates_units_and_distinct_returns(): void
    {
        $productA = Product::create([
            'sku' => 'RPT-A',
            'name' => 'Reports Product A',
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
        $productB = Product::create([
            'sku' => 'RPT-B',
            'name' => 'Reports Product B',
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

        // Two returns containing product A, one return containing product B.
        $ret1 = $this->makeReturn(status: 'Pending');
        $this->attachItem($ret1, $productA, quantity: 3);
        $ret2 = $this->makeReturn(status: 'Pending');
        $this->attachItem($ret2, $productA, quantity: 2);
        $ret3 = $this->makeReturn(status: 'Pending');
        $this->attachItem($ret3, $productB, quantity: 1);

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertInertia(fn ($page) => $page
                ->where('top_products', function ($rows) use ($productA, $productB) {
                    $rows = collect($rows);
                    $a = $rows->firstWhere('product_id', $productA->id);
                    $b = $rows->firstWhere('product_id', $productB->id);
                    return $a !== null && $b !== null
                        && (int) $a['return_count'] === 2
                        && (int) $a['unit_count'] === 5
                        && (int) $b['return_count'] === 1
                        && (int) $b['unit_count'] === 1;
                })
            );
    }

    /* ────────────────────── 3. Date filter ────────────────────── */

    public function test_date_range_filter_narrows_every_metric(): void
    {
        // Old return — outside the window.
        $old = $this->makeReturn(status: 'Pending', refundAmount: 999);
        OrderReturn::where('id', $old->id)->update([
            'created_at' => '2026-04-15 12:00:00',
            'updated_at' => '2026-04-15 12:00:00',
        ]);

        // In-window return.
        $new = $this->makeReturn(status: 'Pending', refundAmount: 100);
        OrderReturn::where('id', $new->id)->update([
            'created_at' => '2026-05-10 12:00:00',
            'updated_at' => '2026-05-10 12:00:00',
        ]);

        $this->actingAs($this->admin)
            ->get('/reports/returns?from=2026-05-01&to=2026-05-31')
            ->assertInertia(fn ($page) => $page
                ->where('from', '2026-05-01')
                ->where('to', '2026-05-31')
                ->where('totals.total', fn ($v) => (int) $v === 1)
                ->where('totals.refund_total', fn ($v) => (float) $v === 100.0)
            );
    }

    /* ────────────────────── 4. Read-only contract ────────────────────── */

    public function test_opening_the_report_does_not_mutate_returns_or_finance_rows(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 50);
        $originalStatus = $return->return_status;
        $originalCondition = $return->product_condition;
        $originalAmount = (string) $return->refund_amount;
        $originalUpdatedAt = $return->updated_at;
        $refundCountBefore = Refund::count();
        $cashboxCountBefore = CashboxTransaction::count();

        $this->actingAs($this->admin)->get('/reports/returns')->assertOk();

        // Return row untouched — state machine fields, money fields, and
        // `updated_at` must all be unchanged after a pure-read endpoint.
        $fresh = OrderReturn::findOrFail($return->id);
        $this->assertSame($originalStatus, $fresh->return_status);
        $this->assertSame($originalCondition, $fresh->product_condition);
        $this->assertSame($originalAmount, (string) $fresh->refund_amount);
        $this->assertEquals($originalUpdatedAt, $fresh->updated_at,
            'Opening the report must not bump updated_at on any return.'
        );

        // No new refund or cashbox transactions.
        $this->assertSame($refundCountBefore, Refund::count());
        $this->assertSame($cashboxCountBefore, CashboxTransaction::count());
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'RPT-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => 'Delivered',
            'collection_status' => 'Collected',
            'shipping_status' => 'Delivered',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => 200,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * @param  array<string,int>|null  $_unused  unused — kept for clarity at call sites
     */
    private function makeReturn(
        string $status = 'Pending',
        float $refundAmount = 0,
        float $shippingLoss = 0,
        string $condition = 'Unknown',
        ?int $reasonId = null,
    ): OrderReturn {
        $order = $this->makeOrder();
        return OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $reasonId ?? $this->reason->id,
            'return_status' => $status,
            'product_condition' => $condition,
            'refund_amount' => $refundAmount,
            'shipping_loss_amount' => $shippingLoss,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    private function attachItem(OrderReturn $return, Product $product, int $quantity): OrderItem
    {
        return OrderItem::create([
            'order_id' => $return->order_id,
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'quantity' => $quantity,
            'unit_price' => 200,
            'total_price' => 200 * $quantity,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Returns Report Test ' . uniqid(),
            'slug' => 'returns-report-test-' . uniqid(),
            'description' => 'Returns report test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Returns Report Test User',
            'email' => 'returns-report+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
