<?php

namespace Tests\Feature\Finance;

use App\Models\Cashbox;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Finance Phase 2 — Payment Methods feature coverage.
 *
 * Validates business rules from docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md:
 *   - methods retired via deactivation (never hard-deleted)
 *   - unique `code` enforced
 *   - default_cashbox_id is OPTIONAL
 *   - the canonical 7 methods are seeded by PaymentMethodsSeeder
 *   - server-side permission gating
 */
class PaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    /* ────────────────────── Seeded defaults ────────────────────── */

    public function test_default_seeded_payment_methods_exist(): void
    {
        $codes = PaymentMethod::pluck('code')->all();
        foreach (['cash', 'visa_pos', 'vodafone_cash', 'bank_transfer', 'courier_cod', 'amazon_wallet', 'noon_wallet'] as $expected) {
            $this->assertContains($expected, $codes, "Seed missing canonical code '{$expected}'.");
        }
    }

    public function test_seeded_methods_have_no_default_cashbox(): void
    {
        // Cashboxes vary per install — seed deliberately leaves default null.
        $this->assertSame(0, PaymentMethod::whereNotNull('default_cashbox_id')->count());
    }

    /* ────────────────────── CRUD ────────────────────── */

    public function test_authorized_user_can_view_payment_methods(): void
    {
        $response = $this->actingAs($this->admin)->get('/payment-methods');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('PaymentMethods/Index'));
    }

    public function test_authorized_user_can_create_payment_method(): void
    {
        $this->actingAs($this->admin)->post('/payment-methods', [
            'name' => 'Apple Pay',
            'code' => 'apple_pay',
            'type' => 'digital_wallet',
            'default_cashbox_id' => null,
            'is_active' => true,
            'description' => 'Apple wallet integration',
        ])->assertRedirect('/payment-methods');

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'apple_pay',
            'name' => 'Apple Pay',
            'type' => 'digital_wallet',
        ]);
    }

    public function test_code_must_be_unique(): void
    {
        // Pick any seeded code that already exists.
        $existing = PaymentMethod::firstOrFail()->code;

        $response = $this->actingAs($this->admin)->post('/payment-methods', [
            'name' => 'Duplicate',
            'code' => $existing,
            'type' => 'cash',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_code_must_be_lower_snake_case(): void
    {
        $response = $this->actingAs($this->admin)->post('/payment-methods', [
            'name' => 'Wrong',
            'code' => 'BadCASE',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $response->assertSessionHasErrors(['code']);
    }

    public function test_default_cashbox_can_be_assigned(): void
    {
        $cashbox = $this->makeCashbox();

        $this->actingAs($this->admin)->post('/payment-methods', [
            'name' => 'With default cashbox',
            'code' => 'with_default',
            'type' => 'cash',
            'default_cashbox_id' => $cashbox->id,
            'is_active' => true,
        ])->assertRedirect('/payment-methods');

        $this->assertDatabaseHas('payment_methods', [
            'code' => 'with_default',
            'default_cashbox_id' => $cashbox->id,
        ]);
    }

    public function test_payment_method_can_be_updated(): void
    {
        $pm = PaymentMethod::firstOrFail();

        $this->actingAs($this->admin)->put('/payment-methods/' . $pm->id, [
            'name' => 'Renamed',
            'code' => $pm->code,
            'type' => $pm->type,
            'default_cashbox_id' => null,
            'is_active' => true,
            'description' => 'updated',
        ])->assertRedirect('/payment-methods');

        $this->assertSame('Renamed', $pm->fresh()->name);
        $this->assertSame('updated', $pm->fresh()->description);
    }

    public function test_payment_method_can_be_deactivated_and_reactivated(): void
    {
        $pm = PaymentMethod::firstOrFail();

        $this->actingAs($this->admin)
            ->post('/payment-methods/' . $pm->id . '/deactivate')
            ->assertRedirect();
        $this->assertFalse($pm->fresh()->is_active);

        $this->actingAs($this->admin)
            ->post('/payment-methods/' . $pm->id . '/reactivate')
            ->assertRedirect();
        $this->assertTrue($pm->fresh()->is_active);
    }

    /* ────────────────────── Permission gating ────────────────────── */

    public function test_unauthorized_user_cannot_view_payment_methods(): void
    {
        $user = $this->makeRestrictedUser(['orders.view']);
        $this->actingAs($user)->get('/payment-methods')->assertForbidden();
    }

    public function test_unauthorized_user_cannot_create_payment_method(): void
    {
        $user = $this->makeRestrictedUser(['payment_methods.view']);
        $this->actingAs($user)->post('/payment-methods', [
            'name' => 'X', 'code' => 'x', 'type' => 'cash', 'is_active' => true,
        ])->assertForbidden();

        $this->assertDatabaseMissing('payment_methods', ['code' => 'x']);
    }

    /* ────────────────────── No delete route ────────────────────── */

    public function test_no_delete_route_exists_for_payment_methods(): void
    {
        $matches = collect(Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'payment-methods'))
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
            ->all();

        $this->assertCount(0, $matches, 'Payment methods use deactivation, not hard delete.');
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeCashbox(array $overrides = []): Cashbox
    {
        return Cashbox::create(array_merge([
            'name' => 'PM Test Cashbox ' . uniqid(),
            'type' => 'cash',
            'currency_code' => 'EGP',
            'opening_balance' => 0,
            'allow_negative_balance' => true,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeRestrictedUser(array $permissionSlugs): User
    {
        $role = Role::create([
            'name' => 'PM Restricted ' . uniqid(),
            'slug' => 'pm-restricted-' . uniqid(),
            'description' => 'Payment method test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $permissionSlugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Restricted',
            'email' => 'pm-restricted+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
