<?php

namespace Tests\Feature\Returns;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Customer;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Professional Return Management — covers the limited details edit
 * endpoint, the Return Show page's order-context + mismatch flag,
 * and the data-integrity rules around forbidden fields.
 *
 * Companion to `tests/Feature/Orders/ReturnFromStatusChangeTest.php`
 * (which pins the order-side modal flow).
 */
class ReturnManagementTest extends TestCase
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

        $this->customer = Customer::create([
            'name' => 'RM Test Customer',
            'primary_phone' => '01066667777',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 RM Street',
            'created_by' => $this->admin->id,
        ]);

        $this->reason = ReturnReason::firstOrCreate(
            ['name' => 'RM Test Reason'],
            ['status' => 'Active'],
        );
    }

    /* ────────────────────── 1. Return Show context props ────────────────────── */

    public function test_show_page_exposes_linked_order_status(): void
    {
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order);

        $this->actingAs($this->admin)->get('/returns/' . $return->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Show')
                ->where('order_context.id', $order->id)
                ->where('order_context.order_number', $order->order_number)
                ->where('order_context.status', 'Returned')
                ->where('order_context.mismatch', false)
            );
    }

    public function test_show_page_shows_mismatch_when_order_status_is_inconsistent(): void
    {
        // Legacy scenario: a return exists for an order whose status was
        // never flipped to Returned (e.g. created via /returns/create
        // before the Phase 5G modal flow shipped).
        $order = $this->order(status: 'Delivered');
        $return = $this->makeReturn($order, returnStatus: 'Inspected');

        $this->actingAs($this->admin)->get('/returns/' . $return->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order_context.status', 'Delivered')
                ->where('order_context.mismatch', true)
            );
    }

    public function test_show_page_does_not_warn_during_pending_midflow(): void
    {
        // Order is still Delivered but the return is in Pending — this is
        // the normal mid-flow state during the new modal flow (the return
        // exists before the status flip completes). No warning yet.
        $order = $this->order(status: 'Delivered');
        $return = $this->makeReturn($order, returnStatus: 'Pending');

        $this->actingAs($this->admin)->get('/returns/' . $return->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order_context.mismatch', false)
            );
    }

    public function test_show_page_does_not_warn_when_order_is_returned(): void
    {
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Damaged');

        $this->actingAs($this->admin)->get('/returns/' . $return->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('order_context.mismatch', false)
            );
    }

    /* ────────────────────── 2. Edit endpoint — happy path ────────────────────── */

    public function test_user_with_returns_create_can_update_editable_fields(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, refundAmount: 100);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, [
                'refund_amount' => 175.50,
                'shipping_loss_amount' => 20,
                'notes' => 'Updated by operator',
            ])
            ->assertRedirect();

        $fresh = $return->fresh();
        $this->assertSame('175.50', (string) $fresh->refund_amount);
        $this->assertSame('20.00', (string) $fresh->shipping_loss_amount);
        $this->assertSame('Updated by operator', $fresh->notes);

        // State-machine fields untouched.
        $this->assertSame($return->return_status, $fresh->return_status);
        $this->assertSame($return->product_condition, $fresh->product_condition);
    }

    /* ────────────────────── 3. Permission gating ────────────────────── */

    public function test_user_without_returns_create_cannot_update(): void
    {
        $user = $this->userWith(['returns.view']);
        $order = $this->order();
        $return = $this->makeReturn($order);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, [
                'refund_amount' => 999,
                'notes' => 'should not save',
            ])
            ->assertForbidden();

        $this->assertSame('100.00', (string) $return->fresh()->refund_amount);
    }

    /* ────────────────────── 4. Closed-return immutability ────────────────────── */

    public function test_closed_return_cannot_be_updated(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Closed');

        $this->actingAs($user)
            ->from('/returns/' . $return->id)
            ->put('/returns/' . $return->id, [
                'refund_amount' => 9999,
                'notes' => 'attempted',
            ])
            ->assertRedirect('/returns/' . $return->id);

        $this->assertSame('100.00', (string) $return->fresh()->refund_amount);
        $this->assertNotSame('attempted', $return->fresh()->notes);
        $this->assertNotNull(session('error'));
    }

    /* ────────────────────── 5. Forbidden-field defence ────────────────────── */

    public function test_update_ignores_forbidden_fields(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Inspected', productCondition: 'Good');

        $originalStatus = $return->return_status;
        $originalCondition = $return->product_condition;
        $originalRestockable = $return->restockable;
        $originalReason = $return->return_reason_id;
        $originalOrderId = $return->order_id;

        $this->actingAs($user)
            ->put('/returns/' . $return->id, [
                // Allowed fields:
                'notes' => 'just notes',
                // Forbidden fields — must be ignored at the service layer:
                'return_status' => 'Closed',
                'product_condition' => 'Damaged',
                'restockable' => false,
                'order_id' => 99999,
                'return_reason_id' => 99999,
            ])
            ->assertRedirect();

        $fresh = $return->fresh();
        $this->assertSame('just notes', $fresh->notes);
        $this->assertSame($originalStatus, $fresh->return_status, 'return_status must be untouched');
        $this->assertSame($originalCondition, $fresh->product_condition, 'product_condition must be untouched');
        $this->assertSame($originalRestockable, $fresh->restockable, 'restockable must be untouched');
        $this->assertSame($originalReason, $fresh->return_reason_id, 'return_reason_id must be untouched');
        $this->assertSame($originalOrderId, $fresh->order_id, 'order_id must be untouched');
    }

    /* ────────────────────── 6. No side effects ────────────────────── */

    public function test_update_does_not_change_order_status(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Delivered');
        $return = $this->makeReturn($order);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, ['notes' => 'updated'])
            ->assertRedirect();

        $this->assertSame('Delivered', $order->fresh()->status);
    }

    public function test_update_does_not_create_refund(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, ['refund_amount' => 250])
            ->assertRedirect();

        $this->assertSame(0, Refund::count());
    }

    public function test_update_does_not_create_cashbox_transaction(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order);

        $before = CashboxTransaction::count();

        $this->actingAs($user)
            ->put('/returns/' . $return->id, [
                'refund_amount' => 250,
                'shipping_loss_amount' => 50,
                'notes' => 'updated',
            ])
            ->assertRedirect();

        $this->assertSame($before, CashboxTransaction::count());
    }

    /* ────────────────────── 7. Refund-amount safety guard ────────────────────── */

    public function test_refund_amount_cannot_be_reduced_below_active_refunds(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Inspected', refundAmount: 300);

        // Two active refunds totalling 200.
        Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $return->id,
            'amount' => 120,
            'status' => Refund::STATUS_REQUESTED,
            'requested_by' => $this->admin->id,
        ]);
        Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $return->id,
            'amount' => 80,
            'status' => Refund::STATUS_APPROVED,
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);

        // Reducing to 150 would invalidate the 200 active total → block.
        $this->actingAs($user)
            ->from('/returns/' . $return->id)
            ->put('/returns/' . $return->id, ['refund_amount' => 150])
            ->assertRedirect('/returns/' . $return->id);

        $this->assertSame('300.00', (string) $return->fresh()->refund_amount);
        $this->assertNotNull(session('error'));
    }

    public function test_refund_amount_can_be_increased_freely(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Inspected', refundAmount: 100);

        Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $return->id,
            'amount' => 50,
            'status' => Refund::STATUS_APPROVED,
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, ['refund_amount' => 250])
            ->assertRedirect();

        $this->assertSame('250.00', (string) $return->fresh()->refund_amount);
    }

    public function test_rejected_refunds_do_not_count_toward_lower_bound(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Inspected', refundAmount: 300);

        // Active 100 + rejected 200. Lower bound = 100, not 300.
        Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $return->id,
            'amount' => 100,
            'status' => Refund::STATUS_APPROVED,
            'requested_by' => $this->admin->id,
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
        ]);
        Refund::create([
            'order_id' => $order->id,
            'order_return_id' => $return->id,
            'amount' => 200,
            'status' => Refund::STATUS_REJECTED,
            'requested_by' => $this->admin->id,
            'rejected_by' => $this->admin->id,
            'rejected_at' => now(),
        ]);

        // Reducing to 100 is allowed (100 active ≤ 100 new).
        $this->actingAs($user)
            ->put('/returns/' . $return->id, ['refund_amount' => 100])
            ->assertRedirect();

        $this->assertSame('100.00', (string) $return->fresh()->refund_amount);
    }

    /* ────────────────────── 8. Audit log ────────────────────── */

    public function test_update_writes_audit_log(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order);

        $this->actingAs($user)
            ->put('/returns/' . $return->id, [
                'refund_amount' => 175,
                'notes' => 'audit me',
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            AuditLog::query()
                ->where('module', 'returns')
                ->where('action', 'updated')
                ->where('record_type', OrderReturn::class)
                ->where('record_id', $return->id)
                ->count(),
            'updated audit row must be written exactly once',
        );
    }

    public function test_no_op_update_does_not_write_audit_log(): void
    {
        $user = $this->userWith(['returns.create', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order);

        // Empty payload — no fields submitted, so nothing changes.
        $this->actingAs($user)
            ->put('/returns/' . $return->id, [])
            ->assertRedirect();

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('module', 'returns')
                ->where('action', 'updated')
                ->where('record_id', $return->id)
                ->count(),
            'No audit row for a no-op update',
        );
    }

    /* ────────────────────── 9. Existing inspect / close still work ────────────────────── */

    public function test_existing_inspect_flow_still_works(): void
    {
        $user = $this->userWith(['returns.inspect', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Pending', productCondition: 'Unknown');

        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/inspect', [
                'product_condition' => 'Good',
                'restockable' => true,
                'refund_amount' => 150,
            ])
            ->assertRedirect();

        $fresh = $return->fresh();
        $this->assertContains($fresh->return_status, ['Restocked', 'Damaged']);
        $this->assertSame('Good', $fresh->product_condition);
    }

    public function test_existing_close_flow_still_works(): void
    {
        $user = $this->userWith(['returns.approve', 'returns.view']);
        $order = $this->order(status: 'Returned');
        $return = $this->makeReturn($order, returnStatus: 'Restocked');

        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/close', ['note' => 'done'])
            ->assertRedirect();

        $this->assertSame('Closed', $return->fresh()->return_status);
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function order(string $status = 'Delivered'): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'RM-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => $status,
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

    private function makeReturn(
        Order $order,
        string $returnStatus = 'Pending',
        string $productCondition = 'Good',
        float $refundAmount = 100,
    ): OrderReturn {
        return OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => $returnStatus,
            'product_condition' => $productCondition,
            'refund_amount' => $refundAmount,
            'shipping_loss_amount' => 0,
            'restockable' => $productCondition === 'Good',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'RM Test ' . uniqid(),
            'slug' => 'rm-test-' . uniqid(),
            'description' => 'Return management test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'RM Test User',
            'email' => 'rm-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
