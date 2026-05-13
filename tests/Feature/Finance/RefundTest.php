<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\CashboxTransaction;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 5A — Refunds Foundation feature coverage.
 *
 * Phase 5A is paperwork-only: Requested → Approved | Rejected. No
 * `pay` action, no cashbox OUT transaction, no marketer reversal, no
 * return-impact wiring. These tests pin those boundaries.
 */
class RefundTest extends TestCase
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
            'name' => 'Refund Test Customer',
            'primary_phone' => '01099992222',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Refund Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Create / permission gating ────────────────────── */

    public function test_user_with_refunds_create_can_create_requested_refund(): void
    {
        $user = $this->userWith(['refunds.create']);

        $this->actingAs($user)->post('/refunds', [
            'amount' => 100,
            'reason' => 'damaged on arrival',
        ])->assertRedirect('/refunds');

        $refund = Refund::firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame('100.00', (string) $refund->amount);
        $this->assertSame($user->id, $refund->requested_by);
    }

    public function test_user_without_refunds_create_cannot_create_refund(): void
    {
        $user = $this->userWith(['refunds.view']);

        $this->actingAs($user)->post('/refunds', [
            'amount' => 100,
            'reason' => 'shouldnt persist',
        ])->assertForbidden();

        $this->assertSame(0, Refund::count());
    }

    /* ────────────────────── 2. Approve / permission gating ────────────────────── */

    public function test_user_with_refunds_approve_can_approve(): void
    {
        $refund = $this->makeRefund();
        $user = $this->userWith(['refunds.view', 'refunds.approve']);

        $this->actingAs($user)
            ->post('/refunds/' . $refund->id . '/approve')
            ->assertRedirect();

        $fresh = $refund->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame($user->id, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_user_without_refunds_approve_cannot_approve(): void
    {
        $refund = $this->makeRefund();
        $user = $this->userWith(['refunds.view', 'refunds.create']);

        $this->actingAs($user)
            ->post('/refunds/' . $refund->id . '/approve')
            ->assertForbidden();

        $this->assertSame('requested', $refund->fresh()->status);
    }

    /* ────────────────────── 3. Reject / permission gating ────────────────────── */

    public function test_user_with_refunds_reject_can_reject(): void
    {
        $refund = $this->makeRefund();
        $user = $this->userWith(['refunds.view', 'refunds.reject']);

        $this->actingAs($user)
            ->post('/refunds/' . $refund->id . '/reject')
            ->assertRedirect();

        $fresh = $refund->fresh();
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame($user->id, $fresh->rejected_by);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_user_without_refunds_reject_cannot_reject(): void
    {
        $refund = $this->makeRefund();
        $user = $this->userWith(['refunds.view', 'refunds.create']);

        $this->actingAs($user)
            ->post('/refunds/' . $refund->id . '/reject')
            ->assertForbidden();

        $this->assertSame('requested', $refund->fresh()->status);
    }

    /* ────────────────────── 4. Lifecycle: edit / delete ────────────────────── */

    public function test_requested_refund_can_be_edited(): void
    {
        $refund = $this->makeRefund(['amount' => 100]);

        $this->actingAs($this->admin)->put('/refunds/' . $refund->id, [
            'amount' => 150,
            'reason' => 'updated',
        ])->assertRedirect('/refunds');

        $fresh = $refund->fresh();
        $this->assertSame('150.00', (string) $fresh->amount);
        $this->assertSame('updated', $fresh->reason);
    }

    public function test_approved_refund_cannot_be_edited(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->approve($refund);

        $this->actingAs($this->admin)->put('/refunds/' . $refund->id, [
            'amount' => 9999,
            'reason' => 'attempted drift',
        ])->assertRedirect();

        $this->assertSame('100.00', (string) $refund->fresh()->amount);
    }

    public function test_rejected_refund_cannot_be_edited(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->reject($refund);

        $this->actingAs($this->admin)->put('/refunds/' . $refund->id, [
            'amount' => 9999,
            'reason' => 'attempted drift',
        ])->assertRedirect();

        $this->assertSame('100.00', (string) $refund->fresh()->amount);
    }

    public function test_requested_refund_can_be_deleted(): void
    {
        $refund = $this->makeRefund();

        $this->actingAs($this->admin)
            ->delete('/refunds/' . $refund->id)
            ->assertRedirect('/refunds');

        $this->assertNull(Refund::find($refund->id));
    }

    public function test_approved_refund_cannot_be_deleted_via_controller(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->approve($refund);

        $this->actingAs($this->admin)
            ->delete('/refunds/' . $refund->id)
            ->assertRedirect();

        $this->assertNotNull(Refund::find($refund->id));
    }

    public function test_rejected_refund_cannot_be_deleted_via_controller(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->reject($refund);

        $this->actingAs($this->admin)
            ->delete('/refunds/' . $refund->id)
            ->assertRedirect();

        $this->assertNotNull(Refund::find($refund->id));
    }

    public function test_model_delete_hook_blocks_non_requested_refunds(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->approve($refund);

        // Direct model delete (bypass controller).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $refund->fresh()->delete();
    }

    /* ────────────────────── 5. Forbidden transitions ────────────────────── */

    public function test_approved_refund_cannot_be_rejected(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->approve($refund);

        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/reject')
            ->assertRedirect();

        $this->assertSame('approved', $refund->fresh()->status);
    }

    public function test_rejected_refund_cannot_be_approved(): void
    {
        $refund = $this->makeRefund();
        app(\App\Services\RefundService::class)->reject($refund);

        $this->actingAs($this->admin)
            ->post('/refunds/' . $refund->id . '/approve')
            ->assertRedirect();

        $this->assertSame('rejected', $refund->fresh()->status);
    }

    /* ────────────────────── 6. Over-refund guard ────────────────────── */

    public function test_over_refund_is_blocked_for_collection_linked_refunds(): void
    {
        $collection = $this->makeCollection(amountCollected: 100);

        // First refund of 60 — OK.
        $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 60,
            'collection_id' => $collection->id,
        ])->assertRedirect('/refunds');

        // Second refund of 41 — would push cumulative to 101, blocked.
        $response = $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 41,
            'collection_id' => $collection->id,
        ]);
        $response->assertSessionHasErrors(['amount']);

        $this->assertSame(1, Refund::where('collection_id', $collection->id)->count());
    }

    public function test_rejected_refund_is_excluded_from_over_refund_sum(): void
    {
        $collection = $this->makeCollection(amountCollected: 100);

        // Create + reject a 60 refund.
        $rejected = Refund::create([
            'collection_id' => $collection->id,
            'amount' => 60,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        app(\App\Services\RefundService::class)->reject($rejected);

        // A new 70 refund should still be allowed because the rejected
        // 60 doesn't count toward the active sum.
        $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 70,
            'collection_id' => $collection->id,
        ])->assertRedirect('/refunds');

        $this->assertSame(2, Refund::where('collection_id', $collection->id)->count());
        $this->assertSame(70.0, (float) Refund::where('collection_id', $collection->id)
            ->where('status', 'requested')->sum('amount'));
    }

    public function test_approve_runs_over_refund_guard_against_active_set(): void
    {
        $collection = $this->makeCollection(amountCollected: 100);

        // Two requested refunds whose combined active sum (110) already
        // exceeds the base (100). This shape simulates data drift from
        // a non-controller code path (direct insert, fixture, future
        // bulk-tool, etc.) — approve must still refuse rather than
        // silently moving one of them past the limit.
        $a = Refund::create([
            'collection_id' => $collection->id,
            'amount' => 50,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        Refund::create([
            'collection_id' => $collection->id,
            'amount' => 60,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        // Approve(a) — existing (excluding a) is the other refund (60).
        // Proposed 50. Total 110 > 100. Service throws.
        $this->expectException(\InvalidArgumentException::class);
        app(\App\Services\RefundService::class)->approve($a);
    }

    public function test_order_only_refund_skips_over_refund_guard_in_phase_5a(): void
    {
        // Documented Phase 5A limitation — refunds without a collection
        // link cannot enforce a per-order refundable base. Multiple
        // order-only refunds for the same order are allowed.
        $order = $this->makeOrder();
        $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 500,
            'order_id' => $order->id,
        ])->assertRedirect('/refunds');
        $this->actingAs($this->admin)->post('/refunds', [
            'amount' => 500,
            'order_id' => $order->id,
        ])->assertRedirect('/refunds');

        $this->assertSame(2, Refund::where('order_id', $order->id)->count());
    }

    /* ────────────────────── 7. Phase 5A absence guarantees ────────────────────── */

    public function test_no_pay_route_exists(): void
    {
        $hasPayRoute = collect(Route::getRoutes())
            ->contains(fn ($r) => str_contains($r->uri(), 'refunds') && str_contains($r->uri(), 'pay'));

        $this->assertFalse($hasPayRoute, 'Phase 5A must not expose a refunds.pay route.');
    }

    public function test_approve_and_reject_do_not_create_cashbox_transaction(): void
    {
        $collection = $this->makeCollection(amountCollected: 100);
        $a = Refund::create([
            'collection_id' => $collection->id,
            'amount' => 50,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);
        $b = Refund::create([
            'collection_id' => $collection->id,
            'amount' => 50,
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $svc = app(\App\Services\RefundService::class);
        $svc->approve($a);
        $svc->reject($b);

        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count(),
            'Phase 5A must NOT write any cashbox transaction with source_type=refund.');
    }

    /* ────────────────────── 8. Audit log ────────────────────── */

    public function test_lifecycle_actions_write_audit_log(): void
    {
        $this->actingAs($this->admin);

        // Create through the controller so the audit row is written
        // (the `makeRefund` helper uses Refund::create directly to keep
        // other tests simple — it bypasses controller-level audit).
        $this->post('/refunds', [
            'amount' => 100,
            'reason' => 'audit-trail test',
        ])->assertRedirect('/refunds');

        $refund = Refund::latest('id')->firstOrFail();

        $this->assertGreaterThanOrEqual(
            1,
            AuditLog::where('module', 'finance.refund')
                ->where('action', 'refund_created')
                ->where('record_id', $refund->id)->count(),
            'Controller should have written a refund_created audit row.'
        );

        $svc = app(\App\Services\RefundService::class);
        $svc->approve($refund);
        $this->assertSame(1, AuditLog::where('module', 'finance.refund')
            ->where('action', 'refund_approved')
            ->where('record_id', $refund->id)->count());

        $other = $this->makeRefund();
        $svc->reject($other);
        $this->assertSame(1, AuditLog::where('module', 'finance.refund')
            ->where('action', 'refund_rejected')
            ->where('record_id', $other->id)->count());
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeRefund(array $overrides = []): Refund
    {
        return Refund::create(array_merge([
            'amount' => 100,
            'reason' => 'test refund',
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'RFD-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
            'total_amount' => 100,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Build a Collection with a real amount_collected so the over-refund
     * guard has a base to check against.
     */
    private function makeCollection(float $amountCollected): Collection
    {
        $order = $this->makeOrder();
        return Collection::create([
            'order_id' => $order->id,
            'amount_due' => $amountCollected,
            'amount_collected' => $amountCollected,
            'collection_status' => 'Collected',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    /**
     * Create a User with a fresh Role granting exactly the listed
     * permission slugs.
     */
    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Refund Test ' . uniqid(),
            'slug' => 'refund-test-' . uniqid(),
            'description' => 'Refund test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Refund Test User',
            'email' => 'refund-test+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
