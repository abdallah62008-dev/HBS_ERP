<?php

namespace Tests\Feature\Finance;

use App\Models\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\CashboxTransfer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\CashboxService;
use App\Services\CashboxTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 2 — Cashbox Transfers coverage.
 *
 * Validates:
 *   - one transfer writes exactly two cashbox_transactions
 *   - the two transactions are signed correctly and linked by transfer_id
 *   - from == to is rejected
 *   - amount <= 0 is rejected
 *   - inactive cashboxes refuse transfers
 *   - allow_negative_balance=false blocks overdraft
 *   - allow_negative_balance=true permits overdraft
 *   - cross-currency transfers rejected
 *   - permission gating
 *   - no DELETE route
 */
class CashboxTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    /* ────────────────────── Index page ────────────────────── */

    public function test_authorized_user_can_view_transfers_index(): void
    {
        $response = $this->actingAs($this->admin)->get('/cashbox-transfers');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('CashboxTransfers/Index'));
    }

    /* ────────────────────── Successful transfer ────────────────────── */

    public function test_transfer_creates_one_transfer_and_two_signed_transactions(): void
    {
        $from = $this->makeCashbox(['name' => 'Main Cash', 'opening_balance' => 1000]);
        $to = $this->makeCashbox(['name' => 'Bank Account']);

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 250,
            'occurred_at' => now()->toDateString(),
            'reason' => 'End-of-day deposit',
        ])->assertRedirect('/cashbox-transfers');

        $transfer = CashboxTransfer::first();
        $this->assertNotNull($transfer);
        $this->assertSame('250.00', (string) $transfer->amount);

        $txs = CashboxTransaction::where('transfer_id', $transfer->id)
            ->orderBy('cashbox_id')
            ->get();
        $this->assertCount(2, $txs);

        $out = CashboxTransaction::where('transfer_id', $transfer->id)
            ->where('cashbox_id', $from->id)->firstOrFail();
        $this->assertSame('out', $out->direction);
        $this->assertSame('-250.00', (string) $out->amount);
        $this->assertSame('transfer', $out->source_type);
        $this->assertSame($transfer->id, $out->source_id);

        $in = CashboxTransaction::where('transfer_id', $transfer->id)
            ->where('cashbox_id', $to->id)->firstOrFail();
        $this->assertSame('in', $in->direction);
        $this->assertSame('250.00', (string) $in->amount);
        $this->assertSame('transfer', $in->source_type);

        // Balances reflect the transfer.
        $this->assertSame(750.0, $from->fresh()->balance(), 'Source: 1000 - 250 = 750');
        $this->assertSame(250.0, $to->fresh()->balance(), 'Destination: 0 + 250 = 250');
    }

    public function test_transfer_recorded_via_service_directly(): void
    {
        $from = $this->makeCashbox(['name' => 'Direct From', 'opening_balance' => 500]);
        $to = $this->makeCashbox(['name' => 'Direct To']);

        $this->actingAs($this->admin);
        $transfer = app(CashboxTransferService::class)->createTransfer([
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 100,
        ]);

        $this->assertSame(2, $transfer->transactions()->count());
        $this->assertSame(400.0, $from->fresh()->balance());
        $this->assertSame(100.0, $to->fresh()->balance());
    }

    /* ────────────────────── Validation guards ────────────────────── */

    public function test_cannot_transfer_to_same_cashbox(): void
    {
        $cashbox = $this->makeCashbox(['opening_balance' => 100]);

        $response = $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $cashbox->id,
            'to_cashbox_id' => $cashbox->id,
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ]);
        $response->assertSessionHasErrors(['to_cashbox_id']);
        $this->assertSame(0, CashboxTransfer::count());
    }

    public function test_amount_must_be_positive(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 100]);
        $to = $this->makeCashbox();

        $response = $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 0,
            'occurred_at' => now()->toDateString(),
        ]);
        $response->assertSessionHasErrors(['amount']);

        $response = $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => -50,
            'occurred_at' => now()->toDateString(),
        ]);
        $response->assertSessionHasErrors(['amount']);

        $this->assertSame(0, CashboxTransfer::count());
    }

    public function test_inactive_source_cashbox_blocks_transfer(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 100, 'is_active' => false]);
        $to = $this->makeCashbox();

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors(['from_cashbox_id']);

        $this->assertSame(0, CashboxTransfer::count());
    }

    public function test_inactive_destination_cashbox_blocks_transfer(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 100]);
        $to = $this->makeCashbox(['is_active' => false]);

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors(['from_cashbox_id']);

        $this->assertSame(0, CashboxTransfer::count());
    }

    public function test_insufficient_balance_blocks_transfer_when_negative_not_allowed(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 50, 'allow_negative_balance' => false]);
        $to = $this->makeCashbox();

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 200,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors(['from_cashbox_id']);

        $this->assertSame(0, CashboxTransfer::count());
        $this->assertSame(50.0, $from->fresh()->balance());
    }

    public function test_transfer_allowed_when_allow_negative_balance_is_true(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 50, 'allow_negative_balance' => true]);
        $to = $this->makeCashbox();

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 200,
            'occurred_at' => now()->toDateString(),
        ])->assertRedirect('/cashbox-transfers');

        $this->assertSame(1, CashboxTransfer::count());
        $this->assertSame(-150.0, $from->fresh()->balance(), 'Negative balance permitted: 50 - 200 = -150');
    }

    public function test_cross_currency_transfer_is_rejected(): void
    {
        $from = $this->makeCashbox(['currency_code' => 'EGP', 'opening_balance' => 100]);
        $to = $this->makeCashbox(['currency_code' => 'USD']);

        $this->actingAs($this->admin)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertSessionHasErrors(['from_cashbox_id']);

        $this->assertSame(0, CashboxTransfer::count());
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_unauthorized_user_cannot_view_transfers(): void
    {
        $user = $this->makeRestrictedUser(['orders.view']);
        $this->actingAs($user)->get('/cashbox-transfers')->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_transfer(): void
    {
        $from = $this->makeCashbox(['opening_balance' => 100]);
        $to = $this->makeCashbox();

        $user = $this->makeRestrictedUser(['cashbox_transfers.view']);
        $this->actingAs($user)->post('/cashbox-transfers', [
            'from_cashbox_id' => $from->id,
            'to_cashbox_id' => $to->id,
            'amount' => 10,
            'occurred_at' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame(0, CashboxTransfer::count());
    }

    /* ────────────────────── No delete route ────────────────────── */

    public function test_no_delete_route_exists_for_cashbox_transfers(): void
    {
        $matches = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'cashbox-transfers'))
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
            ->all();

        $this->assertCount(0, $matches, 'Cashbox transfers are append-only — reversal-by-new-transfer.');
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        static $counter = 0;
        $counter++;

        $opening = (float) ($overrides['opening_balance'] ?? 0);
        $cashbox = Cashbox::create(array_merge([
            'name' => 'Transfer Test ' . $counter,
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => $opening,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));

        // Reproduce the opening_balance ledger row that CashboxService
        // writes during normal creation. Tests use Cashbox::create
        // directly for speed; the row is needed for accurate balance().
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

    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'Transfer Restricted ' . uniqid(),
            'slug' => 'transfer-restricted-' . uniqid(),
            'description' => 'Transfer test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted',
            'email' => 'transfer-restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
