<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerNote;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-4A — structured customer notes.
 *
 * Pins:
 *   - Customer Show ships the `customer_notes` prop with the latest 50.
 *   - Store endpoint creates rows with the acting user as `created_by`.
 *   - Store endpoint is gated by `customers.edit`.
 *   - Empty / whitespace-only notes are rejected.
 *   - Destroy endpoint removes the row.
 *   - Destroy endpoint is gated by `customers.delete`.
 *   - Destroy endpoint cannot delete a note belonging to a different
 *     customer (defence-in-depth — URL customer must match the note's
 *     customer_id, otherwise 404).
 *   - Notes ARE scoped to the correct customer in the index payload.
 *   - The C-3 timeline picks up `customer_note_added` events.
 */
class CustomerNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_customer_show_ships_customer_notes_prop(): void
    {
        $customer = $this->makeCustomer();
        CustomerNote::create([
            'customer_id' => $customer->id,
            'note' => 'Prefers afternoon delivery',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->has('customer_notes', 1)
                ->where('customer_notes.0.note', 'Prefers afternoon delivery')
                ->where('customer_notes.0.is_internal', true)
                ->where('customer_notes.0.created_by.id', $this->admin->id)
                ->where('customer_notes.0.created_by.name', $this->admin->name)
                ->where('can_delete_customer', true)
            );
    }

    public function test_store_note_creates_row_with_actor(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.show', $customer->id))
            ->post(route('customers.notes.store', $customer->id), [
                'note' => 'Wants WhatsApp confirmation only',
                'is_internal' => true,
            ])
            ->assertRedirect(route('customers.show', $customer->id))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('customer_notes', [
            'customer_id' => $customer->id,
            'note' => 'Wants WhatsApp confirmation only',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_store_note_requires_customers_edit_permission(): void
    {
        $customer = $this->makeCustomer();
        $viewer = $this->userWith(['customers.view']); // no edit slug

        $this->actingAs($viewer)
            ->post(route('customers.notes.store', $customer->id), [
                'note' => 'Should not be created',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('customer_notes', [
            'note' => 'Should not be created',
        ]);
    }

    public function test_store_note_rejects_empty_note(): void
    {
        $customer = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.show', $customer->id))
            ->post(route('customers.notes.store', $customer->id), [
                'note' => '   ', // whitespace-only is empty after trim;
                                 // validator's `required` allows whitespace
                                 // so we also confirm completely empty.
            ])
            // Whitespace passes Laravel's `required` rule. The expected
            // behaviour is "no row created if note is *effectively*
            // empty" — but the validator level we're targeting is
            // simpler: completely empty strings get rejected. Confirm
            // the explicit empty-string path.
            ->assertSessionMissing('success');

        $this->actingAs($this->admin)
            ->from(route('customers.show', $customer->id))
            ->post(route('customers.notes.store', $customer->id), [
                'note' => '',
            ])
            ->assertSessionHasErrors(['note']);

        $this->assertSame(0, CustomerNote::where('customer_id', $customer->id)->count());
    }

    public function test_destroy_note_deletes_customer_note(): void
    {
        $customer = $this->makeCustomer();
        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'note' => 'Tombstone',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('customers.notes.destroy', [$customer->id, $note->id]))
            ->assertRedirect(route('customers.show', $customer->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('customer_notes', ['id' => $note->id]);
    }

    public function test_destroy_note_requires_customers_delete_permission(): void
    {
        $customer = $this->makeCustomer();
        $note = CustomerNote::create([
            'customer_id' => $customer->id,
            'note' => 'Protected',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);
        // Role with view + edit but NOT delete. Notes-create permission
        // is reused from customers.edit, so this role can store but
        // not destroy.
        $editor = $this->userWith(['customers.view', 'customers.edit']);

        $this->actingAs($editor)
            ->delete(route('customers.notes.destroy', [$customer->id, $note->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('customer_notes', ['id' => $note->id]);
    }

    public function test_destroy_note_cannot_delete_note_from_another_customer(): void
    {
        // URL says customer A but note belongs to customer B. The
        // controller must 404 — otherwise a privileged user could
        // accidentally (or maliciously) tombstone the wrong row.
        $a = $this->makeCustomer(['name' => 'Customer A']);
        $b = $this->makeCustomer(['name' => 'Customer B']);
        $noteOnB = CustomerNote::create([
            'customer_id' => $b->id,
            'note' => 'Belongs to B',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('customers.notes.destroy', [$a->id, $noteOnB->id]))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_notes', ['id' => $noteOnB->id]);
    }

    public function test_notes_are_scoped_to_correct_customer(): void
    {
        $a = $this->makeCustomer(['name' => 'Customer A']);
        $b = $this->makeCustomer(['name' => 'Customer B']);
        CustomerNote::create([
            'customer_id' => $a->id, 'note' => 'A note', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);
        CustomerNote::create([
            'customer_id' => $b->id, 'note' => 'B note', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $a->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('customer_notes', 1)
                ->where('customer_notes.0.note', 'A note')
            );

        $this->actingAs($this->admin)
            ->get(route('customers.show', $b->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('customer_notes', 1)
                ->where('customer_notes.0.note', 'B note')
            );
    }

    public function test_note_added_event_appears_in_timeline(): void
    {
        $customer = $this->makeCustomer();
        CustomerNote::create([
            'customer_id' => $customer->id,
            'note' => 'Customer prefers afternoon delivery',
            'is_internal' => true,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('customers.show', $customer->id))
            ->assertOk();

        $timeline = $response->viewData('page')['props']['timeline'];
        $types = array_column($timeline, 'type');
        $this->assertContains('customer_note_added', $types, 'Timeline did not include the customer_note_added event.');

        $noteEvt = collect($timeline)->firstWhere('type', 'customer_note_added');
        $this->assertNotNull($noteEvt);
        $this->assertSame('Internal note added', $noteEvt['title']);
        // Subtitle is the truncated body preview.
        $this->assertStringContainsString('afternoon delivery', $noteEvt['subtitle']);
    }

    /* ─── helpers ─── */

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-4A Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'C-4A ' . uniqid(),
            'slug' => 'c4a-' . uniqid(),
            'description' => 'C-4A test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'C-4A User',
            'email' => 'c4a+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
