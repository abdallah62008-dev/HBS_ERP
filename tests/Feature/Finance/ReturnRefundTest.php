<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Finance Phase 5C — Returns × Refunds linkage feature coverage.
 *
 * Phase 5C is purely a *creation path*: an eligible return can spawn a
 * `requested` refund row that's already linked via `order_return_id`.
 * Approval and payment continue to flow through the Phase 5A and 5B
 * paths — Phase 5C does NOT auto-approve, auto-pay, or write any
 * cashbox transaction.
 */
class ReturnRefundTest extends TestCase
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
            'name' => 'Return×Refund Customer',
            'primary_phone' => '01099993333',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Return Refund Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Eligibility ────────────────────── */

    public function test_eligible_return_can_create_requested_refund(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 200);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $refund = Refund::firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($return->id, $refund->order_return_id);
        $this->assertSame('200.00', (string) $refund->amount);
        $this->assertSame($this->admin->id, $refund->requested_by);
    }

    /** @dataProvider eligibleStatuses */
    public function test_all_post_inspection_statuses_are_eligible(string $status): void
    {
        $return = $this->makeReturn(status: $status, refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $this->assertSame(1, Refund::where('order_return_id', $return->id)->count());
    }

    public static function eligibleStatuses(): array
    {
        return [
            ['Inspected'],
            ['Restocked'],
            ['Damaged'],
            ['Closed'],
        ];
    }

    /** @dataProvider ineligibleStatuses */
    public function test_pre_inspection_statuses_cannot_create_refund(string $status): void
    {
        $return = $this->makeReturn(status: $status, refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $this->assertSame(0, Refund::count(),
            "Status '{$status}' should be ineligible for refund creation.");
    }

    public static function ineligibleStatuses(): array
    {
        return [
            ['Pending'],
            ['Received'],
        ];
    }

    public function test_return_with_zero_refund_amount_cannot_create_refund(): void
    {
        // Inspector recorded no refund (e.g. damaged + no compensation).
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 0);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $this->assertSame(0, Refund::count());
    }

    /* ────────────────────── 2. Refund shape ────────────────────── */

    public function test_refund_created_from_return_is_linked_to_order_and_customer(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 50);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $refund = Refund::firstOrFail();
        $this->assertSame($return->id, $refund->order_return_id);
        $this->assertSame($return->order_id, $refund->order_id);
        $this->assertSame($return->customer_id, $refund->customer_id);
    }

    public function test_refund_created_from_return_remains_requested(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $refund = Refund::firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertNull($refund->approved_at, 'Must NOT auto-approve.');
        $this->assertNull($refund->approved_by);
        $this->assertNull($refund->paid_at, 'Must NOT auto-pay.');
        $this->assertNull($refund->cashbox_transaction_id);
    }

    public function test_creating_refund_from_return_does_not_create_cashbox_transaction(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count(),
            'Phase 5C must NOT write any cashbox_transactions row.');
    }

    /* ────────────────────── 3. Over-return guard ────────────────────── */

    public function test_partial_refunds_are_allowed_up_to_return_refundable_amount(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        // 40 + 60 = 100 exactly → both allowed.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 40])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 60])
            ->assertRedirect();

        $this->assertSame(2, Refund::where('order_return_id', $return->id)->count());
        $this->assertSame(
            100.0,
            (float) Refund::where('order_return_id', $return->id)->sum('amount')
        );
    }

    public function test_over_return_refund_amount_is_blocked(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        // First refund: 80 → OK.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 80])
            ->assertRedirect();

        // Second refund: 25 → cumulative 105 > 100 → service throws,
        // controller surfaces flash error, no row inserted.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 25])
            ->assertRedirect();

        $this->assertSame(1, Refund::where('order_return_id', $return->id)->count());
        $this->assertSame(80.0, (float) Refund::where('order_return_id', $return->id)->sum('amount'));
    }

    public function test_rejected_refund_is_excluded_from_return_refund_sum(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        // Request a 70 refund then reject it.
        $first = $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 70]);
        $first->assertRedirect();
        $rejected = Refund::where('order_return_id', $return->id)->firstOrFail();
        app(RefundService::class)->reject($rejected);

        // Now request 80 — should succeed (70 rejected doesn't count).
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund', ['amount' => 80])
            ->assertRedirect();

        $activeRefunds = Refund::where('order_return_id', $return->id)
            ->whereIn('status', Refund::ACTIVE_STATUSES)
            ->get();
        $this->assertCount(1, $activeRefunds);
        $this->assertSame('80.00', (string) $activeRefunds->first()->amount);
    }

    public function test_over_return_guard_also_applies_to_direct_refund_create(): void
    {
        // Direct create at /refunds (not via /returns/.../request-refund)
        // must still enforce the over-return guard when order_return_id
        // is supplied.
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);
        Refund::create([
            'order_return_id' => $return->id,
            'amount' => 80,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 30,
            'order_return_id' => $return->id,
        ]);
        $response->assertSessionHasErrors(['amount']);

        $this->assertSame(1, Refund::where('order_return_id', $return->id)->count());
    }

    public function test_existing_collection_over_refund_guard_still_works(): void
    {
        // Regression: Phase 5A's collection-level guard must still fire
        // alongside the new return-level guard.
        $collection = \App\Models\Collection::create([
            'order_id' => $this->makeOrder()->id,
            'amount_due' => 100,
            'amount_collected' => 100,
            'collection_status' => 'Collected',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 60,
            'collection_id' => $collection->id,
        ])->assertRedirect('/refunds');

        $response = $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 50,
            'collection_id' => $collection->id,
        ]);
        $response->assertSessionHasErrors(['amount']);
    }

    /* ────────────────────── 4. Permission gating ────────────────────── */

    public function test_user_without_refunds_create_cannot_request_refund_from_return(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $user = $this->userWith(['returns.view']);

        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertForbidden();

        $this->assertSame(0, Refund::count());
    }

    public function test_user_with_refunds_create_can_request_refund_from_return(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $user = $this->userWith(['refunds.create']);

        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $this->assertSame(1, Refund::count());
    }

    /* ────────────────────── 5. Approve / pay paths still work afterward ────────────────────── */

    public function test_refund_created_from_return_can_be_approved_via_existing_path(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $refund = Refund::firstOrFail();

        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/approve')
            ->assertRedirect();

        $this->assertSame('approved', $refund->fresh()->status);
    }

    /* ────────────────────── 6. Audit log ────────────────────── */

    public function test_request_refund_from_return_writes_audit_log(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 100);

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/request-refund')
            ->assertRedirect();

        $refund = Refund::firstOrFail();

        $this->assertSame(1, AuditLog::where('module', 'finance.refund')
            ->where('action', 'refund_requested_from_return')
            ->where('record_type', Refund::class)
            ->where('record_id', $refund->id)->count());
    }

    /* ────────────────────── 7. Show page surfaces refund context ────────────────────── */

    public function test_returns_show_page_exposes_refund_context_for_eligible_return(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 200);

        $response = $this->actingAs($this->admin)->get('/returns/' . $return->id);

        $response->assertOk();
        // Note: assertInertia's `where()` is strict-identity; JSON round-trips
        // `200.0` as `200` (int), so we compare loosely via callbacks.
        $response->assertInertia(fn ($page) => $page
            ->component('Returns/Show')
            ->where('refund_context.can_request_refund', true)
            ->where('refund_context.refundable_amount', fn ($v) => (float) $v === 200.0)
            ->where('refund_context.active_refund_total', fn ($v) => (float) $v === 0.0)
            ->where('refund_context.refund_base_amount', fn ($v) => (float) $v === 200.0)
        );
    }

    public function test_returns_show_refund_context_reflects_remaining_after_existing_refunds(): void
    {
        $return = $this->makeReturn(status: 'Inspected', refundAmount: 200);
        Refund::create([
            'order_return_id' => $return->id,
            'amount' => 75,
            'status' => 'approved',
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/returns/' . $return->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('refund_context.active_refund_total', fn ($v) => (float) $v === 75.0)
            ->where('refund_context.refundable_amount', fn ($v) => (float) $v === 125.0)
        );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'RR-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Return Refund Test ' . uniqid(),
            'slug' => 'return-refund-test-' . uniqid(),
            'description' => 'Return refund test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Return Refund Test User',
            'email' => 'return-refund+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
