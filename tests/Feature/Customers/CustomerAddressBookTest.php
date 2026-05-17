<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-4B — Address Book UX on the existing `customer_addresses`
 * table. Tests pin:
 *   - Show ships the `customer_addresses` prop.
 *   - Store creates the row with the actor; first address auto-defaults.
 *   - Setting default clears other defaults (single-default invariant).
 *   - Update persists changes.
 *   - Destroy removes the row; deleting the default promotes the next.
 *   - Permission gates honour existing `customers.edit` / `customers.delete`.
 *   - Cross-customer mutations are 404.
 *   - Backfill artisan command is idempotent + supports dry-run.
 *   - Timeline picks up `customer_address_added` events.
 *
 * No new permission slugs introduced — the test exercises the existing
 * customers.* surface.
 */
class CustomerAddressBookTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_customer_show_ships_addresses_prop(): void
    {
        $customer = $this->makeCustomer();
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'address' => '12 Test Street',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'is_default' => true,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->has('customer_addresses', 1)
                ->where('customer_addresses.0.address', '12 Test Street')
                ->where('customer_addresses.0.is_default', true)
                ->where('can_manage_addresses', true)
            );
    }

    public function test_store_address_creates_row_with_actor(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.show', $customer->id))
            ->post(route('customers.addresses.store', $customer->id), [
                'address' => '1 Apartment Lane',
                'city' => 'Alexandria',
                'governorate' => 'Alexandria',
                'country' => 'Egypt',
            ])
            ->assertRedirect(route('customers.show', $customer->id))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address' => '1 Apartment Lane',
            'city' => 'Alexandria',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_first_address_becomes_default_automatically(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->post(route('customers.addresses.store', $customer->id), [
                'address' => 'Only one',
                'city' => 'Cairo',
                'country' => 'Egypt',
                // is_default NOT sent
            ])
            ->assertRedirect();

        $row = CustomerAddress::where('customer_id', $customer->id)->firstOrFail();
        $this->assertTrue((bool) $row->is_default, 'First address should auto-default.');

        // Legacy `customers.default_address` was also synced.
        $this->assertSame('Only one', $customer->fresh()->default_address);
    }

    public function test_setting_default_clears_other_defaults_for_same_customer(): void
    {
        $customer = $this->makeCustomer();
        $first = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'A', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true]);
        $second = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'B', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => false]);

        $this->actingAs($this->admin)
            ->patch(route('customers.addresses.default', [$customer->id, $second->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse((bool) $first->fresh()->is_default, 'First address should no longer be default.');
        $this->assertTrue((bool) $second->fresh()->is_default);
        // Legacy column reflects the new default.
        $this->assertSame('B', $customer->fresh()->default_address);
    }

    public function test_update_address_persists_changes(): void
    {
        $customer = $this->makeCustomer();
        $addr = CustomerAddress::create([
            'customer_id' => $customer->id,
            'address' => 'old', 'city' => 'old city', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true,
        ]);

        $this->actingAs($this->admin)
            ->put(route('customers.addresses.update', [$customer->id, $addr->id]), [
                'address' => 'new',
                'city' => 'new city',
                'governorate' => 'Cairo',
                'country' => 'Egypt',
                'is_default' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $addr->refresh();
        $this->assertSame('new', $addr->address);
        $this->assertSame('new city', $addr->city);
        $this->assertSame('Cairo', $addr->governorate);
        $this->assertSame($this->admin->id, (int) $addr->updated_by);
        // Default sync to legacy customer columns.
        $this->assertSame('new', $customer->fresh()->default_address);
    }

    public function test_destroy_address_deletes_row(): void
    {
        $customer = $this->makeCustomer();
        $a = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'doomed', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => false]);

        $this->actingAs($this->admin)
            ->delete(route('customers.addresses.destroy', [$customer->id, $a->id]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('customer_addresses', ['id' => $a->id]);
    }

    public function test_destroy_default_address_promotes_or_clears_default_safely(): void
    {
        $customer = $this->makeCustomer();
        // Default + one non-default sibling. Deleting the default
        // should promote the sibling.
        $default = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'default', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true]);
        $sibling = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'sibling', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => false]);

        $this->actingAs($this->admin)
            ->delete(route('customers.addresses.destroy', [$customer->id, $default->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $default->id]);
        $this->assertTrue((bool) $sibling->fresh()->is_default, 'Sibling should be promoted to default.');
        $this->assertSame('sibling', $customer->fresh()->default_address);

        // Now delete the last remaining address. Customer.default_address
        // is intentionally NOT cleared (preserves last-known value for
        // downstream reports). Database row IS removed.
        $this->actingAs($this->admin)
            ->delete(route('customers.addresses.destroy', [$customer->id, $sibling->id]))
            ->assertRedirect();
        $this->assertSame(0, CustomerAddress::where('customer_id', $customer->id)->count());
    }

    public function test_address_actions_require_customers_edit_or_delete_permission(): void
    {
        $customer = $this->makeCustomer();
        $addr = CustomerAddress::create(['customer_id' => $customer->id, 'address' => 'A', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true]);

        // customers.view only — no edit / delete.
        $viewer = $this->userWith(['customers.view']);

        $this->actingAs($viewer)
            ->post(route('customers.addresses.store', $customer->id), ['address' => 'should fail', 'city' => 'Cairo', 'country' => 'Egypt'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->put(route('customers.addresses.update', [$customer->id, $addr->id]), ['address' => 'nope', 'city' => 'Cairo', 'country' => 'Egypt'])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->patch(route('customers.addresses.default', [$customer->id, $addr->id]))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->delete(route('customers.addresses.destroy', [$customer->id, $addr->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('customer_addresses', ['id' => $addr->id, 'address' => 'A']);
    }

    public function test_cannot_mutate_address_from_another_customer(): void
    {
        $a = $this->makeCustomer(['name' => 'Customer A']);
        $b = $this->makeCustomer(['name' => 'Customer B']);
        $addrOnB = CustomerAddress::create(['customer_id' => $b->id, 'address' => 'belongs to B', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true]);

        $this->actingAs($this->admin)
            ->put(route('customers.addresses.update', [$a->id, $addrOnB->id]), ['address' => 'hijack', 'city' => 'Cairo', 'country' => 'Egypt'])
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->patch(route('customers.addresses.default', [$a->id, $addrOnB->id]))
            ->assertNotFound();

        $this->actingAs($this->admin)
            ->delete(route('customers.addresses.destroy', [$a->id, $addrOnB->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_addresses', ['id' => $addrOnB->id, 'address' => 'belongs to B']);
    }

    public function test_backfill_command_copies_default_address_idempotently(): void
    {
        $eligible = $this->makeCustomer([
            'default_address' => '7 Backfill Lane',
            'city' => 'Cairo',
            'country' => 'Egypt',
        ]);
        $alreadyHasAddress = $this->makeCustomer([
            'default_address' => '13 Already',
        ]);
        CustomerAddress::create([
            'customer_id' => $alreadyHasAddress->id,
            'address' => 'pre-existing', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true,
        ]);
        // `customers.default_address` is NOT NULL on the schema; the
        // "no legacy address" case is the empty string. The backfill
        // filter uses `!= ''` to skip these rows.
        $noLegacyAddress = $this->makeCustomer(['default_address' => '']);

        // First run — only the eligible customer should land a row.
        $this->artisan('customers:backfill-addresses')->assertExitCode(0);

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $eligible->id,
            'address' => '7 Backfill Lane',
            'is_default' => true,
        ]);
        // alreadyHasAddress still has only its one pre-existing row.
        $this->assertSame(1, CustomerAddress::where('customer_id', $alreadyHasAddress->id)->count());
        // noLegacyAddress still has zero.
        $this->assertSame(0, CustomerAddress::where('customer_id', $noLegacyAddress->id)->count());

        // Re-run — no new rows.
        $totalBefore = CustomerAddress::count();
        $this->artisan('customers:backfill-addresses')
            ->expectsOutputToContain('No customers to backfill')
            ->assertExitCode(0);
        $this->assertSame($totalBefore, CustomerAddress::count());
    }

    public function test_backfill_command_supports_dry_run(): void
    {
        $c = $this->makeCustomer(['default_address' => 'Dry-run Lane']);
        $before = CustomerAddress::count();

        $this->artisan('customers:backfill-addresses', ['--dry-run' => true])
            ->assertExitCode(0);

        // No rows written.
        $this->assertSame($before, CustomerAddress::count());
        $this->assertSame(0, CustomerAddress::where('customer_id', $c->id)->count());
    }

    public function test_address_added_event_appears_in_timeline(): void
    {
        $customer = $this->makeCustomer();
        $this->actingAs($this->admin)
            ->post(route('customers.addresses.store', $customer->id), [
                'address' => '99 Timeline Way',
                'city' => 'Cairo',
                'country' => 'Egypt',
            ])
            ->assertRedirect();

        $response = $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk();

        $timeline = $response->viewData('page')['props']['timeline'];
        $types = array_column($timeline, 'type');
        $this->assertContains('customer_address_added', $types);
        $evt = collect($timeline)->firstWhere('type', 'customer_address_added');
        $this->assertNotNull($evt);
        // First-address-becomes-default → title reflects that.
        $this->assertSame('Default address added', $evt['title']);
        $this->assertStringContainsString('99 Timeline Way', $evt['subtitle']);
    }

    /* ─── helpers ─── */

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-4B Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'seed addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'C-4B ' . uniqid(),
            'slug' => 'c4b-' . uniqid(),
            'description' => 'C-4B test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'C-4B User',
            'email' => 'c4b+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
