<?php

namespace Tests\Feature\Returns;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FinancePeriod;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use App\Services\RefundService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Returns Phase 5 — Refund & Finance integration boundary pinning.
 *
 * This suite is intentionally narrow: it pins the rules that must NEVER
 * regress at the Returns ↔ Refunds ↔ Cashboxes ↔ FinancePeriod seam.
 *
 * Why focus tests here instead of in ReturnRefundTest / RefundTest /
 * FinancePeriodTest:
 *   - Each of those suites pins ONE side of the wall (return-side
 *     creation, refund-side lifecycle, period-side guard). The cross-
 *     module assertions in this file are the *joins* between them.
 *   - A regression that subtly couples lifecycles (e.g. "closing a
 *     return now also writes a cashbox row") would slip past every
 *     existing single-axis test. The cross-axis pinning here catches it.
 *
 * Documented contract: docs/returns/RETURNS_REFUNDS_AND_FINANCE_BOUNDARIES.md
 *
 * Coverage map vs the Phase 5 prompt:
 *   #1 return-create → no refund        — test_creating_a_return_does_not_create_a_refund
 *   #2 return-create → no cashbox tx    — test_creating_a_return_does_not_create_a_cashbox_transaction
 *   #3 close return → no cashbox tx     — test_closing_a_return_does_not_create_a_cashbox_transaction
 *   #4 refund-request → no cashbox tx   — covered by ReturnRefundTest line 158 (NOT duplicated here)
 *   #5 pay from return → 1 OUT tx       — test_paying_refund_from_return_creates_exactly_one_cashbox_out_linked_to_refund_and_return
 *   #6 closed-period blocks pay         — covered by FinancePeriodTest line 372 (extended below at HTTP level)
 *   #7 failed pay preserves balance     — test_failed_refund_payment_in_closed_period_preserves_cashbox_balance
 *   #8 order-agent SoD                  — test_order_agent_can_create_return_but_cannot_pay_refund
 *   #9 amount > return remaining        — covered by ReturnRefundTest line 191 (NOT duplicated)
 *   #10 duplicate refunds cap           — covered by ReturnRefundTest line 172 + 191 (NOT duplicated)
 */
class ReturnFinanceBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ReturnReason $reason;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->reason = ReturnReason::firstOrFail();
        $this->customer = Customer::create([
            'name' => 'Boundary Test Customer',
            'primary_phone' => '01099994444',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Boundary Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Return creation never touches Finance ────────────────────── */

    public function test_creating_a_return_does_not_create_a_refund(): void
    {
        $this->actingAs($this->admin);
        $order = $this->makeOrder();

        $this->assertSame(0, Refund::count(), 'No refunds exist before the return is opened.');

        $return = app(ReturnService::class)->open([
            'order_id' => $order->id,
            'return_reason_id' => $this->reason->id,
            'refund_amount' => 150, // intent, not commitment
        ]);

        $this->assertSame('Pending', $return->return_status);
        $this->assertSame(0, Refund::count(),
            'Opening a return must NOT auto-create a refund row. The refund decision is a separate explicit user action.'
        );
        $this->assertSame(0, Refund::where('order_return_id', $return->id)->count());
    }

    public function test_creating_a_return_does_not_create_a_cashbox_transaction(): void
    {
        $this->actingAs($this->admin);
        $order = $this->makeOrder();

        $cashboxRowsBefore = CashboxTransaction::count();

        app(ReturnService::class)->open([
            'order_id' => $order->id,
            'return_reason_id' => $this->reason->id,
            'refund_amount' => 200,
        ]);

        $this->assertSame($cashboxRowsBefore, CashboxTransaction::count(),
            'Opening a return must NOT post to any cashbox. Cashboxes are append-only and only the Finance module posts to them.'
        );
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count());
    }

    /* ────────────────────── 2. Closing a return never touches Finance ────────────────────── */

    public function test_closing_a_return_does_not_create_a_cashbox_transaction(): void
    {
        $this->actingAs($this->admin);
        $return = $this->makeReturn(status: 'Restocked', refundAmount: 100);

        $cashboxRowsBefore = CashboxTransaction::count();
        $refundRowsBefore = Refund::count();

        app(ReturnService::class)->close($return, 'Closed for boundary test.');

        $this->assertSame('Closed', $return->fresh()->return_status);
        $this->assertSame($cashboxRowsBefore, CashboxTransaction::count(),
            'Closing a return must NOT post to any cashbox — closure is lifecycle finalisation, not money movement.'
        );
        $this->assertSame($refundRowsBefore, Refund::count(),
            'Closing a return must NOT auto-create or auto-pay a refund.'
        );
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count());
    }

    /* ────────────────────── 3. Paying a return-originated refund posts ONE cashbox OUT ────────────────────── */

    public function test_paying_refund_from_return_creates_exactly_one_cashbox_out_linked_to_refund_and_return(): void
    {
        // Setup: an Inspected return with refund_amount=100.
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        // Step 1: order-side action — request refund from the return.
        // This is the bridge endpoint; the only place that connects the
        // Returns module to the Finance module.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 100])
            ->assertRedirect();

        $refund = Refund::where('order_return_id', $return->id)->firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($return->id, $refund->order_return_id,
            'The refund must remain linked to the originating return.'
        );

        // Step 2: finance-side action — approve.
        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/approve')
            ->assertRedirect();
        $this->assertSame('approved', $refund->fresh()->status);

        // Step 3: finance-side action — pay.
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/pay', [
                'cashbox_id' => $cashbox->id,
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect();

        // Assertions: refund is paid, cashbox has ONE OUT row, the row
        // points back at this refund, the refund still points at the return.
        $this->assertSame('paid', $refund->fresh()->status);

        $txs = CashboxTransaction::where('source_type', 'refund')
            ->where('source_id', $refund->id)
            ->get();
        $this->assertCount(1, $txs,
            'Paying a return-originated refund must produce EXACTLY ONE cashbox_transactions row.'
        );

        $tx = $txs->first();
        $this->assertSame('out', $tx->direction);
        $this->assertSame('-100.00', (string) $tx->amount);
        $this->assertSame($cashbox->id, $tx->cashbox_id);
        $this->assertSame($method->id, $tx->payment_method_id);

        // The linkage chain return ←─ refund ─→ cashbox_transaction must stay intact.
        $this->assertSame($return->id, $refund->fresh()->order_return_id);

        // And paying the refund must NOT mutate the return row.
        $this->assertSame('Inspected', $return->fresh()->return_status,
            'Refund payment must NOT change the return lifecycle status.'
        );
        $this->assertSame('100.00', (string) $return->fresh()->refund_amount,
            'Refund payment must NOT mutate refund_amount on the return.'
        );
    }

    /* ────────────────────── 4. Closed-period guard protects the cashbox ────────────────────── */

    public function test_failed_refund_payment_in_closed_period_preserves_cashbox_balance(): void
    {
        // A closed period covering "now". The Finance period guard fires
        // off `occurred_at`, which defaults to now() when the controller
        // doesn't pass one.
        $today = now();
        $start = $today->copy()->startOfMonth()->toDateString();
        $end = $today->copy()->endOfMonth()->toDateString();
        $this->closePeriod($start, $end);

        // Setup: approved refund from a return.
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 200);
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 200])
            ->assertRedirect();
        $refund = Refund::where('order_return_id', $return->id)->firstOrFail();
        app(RefundService::class)->approve($refund);

        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');
        $balanceBefore = $cashbox->fresh()->balance();
        $this->assertSame(1000.0, $balanceBefore);

        // Attempt to pay — service throws RuntimeException, controller
        // catches it and redirects back with a flash error.
        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/pay', [
                'cashbox_id' => $cashbox->id,
                'payment_method_id' => $method->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        // Cashbox balance unchanged.
        $this->assertSame($balanceBefore, $cashbox->fresh()->balance(),
            'A blocked refund payment must leave the cashbox balance untouched.'
        );

        // No cashbox_transactions row written.
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count(),
            'A blocked refund payment must NOT write a cashbox_transactions row.'
        );

        // Refund status unchanged — still `approved`, still ready to pay
        // once the period is re-opened.
        $this->assertSame('approved', $refund->fresh()->status,
            'A blocked pay attempt must NOT advance the refund status.'
        );
    }

    /* ────────────────────── 5. Separation of duties ────────────────────── */

    public function test_order_agent_can_create_return_but_cannot_pay_refund(): void
    {
        $orderAgent = $this->makeUserWithRoleSlug('order-agent');
        $order = $this->makeOrder();

        // (1) Order Agent can create a return via the direct POST endpoint
        // (the role carries `returns.create`).
        $this->actingAs($orderAgent)
            ->post('/returns', [
                'order_id' => $order->id,
                'return_reason_id' => $this->reason->id,
                'product_condition' => 'Good',
                'refund_amount' => 100,
            ])
            ->assertRedirect();

        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame($orderAgent->id, $return->created_by);

        // Bring the return to a refund-eligible state for the request step
        // below. Inspection itself requires `returns.inspect`, which the
        // order-agent does NOT have — so we move it via the service as the
        // admin, simulating "warehouse agent inspected; order agent now
        // raises a refund". This keeps the test focused on the SoD wall
        // we're pinning, not on inspection permissions.
        $return->forceFill(['return_status' => 'Inspected'])->save();

        // (2) Order Agent can request a refund (the role carries
        // `refunds.create`).
        $this->actingAs($orderAgent)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 100])
            ->assertRedirect();

        $refund = Refund::where('order_return_id', $return->id)->firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($orderAgent->id, $refund->requested_by);

        // (3) Approval is NOT order-agent's job — Admin handles it so we
        // can move to the wall we actually care about.
        app(RefundService::class)->approve($refund, $this->admin);

        // (4) The wall: Order Agent must NOT be able to pay the refund.
        // `refunds.pay` is reserved for the accountant role.
        $cashbox = $this->makeCashbox(['opening_balance' => 1000]);
        $method = $this->getMethod('cash');

        $this->actingAs($orderAgent)
            ->post('/refunds/' . $refund->id . '/pay', [
                'cashbox_id' => $cashbox->id,
                'payment_method_id' => $method->id,
            ])
            ->assertForbidden();

        // Money did NOT move. Refund is still approved, cashbox balance
        // is still 1000.
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertSame(1000.0, $cashbox->fresh()->balance());
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count(),
            'A 403 on /refunds/{id}/pay must NOT have left a partial cashbox row.'
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'RFB-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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

    private function makeReturn(string $status, float $refundAmount): OrderReturn
    {
        $order = $this->makeOrder();
        return OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => $status,
            'product_condition' => 'Good',
            'refund_amount' => $refundAmount,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    /**
     * Mirrors RefundTest::makeCashbox — writes an opening-balance row so
     * the cashbox has a sensible starting balance.
     */
    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;
        $opening = (float) ($overrides['opening_balance'] ?? 0);

        $cashbox = Cashbox::create(array_merge([
            'name' => 'Boundary Cashbox ' . $counter,
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

    /**
     * Build a User attached to one of the seeded system roles. This is
     * how we test the real production role surface (rather than a
     * synthesised permission set).
     */
    private function makeUserWithRoleSlug(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::create([
            'name' => 'Role-' . $slug . '-' . uniqid(),
            'email' => $slug . '+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    private function closePeriod(string $start, string $end): FinancePeriod
    {
        static $counter = 0;
        $counter++;
        return FinancePeriod::create([
            'name' => 'Boundary Closed Period ' . $counter,
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);
    }
}
