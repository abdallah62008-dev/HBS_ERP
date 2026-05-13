<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 1 — feature coverage for the Cashboxes module.
 *
 * Validates the core business rules from docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - opening_balance writes one cashbox_transaction row at creation
 *   - balance is calculated from transactions (no current_balance column)
 *   - opening_balance is immutable after creation
 *   - cashboxes are retired via deactivation, never hard-deleted
 *   - deactivated cashboxes remain readable
 *   - manual adjustments require notes
 *   - no delete route exists for cashbox_transactions
 *   - permission gating is enforced server-side
 *   - audit logs are written for cashbox.created + transaction.created
 */
class CashboxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    /* ────────────────────── Creation + opening balance ────────────────────── */

    public function test_authorized_user_can_create_cashbox(): void
    {
        $response = $this->actingAs($this->admin)->post('/cashboxes', [
            'name' => 'Main Cash',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'description' => 'Primary till',
        ]);

        $response->assertRedirect('/cashboxes');
        $this->assertDatabaseHas('cashboxes', [
            'name' => 'Main Cash',
            'type' => 'cash',
            'currency_code' => 'EGP',
        ]);
    }

    public function test_opening_balance_creates_opening_balance_transaction(): void
    {
        $this->actingAs($this->admin)->post('/cashboxes', [
            'name' => 'Visa POS',
            'type' => 'bank',
            'currency_code' => 'EGP',
            'opening_balance' => 1500,
            'allow_negative_balance' => true,
            'is_active' => true,
            'description' => null,
        ])->assertRedirect('/cashboxes');

        $cashbox = Cashbox::where('name', 'Visa POS')->firstOrFail();
        $txs = CashboxTransaction::where('cashbox_id', $cashbox->id)->get();

        $this->assertCount(1, $txs);
        $this->assertSame('opening_balance', $txs->first()->source_type);
        $this->assertSame('1500.00', (string) $txs->first()->amount);
        $this->assertSame('in', $txs->first()->direction);
    }

    public function test_zero_opening_balance_writes_no_transaction(): void
    {
        $this->actingAs($this->admin)->post('/cashboxes', [
            'name' => 'Empty Box',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'description' => null,
        ])->assertRedirect('/cashboxes');

        $cashbox = Cashbox::where('name', 'Empty Box')->firstOrFail();
        $this->assertSame(0, CashboxTransaction::where('cashbox_id', $cashbox->id)->count());
    }

    public function test_balance_is_calculated_from_transactions(): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'Sum Test',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 100,
        ]);

        $this->actingAs($this->admin);
        app(CashboxService::class)->createAdjustmentTransaction($cashbox, [
            'direction' => 'in', 'amount' => 50, 'notes' => 'top up',
        ]);
        app(CashboxService::class)->createAdjustmentTransaction($cashbox, [
            'direction' => 'out', 'amount' => 30, 'notes' => 'petty cash',
        ]);

        // Reload to discard relationship cache.
        $fresh = Cashbox::find($cashbox->id);
        $this->assertSame(120.0, $fresh->balance(), '100 + 50 − 30 = 120');
    }

    /* ────────────────────── Update + opening balance immutability ────────────────────── */

    public function test_cashbox_can_be_updated(): void
    {
        $cashbox = $this->makeCashbox(['name' => 'Old name']);

        $this->actingAs($this->admin)->put('/cashboxes/' . $cashbox->id, [
            'name' => 'New name',
            'type' => 'cash',
            'allow_negative_balance' => false,
            'is_active' => true,
            'description' => 'updated',
        ])->assertRedirect('/cashboxes');

        $cashbox->refresh();
        $this->assertSame('New name', $cashbox->name);
        $this->assertFalse($cashbox->allow_negative_balance);
        $this->assertSame('updated', $cashbox->description);
    }

    public function test_opening_balance_cannot_be_edited_via_update(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);

        $this->actingAs($this->admin)->put('/cashboxes/' . $cashbox->id, [
            'name' => $cashbox->name,
            'type' => $cashbox->type,
            'allow_negative_balance' => true,
            'is_active' => true,
            'description' => null,
            // Attempt to change opening_balance — must be silently ignored.
            'opening_balance' => 9999,
        ])->assertRedirect('/cashboxes');

        $cashbox->refresh();
        $this->assertSame('100.00', (string) $cashbox->opening_balance, 'opening_balance must not change.');
    }

    public function test_service_refuses_second_opening_balance(): void
    {
        $cashbox = app(CashboxService::class)->createCashbox([
            'name' => 'Already opened',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 50,
        ]);

        $this->expectException(\RuntimeException::class);
        app(CashboxService::class)->createOpeningBalanceTransaction($cashbox, 50);
    }

    /* ────────────────────── Deactivation ────────────────────── */

    public function test_cashbox_can_be_deactivated(): void
    {
        $cashbox = $this->makeCashbox();

        $this->actingAs($this->admin)
            ->post('/cashboxes/' . $cashbox->id . '/deactivate')
            ->assertRedirect();

        $this->assertFalse($cashbox->fresh()->is_active);
    }

    public function test_deactivated_cashbox_remains_viewable(): void
    {
        $cashbox = $this->makeCashbox(['is_active' => false]);

        $response = $this->actingAs($this->admin)->get('/cashboxes/' . $cashbox->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cashboxes/Statement')
            ->where('cashbox.is_active', false)
        );
    }

    public function test_deactivated_cashbox_refuses_new_adjustments(): void
    {
        $cashbox = $this->makeCashbox(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->actingAs($this->admin);
        app(CashboxService::class)->createAdjustmentTransaction($cashbox, [
            'direction' => 'in', 'amount' => 10, 'notes' => 'should fail',
        ]);
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_unauthorized_user_cannot_view_cashboxes(): void
    {
        $user = $this->makeRestrictedUser(['orders.view']);

        $this->actingAs($user)->get('/cashboxes')->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_cashbox(): void
    {
        $user = $this->makeRestrictedUser(['cashboxes.view']);

        $this->actingAs($user)->post('/cashboxes', [
            'name' => 'Forbidden',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('cashboxes', ['name' => 'Forbidden']);
    }

    public function test_statement_page_loads_for_authorized_user(): void
    {
        $cashbox = $this->makeCashbox();
        $user = $this->makeRestrictedUser(['cashboxes.view', 'cashbox_transactions.view']);

        $response = $this->actingAs($user)->get('/cashboxes/' . $cashbox->id);

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Cashboxes/Statement')
            ->where('cashbox.id', $cashbox->id)
        );
    }

    /* ────────────────────── Adjustments ────────────────────── */

    public function test_manual_adjustment_requires_notes(): void
    {
        $cashbox = $this->makeCashbox();

        $response = $this->actingAs($this->admin)->post(
            '/cashboxes/' . $cashbox->id . '/transactions',
            ['direction' => 'in', 'amount' => 10, 'notes' => '']
        );

        $response->assertSessionHasErrors(['notes']);
        $this->assertSame(0, CashboxTransaction::where('cashbox_id', $cashbox->id)->count());
    }

    public function test_manual_adjustment_writes_signed_amount(): void
    {
        $cashbox = $this->makeCashbox();

        // OUT 25 should land as amount = -25.
        $this->actingAs($this->admin)->post(
            '/cashboxes/' . $cashbox->id . '/transactions',
            ['direction' => 'out', 'amount' => 25, 'notes' => 'lunch']
        )->assertRedirect();

        $tx = CashboxTransaction::where('cashbox_id', $cashbox->id)->first();
        $this->assertNotNull($tx);
        $this->assertSame('out', $tx->direction);
        $this->assertSame('-25.00', (string) $tx->amount);
        $this->assertSame('adjustment', $tx->source_type);
    }

    /* ────────────────────── No delete route ────────────────────── */

    public function test_no_delete_route_exists_for_cashbox_transactions(): void
    {
        // Confirms append-only design: there is no destroy endpoint for
        // cashbox_transactions at all (not even one that returns 403).
        $matches = collect(Route::getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'cashbox') && str_contains($r->uri(), 'transactions'))
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
            ->all();

        $this->assertCount(0, $matches, 'No DELETE route should exist on cashbox transactions.');
    }

    public function test_no_delete_route_exists_for_cashboxes(): void
    {
        $matches = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'cashboxes'))
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
            ->all();

        $this->assertCount(0, $matches, 'Cashboxes use deactivation, not hard delete.');
    }

    /* ────────────────────── Audit log coverage ────────────────────── */

    public function test_audit_log_is_written_for_cashbox_creation_and_transaction(): void
    {
        $this->actingAs($this->admin)->post('/cashboxes', [
            'name' => 'Audited Box',
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 200,
            'allow_negative_balance' => true,
            'is_active' => true,
        ])->assertRedirect('/cashboxes');

        $cashbox = Cashbox::where('name', 'Audited Box')->firstOrFail();

        $cashboxAudit = AuditLog::where('record_type', Cashbox::class)
            ->where('record_id', $cashbox->id)
            ->where('action', 'created')
            ->where('module', CashboxService::MODULE)
            ->first();
        $this->assertNotNull($cashboxAudit, 'Cashbox creation audit row missing.');

        $txAudit = AuditLog::where('record_type', CashboxTransaction::class)
            ->where('action', 'cashbox_transaction.created')
            ->where('module', CashboxService::MODULE)
            ->first();
        $this->assertNotNull($txAudit, 'Opening-balance transaction audit row missing.');
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;

        $defaults = [
            'name' => 'Test Cashbox ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'description' => null,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ];

        return Cashbox::create(array_merge($defaults, $overrides));
    }

    /**
     * Create a user whose role grants only the listed permissions.
     *
     * @param  array<int, string>  $permissionSlugs
     */
    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Test Restricted ' . uniqid(),
            'slug' => 'test-restricted-' . uniqid(),
            'description' => 'Cashbox-test scoped role.',
            'is_system' => false,
        ]);

        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted User',
            'email' => 'cashbox-restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
