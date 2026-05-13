<?php

namespace Tests\Feature\Finance;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\CashboxTransfer;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\Expense;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Finance Phase 5E — Finance Reports feature coverage.
 *
 * All endpoints are read-only. These tests pin:
 *   - permission gating
 *   - aggregate correctness (sums match the ledger)
 *   - filter behavior (date / cashbox / source / status)
 *   - inclusion of every modern source type (collection, expense,
 *     refund, marketer_payout, transfer, adjustment, opening_balance)
 *   - read-only contract: no rows are inserted/updated/deleted by
 *     calling a report endpoint.
 */
class FinanceReportsTest extends TestCase
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
            'name' => 'Reports Customer',
            'primary_phone' => '01088887777',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Reports Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Permission gating ────────────────────── */

    public function test_user_with_finance_reports_view_can_access_index(): void
    {
        $user = $this->userWith(['finance_reports.view']);

        $this->actingAs($user)->get('/finance/reports')->assertOk()
            ->assertInertia(fn ($page) => $page->component('FinanceReports/Index'));
    }

    public function test_user_without_finance_reports_view_cannot_access_index(): void
    {
        $user = $this->userWith(['cashboxes.view']);

        $this->actingAs($user)->get('/finance/reports')->assertForbidden();
    }

    public function test_user_without_permission_cannot_access_any_finance_report(): void
    {
        $user = $this->userWith(['cashboxes.view']);

        foreach ([
            '/finance/reports',
            '/finance/reports/cashboxes',
            '/finance/reports/movements',
            '/finance/reports/collections',
            '/finance/reports/expenses',
            '/finance/reports/refunds',
            '/finance/reports/marketer-payouts',
            '/finance/reports/transfers',
            '/finance/reports/cash-flow',
        ] as $url) {
            $this->actingAs($user)->get($url)->assertForbidden();
        }
    }

    /* ────────────────────── 2. Cashbox summary sums match the ledger ────────────────────── */

    public function test_cashbox_summary_totals_match_cashbox_transactions(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 500]);
        // Add a 200 IN + 50 OUT.
        $this->insertTx($cashbox, +200, 'collection');
        $this->insertTx($cashbox, -50, 'expense');

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)->get('/finance/reports/cashboxes?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Cashboxes')
            ->where('totals.total_balance', fn ($v) => (float) $v === 650.0) // 500 + 200 − 50
            ->where('totals.inflow', fn ($v) => (float) $v === 700.0) // 500 (opening_balance) + 200 (collection)
            ->where('totals.outflow', fn ($v) => (float) $v === -50.0)
        );
    }

    /* ────────────────────── 3. Movements report — source type inclusion ────────────────────── */

    public function test_movement_report_includes_all_modern_source_types(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $this->insertTx($cashbox, +100, CashboxTransaction::SOURCE_COLLECTION);
        $this->insertTx($cashbox, -25, CashboxTransaction::SOURCE_EXPENSE);
        $this->insertTx($cashbox, -50, CashboxTransaction::SOURCE_REFUND);
        $this->insertTx($cashbox, -75, CashboxTransaction::SOURCE_MARKETER_PAYOUT);
        $this->insertTx($cashbox, +200, CashboxTransaction::SOURCE_TRANSFER);
        $this->insertTx($cashbox, -10, CashboxTransaction::SOURCE_ADJUSTMENT);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/movements?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Movements')
            ->has('source_types', 7) // PHASE_5D whitelist
            ->where('source_types.0', 'opening_balance')
            ->where('source_types.6', 'marketer_payout')
            ->where('totals.count', 7) // 6 + the opening_balance row
        );
    }

    public function test_movement_report_date_filter_excludes_old_rows(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        // Insert one tx in 2020 and one today.
        $old = $this->insertTx($cashbox, +100, 'collection', '2020-01-15 12:00:00');
        $new = $this->insertTx($cashbox, +200, 'collection', now()->toDateTimeString());

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/movements?from=2024-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            // Only the recent tx is within range.
            ->where('totals.count', 1)
            ->where('totals.inflow', fn ($v) => (float) $v === 200.0)
        );
    }

    public function test_movement_report_cashbox_filter(): void
    {
        $a = $this->makeCashbox(['name' => 'Cashbox A', 'opening_balance' => 0]);
        $b = $this->makeCashbox(['name' => 'Cashbox B', 'opening_balance' => 0]);
        $this->insertTx($a, +100, 'collection');
        $this->insertTx($b, +500, 'collection');

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/movements?from=2000-01-01&to=2099-12-31&cashbox_id=' . $a->id);

        $response->assertInertia(fn ($page) => $page
            ->where('totals.inflow', fn ($v) => (float) $v === 100.0)
            ->where('totals.count', 1)
        );
    }

    public function test_movement_report_source_type_filter(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $this->insertTx($cashbox, +100, CashboxTransaction::SOURCE_COLLECTION);
        $this->insertTx($cashbox, -50, CashboxTransaction::SOURCE_EXPENSE);
        $this->insertTx($cashbox, -75, CashboxTransaction::SOURCE_MARKETER_PAYOUT);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/movements?from=2000-01-01&to=2099-12-31&source_type=marketer_payout');

        $response->assertInertia(fn ($page) => $page
            ->where('totals.count', 1)
            ->where('totals.outflow', fn ($v) => (float) $v === -75.0)
        );
    }

    /* ────────────────────── 4. Cash flow grouping ────────────────────── */

    public function test_cash_flow_groups_by_source_type(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $this->insertTx($cashbox, +300, CashboxTransaction::SOURCE_COLLECTION);
        $this->insertTx($cashbox, -100, CashboxTransaction::SOURCE_EXPENSE);
        $this->insertTx($cashbox, -50, CashboxTransaction::SOURCE_REFUND);
        $this->insertTx($cashbox, -25, CashboxTransaction::SOURCE_MARKETER_PAYOUT);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/cash-flow?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/CashFlow')
            // Net excluding transfers: +300 − 100 − 50 − 25 = +125
            ->where('totals.net_excluding_transfers', fn ($v) => (float) $v === 125.0)
            ->where('totals.inflow_excluding_transfers', fn ($v) => (float) $v === 300.0)
            ->where('totals.outflow_excluding_transfers', fn ($v) => (float) $v === -175.0)
        );
    }

    public function test_cash_flow_excludes_transfers_from_net(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $this->insertTx($cashbox, +100, CashboxTransaction::SOURCE_COLLECTION);
        $this->insertTx($cashbox, +1000, CashboxTransaction::SOURCE_TRANSFER);
        $this->insertTx($cashbox, -500, CashboxTransaction::SOURCE_TRANSFER);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/cash-flow?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->where('totals.net_excluding_transfers', fn ($v) => (float) $v === 100.0)
            ->where('totals.net_including_transfers', fn ($v) => (float) $v === 600.0)
        );
    }

    /* ────────────────────── 5. Collections / Expenses / Refunds / Payouts / Transfers ────────────────────── */

    public function test_collections_report_includes_posted_collections(): void
    {
        $order = $this->makeOrder();
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $method = $this->getMethod('cash');

        $tx = $this->insertTx($cashbox, +250, 'collection');
        $col = Collection::create([
            'order_id' => $order->id,
            'amount_due' => 250,
            'amount_collected' => 250,
            'collection_status' => 'Collected',
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
            'cashbox_transaction_id' => $tx->id,
            'cashbox_posted_at' => now(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/collections?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Collections')
            ->has('rows.data', 1)
            ->where('totals.posted_amount', fn ($v) => (float) $v === 250.0)
        );
    }

    public function test_expenses_report_includes_posted_expenses(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $tx = $this->insertTx($cashbox, -300, 'expense');

        $category = \App\Models\ExpenseCategory::firstOrCreate(
            ['name' => 'Test Category'],
            ['type' => 'Operating', 'status' => 'Active'],
        );

        Expense::create([
            'expense_category_id' => $category->id,
            'title' => 'Office rent',
            'amount' => 300,
            'currency_code' => 'EGP',
            'expense_date' => now()->toDateString(),
            'cashbox_id' => $cashbox->id,
            'cashbox_transaction_id' => $tx->id,
            'cashbox_posted_at' => now(),
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/expenses?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Expenses')
            ->has('rows.data', 1)
            ->where('totals.posted_amount', fn ($v) => (float) $v === 300.0)
        );
    }

    public function test_refunds_report_includes_paid_refunds(): void
    {
        $order = $this->makeOrder();
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $refund = Refund::create([
            'order_id' => $order->id,
            'amount' => 150,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        app(\App\Services\RefundService::class)->approve($refund);
        app(\App\Services\RefundService::class)->pay($refund->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/refunds?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Refunds')
            ->where('totals.paid_amount', fn ($v) => (float) $v === 150.0)
        );
    }

    public function test_marketer_payouts_report_includes_paid_payouts(): void
    {
        $marketer = $this->makeMarketer();
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $payout = MarketerPayout::create([
            'marketer_id' => $marketer->id,
            'amount' => 400,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        $service = app(\App\Services\MarketerPayoutService::class);
        $service->approve($payout);
        $service->pay($payout->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/marketer-payouts?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/MarketerPayouts')
            ->where('totals.paid_amount', fn ($v) => (float) $v === 400.0)
        );
    }

    public function test_transfers_report_includes_cashbox_transfers(): void
    {
        $from = $this->makeCashbox(['name' => 'Source', 'opening_balance' => 1000]);
        $to = $this->makeCashbox(['name' => 'Destination', 'opening_balance' => 0]);

        CashboxTransfer::create([
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 350,
            'occurred_at' => now(),
            'reason' => 'test',
            'created_by' => $this->admin->id,
        ]);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports/transfers?from=2000-01-01&to=2099-12-31');

        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Transfers')
            ->where('totals.count', 1)
            ->where('totals.total_amount', fn ($v) => (float) $v === 350.0)
        );
    }

    /* ────────────────────── 6. Read-only contract ────────────────────── */

    public function test_finance_reports_do_not_mutate_data(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);
        $this->insertTx($cashbox, +250, 'collection');

        $txCountBefore = CashboxTransaction::count();
        $cashboxCountBefore = Cashbox::count();
        $balanceBefore = $cashbox->balance();

        $user = $this->userWith(['finance_reports.view']);
        foreach ([
            '/finance/reports',
            '/finance/reports/cashboxes',
            '/finance/reports/movements',
            '/finance/reports/collections',
            '/finance/reports/expenses',
            '/finance/reports/refunds',
            '/finance/reports/marketer-payouts',
            '/finance/reports/transfers',
            '/finance/reports/cash-flow',
        ] as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->assertSame($txCountBefore, CashboxTransaction::count(), 'No rows inserted by GET.');
        $this->assertSame($cashboxCountBefore, Cashbox::count(), 'No cashbox modifications.');
        $this->assertSame($balanceBefore, $cashbox->fresh()->balance(), 'Balance unchanged.');
    }

    /* ────────────────────── 7. Overview totals ────────────────────── */

    public function test_overview_totals_match_ledger(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 0]);
        $this->insertTx($cashbox, +500, CashboxTransaction::SOURCE_COLLECTION);
        $this->insertTx($cashbox, -200, CashboxTransaction::SOURCE_EXPENSE);
        $this->insertTx($cashbox, -100, CashboxTransaction::SOURCE_REFUND);
        $this->insertTx($cashbox, -50, CashboxTransaction::SOURCE_MARKETER_PAYOUT);

        $user = $this->userWith(['finance_reports.view']);
        $response = $this->actingAs($user)
            ->get('/finance/reports?from=2000-01-01&to=2099-12-31');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('FinanceReports/Index')
            ->where('collections_posted', fn ($v) => (float) $v === 500.0)
            ->where('expenses_posted', fn ($v) => (float) $v === 200.0)
            ->where('refunds_paid', fn ($v) => (float) $v === 100.0)
            ->where('marketer_payouts_paid', fn ($v) => (float) $v === 50.0)
            ->where('inflow', fn ($v) => (float) $v === 500.0)
            ->where('outflow', fn ($v) => (float) $v === -350.0)
            ->where('net', fn ($v) => (float) $v === 150.0)
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        $opening = (float) ($overrides['opening_balance'] ?? 0);

        $cashbox = Cashbox::create(array_merge([
            'name' => 'Reports Cashbox ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => $opening,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));

        if ($opening != 0) {
            CashboxTransaction::create([
                'cashbox_id' => $cashbox->id,
                'direction' => $opening >= 0 ? 'in' : 'out',
                'amount' => $opening,
                'occurred_at' => now(),
                'source_type' => 'opening_balance',
                'notes' => 'Test fixture opening balance.',
                'created_by' => $this->admin->id,
            ]);
        }

        return $cashbox;
    }

    /**
     * Insert a raw cashbox transaction with the given signed amount and source_type.
     */
    private function insertTx(Cashbox $cashbox, float $amount, string $sourceType, ?string $occurredAt = null): CashboxTransaction
    {
        return CashboxTransaction::create([
            'cashbox_id' => $cashbox->id,
            'direction' => $amount >= 0 ? 'in' : 'out',
            'amount' => $amount,
            'occurred_at' => $occurredAt ?? now()->toDateTimeString(),
            'source_type' => $sourceType,
            'notes' => "Test {$sourceType}",
            'created_by' => $this->admin->id,
        ]);
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
            'order_number' => 'FR-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
            'name' => 'Reports Test Group ' . uniqid(),
            'code' => 'RGRP' . substr(uniqid(), -4),
            'status' => 'Active',
        ]);
        $role = Role::where('slug', 'marketer')->firstOrFail();
        $user = User::create([
            'name' => 'Test Marketer',
            'email' => 'mkt-rep-' . uniqid() . '@hbs.local',
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
            'name' => 'Finance Reports Test ' . uniqid(),
            'slug' => 'fin-rep-test-' . uniqid(),
            'description' => 'Finance reports test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Finance Reports Test User',
            'email' => 'fr-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
