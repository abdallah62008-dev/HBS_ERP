<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\FinancePeriod;
use App\Models\FiscalYear;
use App\Models\Marketer;
use App\Models\MarketerPayout;
use App\Models\MarketerPriceGroup;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\FinancePeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Finance Phase 5F — Finance Period lifecycle + closed-period guard.
 *
 * Two coverage axes:
 *   1. Period CRUD + permissions + audit
 *   2. The guard fires from every cash-impacting service when a paid
 *      date lands in a closed period — and stays quiet for dates
 *      outside any closed period.
 */
class FinancePeriodTest extends TestCase
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
            'name' => 'Period Test Customer',
            'primary_phone' => '01077776666',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Period Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Period lifecycle / permissions ────────────────────── */

    public function test_user_with_create_can_create_period(): void
    {
        $user = $this->userWith(['finance_periods.create']);

        $this->actingAs($user)->post('/finance/periods', [
            'name' => 'May 2026',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ])->assertRedirect('/finance/periods');

        $p = FinancePeriod::firstOrFail();
        $this->assertSame('open', $p->status);
        $this->assertSame('May 2026', $p->name);
    }

    public function test_user_without_create_cannot_create_period(): void
    {
        $user = $this->userWith(['finance_periods.view']);

        $this->actingAs($user)->post('/finance/periods', [
            'name' => 'May 2026',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ])->assertForbidden();

        $this->assertSame(0, FinancePeriod::count());
    }

    public function test_invalid_date_range_is_rejected(): void
    {
        $user = $this->userWith(['finance_periods.create']);

        // end_date before start_date — caught by FormRequest's after_or_equal rule.
        $this->actingAs($user)->post('/finance/periods', [
            'name' => 'Bad',
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-01',
        ])->assertSessionHasErrors('end_date');

        $this->assertSame(0, FinancePeriod::count());
    }

    public function test_overlapping_period_is_rejected(): void
    {
        $user = $this->userWith(['finance_periods.create']);

        FinancePeriod::create([
            'name' => 'May 2026',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($user)->post('/finance/periods', [
            'name' => 'Overlap',
            'start_date' => '2026-05-15',
            'end_date' => '2026-06-15',
        ])->assertRedirect();

        $this->assertSame(1, FinancePeriod::count(), 'Overlap blocked — only the original row exists.');
    }

    public function test_user_with_close_permission_can_close_open_period(): void
    {
        $user = $this->userWith(['finance_periods.close']);
        $period = $this->makePeriod(status: 'open');

        $this->actingAs($user)->post('/finance/periods/' . $period->id . '/close')
            ->assertRedirect();

        $this->assertSame('closed', $period->fresh()->status);
        $this->assertSame($user->id, $period->fresh()->closed_by);
    }

    public function test_user_without_close_permission_cannot_close_period(): void
    {
        $user = $this->userWith(['finance_periods.view']);
        $period = $this->makePeriod(status: 'open');

        $this->actingAs($user)->post('/finance/periods/' . $period->id . '/close')
            ->assertForbidden();

        $this->assertSame('open', $period->fresh()->status);
    }

    public function test_user_with_reopen_permission_can_reopen_closed_period(): void
    {
        $user = $this->userWith(['finance_periods.reopen']);
        $period = $this->makePeriod(status: 'closed');

        $this->actingAs($user)->post('/finance/periods/' . $period->id . '/reopen')
            ->assertRedirect();

        $this->assertSame('open', $period->fresh()->status);
        $this->assertSame($user->id, $period->fresh()->reopened_by);
    }

    public function test_user_without_reopen_permission_cannot_reopen(): void
    {
        $user = $this->userWith(['finance_periods.view', 'finance_periods.close']);
        $period = $this->makePeriod(status: 'closed');

        $this->actingAs($user)->post('/finance/periods/' . $period->id . '/reopen')
            ->assertForbidden();

        $this->assertSame('closed', $period->fresh()->status);
    }

    public function test_no_delete_route_exists_for_finance_periods(): void
    {
        // Defensive — confirm no DELETE route is registered. Phase 5F
        // periods are permanent records.
        $delete = collect(\Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'finance/periods'))
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true));

        $this->assertSame(0, $delete->count(), 'No DELETE route for finance periods.');
    }

    public function test_model_blocks_deletion_defensively(): void
    {
        $period = $this->makePeriod(status: 'closed');

        $this->expectException(\RuntimeException::class);
        $period->delete();
    }

    public function test_audit_logs_are_written_for_create_close_reopen(): void
    {
        $user = $this->userWith(['finance_periods.create', 'finance_periods.close', 'finance_periods.reopen']);

        // Create
        $this->actingAs($user)->post('/finance/periods', [
            'name' => 'Audit Period',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
        ])->assertRedirect();
        $p = FinancePeriod::firstOrFail();

        // Close
        $this->actingAs($user)->post('/finance/periods/' . $p->id . '/close')->assertRedirect();

        // Reopen
        $this->actingAs($user)->post('/finance/periods/' . $p->id . '/reopen')->assertRedirect();

        $this->assertSame(1, AuditLog::where('action', 'finance_period_created')->where('record_id', $p->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'finance_period_closed')->where('record_id', $p->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'finance_period_reopened')->where('record_id', $p->id)->count());
    }

    /* ────────────────────── 2. Closed-period guard against posting services ────────────────────── */

    public function test_cashbox_adjustment_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);

        $service = app(\App\Services\CashboxService::class);

        $this->expectException(\RuntimeException::class);
        $service->createAdjustmentTransaction($cashbox, [
            'direction' => 'in',
            'amount' => 100,
            'notes' => 'attempted adjustment',
            'occurred_at' => '2026-05-15 10:00:00',
        ]);
    }

    public function test_cashbox_adjustment_outside_closed_period_still_works(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);

        $service = app(\App\Services\CashboxService::class);
        $tx = $service->createAdjustmentTransaction($cashbox, [
            'direction' => 'in',
            'amount' => 100,
            'notes' => 'outside closed range',
            'occurred_at' => '2026-06-15 10:00:00',
        ]);

        $this->assertSame(100.0, (float) $tx->amount);
    }

    /* ────────── Phase 5F.1 — CashboxesController surfaces guard as flash, not 500 ────────── */

    public function test_cashbox_adjustment_via_controller_redirects_back_with_error_when_period_closed(): void
    {
        $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);

        $user = $this->userWith(['cashbox_transactions.create']);

        $response = $this->actingAs($user)->post('/cashboxes/' . $cashbox->id . '/transactions', [
            'direction' => 'in',
            'amount' => 100,
            'notes' => 'attempted',
            'occurred_at' => '2026-05-15 10:00:00',
        ]);

        // Redirect-back-with-flash-error, NOT a 500.
        $response->assertStatus(302);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('closed', session('error'));

        // No cashbox transaction was created (other than the opening balance).
        $this->assertSame(
            1,
            \App\Models\CashboxTransaction::where('cashbox_id', $cashbox->id)->count(),
            'Only the opening_balance tx exists; the blocked adjustment did not land.'
        );
    }

    public function test_cashbox_adjustment_via_controller_succeeds_when_period_open(): void
    {
        $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);

        $user = $this->userWith(['cashbox_transactions.create']);

        $response = $this->actingAs($user)->post('/cashboxes/' . $cashbox->id . '/transactions', [
            'direction' => 'in',
            'amount' => 250,
            'notes' => 'outside the closed range',
            'occurred_at' => '2026-06-10 10:00:00',
        ]);

        $response->assertStatus(302);
        $response->assertSessionHas('success');

        $this->assertSame(
            2,
            \App\Models\CashboxTransaction::where('cashbox_id', $cashbox->id)->count(),
            'Opening balance + the new adjustment row.'
        );
    }

    public function test_cashbox_transfer_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $from = $this->makeCashbox(['name' => 'A', 'opening_balance' => 1000]);
        $to = $this->makeCashbox(['name' => 'B', 'opening_balance' => 0]);

        $service = app(\App\Services\CashboxTransferService::class);

        $this->expectException(\RuntimeException::class);
        $service->createTransfer([
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 100,
            'occurred_at' => '2026-05-10 09:00:00',
        ]);
    }

    public function test_collection_posting_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $order = $this->makeOrder();
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $method = $this->getMethod('cash');

        $collection = \App\Models\Collection::create([
            'order_id' => $order->id,
            'amount_due' => 100,
            'amount_collected' => 100,
            'collection_status' => 'Collected',
            'settlement_date' => '2026-05-10',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $service = app(\App\Services\CollectionCashboxService::class);

        $this->expectException(\RuntimeException::class);
        $service->postCollectionToCashbox($collection, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'occurred_at' => '2026-05-12 12:00:00',
        ]);
    }

    public function test_expense_posting_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $cat = ExpenseCategory::firstOrCreate(
            ['name' => 'Period Test Cat'],
            ['type' => 'Operating', 'status' => 'Active'],
        );

        $expense = \App\Models\Expense::create([
            'expense_category_id' => $cat->id,
            'title' => 'May rent',
            'amount' => 200,
            'currency_code' => 'EGP',
            'expense_date' => '2026-05-15',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $service = app(\App\Services\ExpenseCashboxService::class);

        $this->expectException(\RuntimeException::class);
        $service->postExpenseToCashbox($expense, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'occurred_at' => '2026-05-15 09:00:00',
        ]);
    }

    public function test_refund_payment_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $order = $this->makeOrder();
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $refund = Refund::create([
            'order_id' => $order->id,
            'amount' => 75,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        app(\App\Services\RefundService::class)->approve($refund);

        $this->expectException(\RuntimeException::class);
        app(\App\Services\RefundService::class)->pay($refund->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'occurred_at' => '2026-05-20 14:00:00',
        ]);
    }

    public function test_marketer_payout_inside_closed_period_is_blocked(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $marketer = $this->makeMarketer();
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $payout = MarketerPayout::create([
            'marketer_id' => $marketer->id,
            'amount' => 200,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        app(\App\Services\MarketerPayoutService::class)->approve($payout);

        $this->expectException(\RuntimeException::class);
        app(\App\Services\MarketerPayoutService::class)->pay($payout->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'occurred_at' => '2026-05-15 09:00:00',
        ]);
    }

    public function test_actions_outside_closed_period_still_work(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');
        $order = $this->makeOrder();

        // Refund dated outside the closed range — should succeed.
        $refund = Refund::create([
            'order_id' => $order->id,
            'amount' => 50,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        $svc = app(\App\Services\RefundService::class);
        $svc->approve($refund);
        $svc->pay($refund->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'occurred_at' => '2026-06-10 10:00:00',
        ]);

        $this->assertSame('paid', $refund->fresh()->status);
    }

    public function test_reports_remain_readable_when_period_is_closed(): void
    {
        $period = $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $user = $this->userWith(['finance_reports.view']);

        // All finance report endpoints stay open regardless of period status.
        foreach ([
            '/finance/reports',
            '/finance/reports/cashboxes',
            '/finance/reports/movements?from=2026-05-01&to=2026-05-31',
            '/finance/reports/cash-flow?from=2026-05-01&to=2026-05-31',
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }

    /* ────────────────────── 3. Direct service-level guard tests ────────────────────── */

    public function test_assert_date_is_open_returns_silently_when_no_closed_period_covers_it(): void
    {
        $svc = app(FinancePeriodService::class);
        $svc->assertDateIsOpen('2030-01-15'); // no period covers it
        $this->assertTrue(true, 'assertDateIsOpen is silent when no closed period applies.');
    }

    public function test_assert_date_is_open_throws_inside_closed_period(): void
    {
        $this->makePeriod(status: 'closed', start: '2026-05-01', end: '2026-05-31');
        $svc = app(FinancePeriodService::class);

        $this->expectException(\RuntimeException::class);
        $svc->assertDateIsOpen('2026-05-15');
    }

    public function test_assert_date_is_open_does_not_throw_inside_open_period(): void
    {
        $this->makePeriod(status: 'open', start: '2026-05-01', end: '2026-05-31');
        $svc = app(FinancePeriodService::class);

        $svc->assertDateIsOpen('2026-05-15');
        $this->assertTrue(true, 'Open periods do not block writes.');
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makePeriod(string $status = 'open', string $start = '2026-05-01', string $end = '2026-05-31'): FinancePeriod
    {
        static $counter = 0;
        $counter++;
        return FinancePeriod::create([
            'name' => "Test Period {$counter}",
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
            'closed_at' => $status === 'closed' ? now() : null,
            'closed_by' => $status === 'closed' ? $this->admin->id : null,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        $opening = (float) ($overrides['opening_balance'] ?? 0);

        // Cashbox::create() calls into CashboxService for the opening
        // balance tx with `now()`. To avoid the guard firing when no
        // period covers today, we insert the opening balance manually.
        $cashbox = Cashbox::create(array_merge([
            'name' => 'Period Cashbox ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => $opening,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));

        if ($opening != 0) {
            // Stamp opening balance occurred_at outside any closed range
            // used by our tests (2026-05) — use 2020 so guards stay silent.
            CashboxTransaction::create([
                'cashbox_id' => $cashbox->id,
                'direction' => $opening >= 0 ? 'in' : 'out',
                'amount' => $opening,
                'occurred_at' => '2020-01-01 00:00:00',
                'source_type' => 'opening_balance',
                'notes' => 'Test fixture opening balance.',
                'created_by' => $this->admin->id,
            ]);
        }

        return $cashbox;
    }

    private function getMethod(string $code): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'FP-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
            'total_amount' => 250,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeMarketer(): Marketer
    {
        $group = MarketerPriceGroup::create([
            'name' => 'Period Test Group ' . uniqid(),
            'code' => 'PGRP' . substr(uniqid(), -4),
            'status' => 'Active',
        ]);
        $role = Role::where('slug', 'marketer')->firstOrFail();
        $user = User::create([
            'name' => 'Period Test Marketer',
            'email' => 'mkt-period-' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
        return Marketer::create([
            'user_id' => $user->id,
            'code' => 'MKT-' . strtoupper(substr(uniqid(), -6)),
            'price_group_id' => $group->id,
            'status' => 'Active',
            'shipping_deducted' => true,
            'tax_deducted' => true,
            'commission_after_delivery_only' => true,
            'settlement_cycle' => 'Weekly',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Period Test Role ' . uniqid(),
            'slug' => 'period-test-' . uniqid(),
            'description' => 'Period test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Period Test User',
            'email' => 'period-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
