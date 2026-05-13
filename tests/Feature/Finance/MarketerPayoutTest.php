<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Marketer;
use App\Models\MarketerPayout;
use App\Models\MarketerPriceGroup;
use App\Models\MarketerTransaction;
use App\Models\MarketerWallet;
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
 * Finance Phase 5D — Marketer Payouts + Refund-driven profit reversal.
 *
 * Layer A: workflow (requested → approved → paid) + cashbox OUT.
 * Layer B: best-effort proportional profit reversal when a refund is paid.
 *
 * The existing `MarketerWalletService::payout()` legacy quick-payout
 * path (no cashbox, instant Paid) stays untouched and is not exercised
 * here.
 */
class MarketerPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private Marketer $marketer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->customer = Customer::create([
            'name' => 'Payout Test Customer',
            'primary_phone' => '01099991111',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Payout Street',
            'created_by' => $this->admin->id,
        ]);
        $this->marketer = $this->makeMarketer();
    }

    /* ────────────────────── 1. Create / permission gating ────────────────────── */

    public function test_user_with_create_permission_can_request_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.create']);

        $this->actingAs($user)->post('/marketer-payouts', [
            'marketer_id' => $this->marketer->id,
            'amount' => 250,
            'notes' => 'weekly settlement',
        ])->assertRedirect('/marketer-payouts');

        $payout = MarketerPayout::firstOrFail();
        $this->assertSame('requested', $payout->status);
        $this->assertSame('250.00', (string) $payout->amount);
        $this->assertSame($this->marketer->id, $payout->marketer_id);
        $this->assertSame($user->id, $payout->requested_by);
    }

    public function test_user_without_create_permission_cannot_request_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.view']);

        $this->actingAs($user)->post('/marketer-payouts', [
            'marketer_id' => $this->marketer->id,
            'amount' => 100,
        ])->assertForbidden();

        $this->assertSame(0, MarketerPayout::count());
    }

    /* ────────────────────── 2. Approve / reject permission gating ────────────────────── */

    public function test_user_with_approve_permission_can_approve_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.approve']);
        $payout = $this->makeRequestedPayout();

        $this->actingAs($user)->post('/marketer-payouts/' . $payout->id . '/approve')
            ->assertRedirect();

        $this->assertSame('approved', $payout->fresh()->status);
        $this->assertSame($user->id, $payout->fresh()->approved_by);
        $this->assertNotNull($payout->fresh()->approved_at);
    }

    public function test_user_without_approve_permission_cannot_approve_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.view']);
        $payout = $this->makeRequestedPayout();

        $this->actingAs($user)->post('/marketer-payouts/' . $payout->id . '/approve')
            ->assertForbidden();

        $this->assertSame('requested', $payout->fresh()->status);
    }

    public function test_user_with_reject_permission_can_reject_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.reject']);
        $payout = $this->makeRequestedPayout();

        $this->actingAs($user)->post('/marketer-payouts/' . $payout->id . '/reject')
            ->assertRedirect();

        $this->assertSame('rejected', $payout->fresh()->status);
    }

    /* ────────────────────── 3. Pay permission + lifecycle ────────────────────── */

    public function test_user_with_pay_permission_can_pay_approved_payout(): void
    {
        $user = $this->userWith(['marketer_payouts.pay']);
        $payout = $this->makeApprovedPayout(['amount' => 300]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($user)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame($user->id, $payout->fresh()->paid_by);
        $this->assertNotNull($payout->fresh()->paid_at);
    }

    public function test_requested_payout_cannot_be_paid_directly(): void
    {
        $payout = $this->makeRequestedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame('requested', $payout->fresh()->status, 'requested payouts must not skip approval.');
        $this->assertSame(0, CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->count());
    }

    public function test_rejected_payout_cannot_be_paid(): void
    {
        $payout = $this->makeRequestedPayout(['amount' => 100]);
        app(\App\Services\MarketerPayoutService::class)->reject($payout);
        $this->assertSame('rejected', $payout->fresh()->status);

        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame('rejected', $payout->fresh()->status);
        $this->assertSame(0, CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->count());
    }

    public function test_paid_payout_cannot_be_paid_twice(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();
        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(1, CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->count());

        // Second attempt — controller surfaces error, no new tx written.
        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(1, CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->count(), 'No second cashbox tx written.');
    }

    /* ────────────────────── 4. Cashbox effect on pay ────────────────────── */

    public function test_paying_creates_cashbox_out_transaction(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 420]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $tx = CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->firstOrFail();

        $this->assertSame('out', $tx->direction);
        $this->assertSame(-420.0, (float) $tx->amount, 'Cashbox amount must be signed negative.');
        $this->assertSame($cashbox->id, $tx->cashbox_id);
        $this->assertSame($method->id, $tx->payment_method_id);
    }

    public function test_cashbox_transaction_links_back_to_payout(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $tx = CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->firstOrFail();
        $this->assertSame(CashboxTransaction::SOURCE_MARKETER_PAYOUT, $tx->source_type);
        $this->assertSame($payout->id, (int) $tx->source_id);
        $this->assertSame($tx->id, (int) $payout->fresh()->cashbox_transaction_id);
    }

    public function test_paying_stamps_paid_metadata_on_payout(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $fresh = $payout->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame($this->admin->id, (int) $fresh->paid_by);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame($cashbox->id, (int) $fresh->cashbox_id);
        $this->assertSame($method->id, (int) $fresh->payment_method_id);
        $this->assertNotNull($fresh->cashbox_transaction_id);
        $this->assertNotNull($fresh->marketer_transaction_id);
    }

    public function test_paying_decreases_cashbox_balance(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 250]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(750.0, $cashbox->fresh()->balance(), 'Balance must drop by exactly the payout amount.');
    }

    public function test_paying_mirrors_to_marketer_transactions(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 200]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $mirror = MarketerTransaction::where('transaction_type', MarketerTransaction::TYPE_PAYOUT)
            ->where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)
            ->where('source_id', $payout->id)
            ->firstOrFail();

        $this->assertSame('Paid', $mirror->status);
        $this->assertSame(200.0, (float) $mirror->net_profit);
        $this->assertSame($this->marketer->id, (int) $mirror->marketer_id);
    }

    /* ────────────────────── 5. Negative-balance rules ────────────────────── */

    public function test_pay_blocked_when_cashbox_disallows_negative_balance(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 500]);
        $cashbox = $this->makeCashbox(['opening_balance' => 100, 'allow_negative_balance' => false]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame('approved', $payout->fresh()->status);
        $this->assertSame(0, CashboxTransaction::where('source_type', CashboxTransaction::SOURCE_MARKETER_PAYOUT)->count());
        $this->assertSame(100.0, $cashbox->fresh()->balance(), 'Balance must not change after a blocked pay.');
    }

    public function test_pay_allowed_when_cashbox_allows_negative_balance(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 500]);
        $cashbox = $this->makeCashbox(['opening_balance' => 100, 'allow_negative_balance' => true]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame('paid', $payout->fresh()->status);
        $this->assertSame(-400.0, $cashbox->fresh()->balance(), '100 − 500 = -400 (permitted).');
    }

    /* ────────────────────── 6. Immutability ────────────────────── */

    public function test_paid_payout_cannot_be_edited(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');
        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();
        $this->assertSame('paid', $payout->fresh()->status);

        $this->actingAs($this->admin)->put('/marketer-payouts/' . $payout->id, [
            'marketer_id' => $this->marketer->id,
            'amount' => 9999,
        ])->assertRedirect();

        $this->assertSame('100.00', (string) $payout->fresh()->amount, 'Paid payout must be immutable.');
    }

    public function test_paid_payout_cannot_be_deleted_via_model(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');
        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->expectException(\RuntimeException::class);
        $payout->fresh()->delete();
    }

    /* ────────────────────── 7. Audit log ────────────────────── */

    public function test_payout_paid_audit_log_is_written(): void
    {
        $payout = $this->makeApprovedPayout(['amount' => 100]);
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)->post('/marketer-payouts/' . $payout->id . '/pay', [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ])->assertRedirect();

        $this->assertSame(1, AuditLog::where('module', 'finance.marketer_payout')
            ->where('action', 'marketer_payout_paid')
            ->where('record_type', MarketerPayout::class)
            ->where('record_id', $payout->id)->count());

        $this->assertSame(1, AuditLog::where('module', 'finance.marketer_payout')
            ->where('action', 'cashbox_transaction.created')
            ->where('record_type', CashboxTransaction::class)->count());
    }

    public function test_payout_request_writes_audit_log(): void
    {
        $this->actingAs($this->admin)->post('/marketer-payouts', [
            'marketer_id' => $this->marketer->id,
            'amount' => 100,
        ])->assertRedirect();

        $payout = MarketerPayout::firstOrFail();
        $this->assertSame(1, AuditLog::where('module', 'finance.marketer_payout')
            ->where('action', 'marketer_payout_created')
            ->where('record_id', $payout->id)->count());
    }

    /* ────────────────────── 8. Layer B — Profit reversal ────────────────────── */

    public function test_paid_refund_creates_reversal_when_order_has_marketer_profit(): void
    {
        // Order subtotal 1000, marketer_profit 200; refund 100 = 10% of total.
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 200);
        $refund = $this->makePaidRefund($order, 100);

        $reversal = MarketerTransaction::where('source_type', 'refund')
            ->where('source_id', $refund->id)
            ->first();

        $this->assertNotNull($reversal, 'Reversal Adjustment row must be created.');
        $this->assertSame('Adjustment', $reversal->transaction_type);
        $this->assertSame('Approved', $reversal->status);
        $this->assertSame(-20.0, (float) $reversal->net_profit, '200 × (100 / 1000) = 20 → -20 reversal.');
        $this->assertSame($this->marketer->id, (int) $reversal->marketer_id);
        $this->assertSame($order->id, (int) $reversal->order_id);
    }

    public function test_paid_refund_reversal_is_idempotent(): void
    {
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 200);
        $refund = $this->makePaidRefund($order, 100);

        // Call the service a second time directly — should be a no-op.
        $service = app(\App\Services\MarketerProfitReversalService::class);
        $second = $service->reverseFromPaidRefund($refund->fresh());

        // Same row returned, no new row inserted.
        $this->assertSame(
            1,
            MarketerTransaction::where('source_type', 'refund')->where('source_id', $refund->id)->count(),
            'Idempotent — only one reversal row.'
        );
    }

    public function test_partial_refund_creates_proportional_reversal(): void
    {
        // Order subtotal 1000, marketer_profit 300; refund 250 = 25%.
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 300);
        $refund = $this->makePaidRefund($order, 250);

        $reversal = MarketerTransaction::where('source_type', 'refund')
            ->where('source_id', $refund->id)->firstOrFail();

        $this->assertSame(-75.0, (float) $reversal->net_profit, '300 × (250 / 1000) = 75 → -75 reversal.');
    }

    public function test_no_reversal_when_order_has_no_marketer(): void
    {
        // Order with no marketer (e.g. organic / direct).
        $order = $this->makeOrderWithoutMarketer();
        $refund = $this->makePaidRefund($order, 100);

        $this->assertSame(0, MarketerTransaction::where('source_type', 'refund')->count());
    }

    public function test_no_reversal_when_order_has_no_marketer_profit_snapshot(): void
    {
        // Order with marketer but no marketer_profit set (e.g. older order
        // pre-snapshot or zero-profit case).
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 0);
        $refund = $this->makePaidRefund($order, 100);

        $this->assertSame(0, MarketerTransaction::where('source_type', 'refund')->count());
    }

    /* ──────── 9. Layer B — Double-reversal protection (pre-commit review fix) ──────── */

    public function test_no_reversal_when_order_status_is_returned(): void
    {
        // Order already moved to Returned (syncFromOrder has zeroed
        // the per-order Earned row). A paid refund here MUST NOT add a
        // second reversal — otherwise the wallet drops by 2× the right
        // amount.
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 200);
        $order->forceFill(['status' => 'Returned'])->save();

        $refund = $this->makePaidRefund($order, 100);

        $this->assertSame(
            0,
            MarketerTransaction::where('source_type', 'refund')->count(),
            'Reversal must skip when the order is already in a terminal status (Returned/Cancelled).'
        );
    }

    public function test_no_reversal_when_order_status_is_cancelled(): void
    {
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 200);
        $order->forceFill(['status' => 'Cancelled'])->save();

        $refund = $this->makePaidRefund($order, 100);

        $this->assertSame(0, MarketerTransaction::where('source_type', 'refund')->count());
    }

    public function test_no_reversal_when_refund_is_linked_to_a_return(): void
    {
        // Return-linked refunds (Phase 5C) defer to the return path:
        // when the return closes / the order flips to Returned,
        // syncFromOrder zeros the per-order Earned row. Writing an
        // adjustment here would be double-counted.
        $order = $this->makeOrderWithMarketer(totalAmount: 1000, marketerProfit: 200);

        // Real OrderReturn row so the FK on refunds.order_return_id holds.
        $reason = \App\Models\ReturnReason::create([
            'name' => 'Pre-Commit Test Reason',
            'status' => 'Active',
        ]);
        $orderReturn = \App\Models\OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $reason->id,
            'return_status' => 'Inspected',
            'product_condition' => 'Good',
            'refund_amount' => 100,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $refund = Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $orderReturn->id,
            'customer_id' => $order->customer_id,
            'amount' => 100,
            'reason' => 'return-linked',
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $service = app(\App\Services\RefundService::class);
        $service->approve($refund);

        $cashbox = $this->makeCashbox(['opening_balance' => 10000]);
        $method = $this->getMethod('cash');
        $service->pay($refund->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        $this->assertSame('paid', $refund->fresh()->status, 'Refund itself must still pay successfully.');
        $this->assertSame(
            0,
            MarketerTransaction::where('source_type', 'refund')->count(),
            'No reversal must be written for return-linked refunds.'
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeRequestedPayout(array $overrides = []): MarketerPayout
    {
        return MarketerPayout::create(array_merge([
            'marketer_id' => $this->marketer->id,
            'amount' => 100,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeApprovedPayout(array $overrides = []): MarketerPayout
    {
        $payout = $this->makeRequestedPayout($overrides);
        app(\App\Services\MarketerPayoutService::class)->approve($payout);
        return $payout->fresh();
    }

    private function makeMarketer(): Marketer
    {
        $group = MarketerPriceGroup::create([
            'name' => 'Test Group ' . uniqid(),
            'code' => 'TGRP' . substr(uniqid(), -4),
            'status' => 'Active',
        ]);

        $role = Role::where('slug', 'marketer')->firstOrFail();
        $user = User::create([
            'name' => 'Test Marketer',
            'email' => 'mkt-' . uniqid() . '@hbs.local',
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

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        $opening = (float) ($overrides['opening_balance'] ?? 0);

        $cashbox = Cashbox::create(array_merge([
            'name' => 'Payout Cashbox ' . $counter,
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

    private function getMethod(string $code): PaymentMethod
    {
        return PaymentMethod::where('code', $code)->firstOrFail();
    }

    private function makeOrderWithMarketer(float $totalAmount, float $marketerProfit): Order
    {
        static $counter = 0;
        $counter++;
        $order = Order::create([
            'order_number' => 'MKT-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'marketer_id' => $this->marketer->id,
            'status' => 'Delivered',
            'collection_status' => 'Collected',
            'shipping_status' => 'Delivered',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => $totalAmount,
            'created_by' => $this->admin->id,
        ]);
        // marketer_profit is a snapshot column (Phase 5.9). The OrderService
        // sets it at order creation; tests set it directly.
        $order->forceFill(['marketer_profit' => $marketerProfit])->save();
        return $order;
    }

    private function makeOrderWithoutMarketer(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'ORG-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
            'total_amount' => 1000,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Create + approve + pay a refund linked to the order. Returns the
     * fresh (paid) refund. The reversal hook fires from RefundService::pay().
     */
    private function makePaidRefund(Order $order, float $amount): Refund
    {
        $refund = Refund::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'amount' => $amount,
            'reason' => 'test refund',
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $service = app(\App\Services\RefundService::class);
        $service->approve($refund);

        $cashbox = $this->makeCashbox(['opening_balance' => 10000]);
        $method = $this->getMethod('cash');
        $service->pay($refund->fresh(), $this->admin, [
            'cashbox_id' => $cashbox->id,
            'payment_method_id' => $method->id,
        ]);

        return $refund->fresh();
    }

    /**
     * Create a User with a fresh Role granting exactly the listed
     * permission slugs.
     */
    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Payout Test ' . uniqid(),
            'slug' => 'payout-test-' . uniqid(),
            'description' => 'Payout test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Payout Test User',
            'email' => 'payout-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
