<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerMerge;
use App\Models\CustomerNote;
use App\Models\CustomerTag;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-5B — duplicate merge execution.
 *
 * Pins the critical safety contracts:
 *   - orders / returns / refunds / notes / addresses / tags get
 *     reassigned to target.
 *   - orders.customer_name / customer_phone / customer_address etc.
 *     SNAPSHOT columns are NEVER touched (historical integrity).
 *   - source is marked merged via `merged_into_customer_id` /
 *     `merged_at` / `merged_by` — NOT soft-deleted.
 *   - `customer_merges` log row written with payload.
 *   - Audit logs written on both sides.
 *   - Already-merged source rejected.
 *   - Empty / short reason rejected.
 *   - Wrong confirmation phrase rejected.
 *   - Permission gate uses the new `customers.merge` slug.
 *   - Cross-phone merge requires super-admin.
 *   - C-2 duplicate detector hides already-merged rows.
 *   - C-3 timeline emits the merge events on both sides.
 */
class CustomerMergeExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        // C-5B Must-Fix M3a: the merge workflow ships disabled. Enable
        // it for the execution-path tests so the existing assertions
        // still describe a successful merge.
        SettingsService::set('customer_merge_enabled', true, 'customers', 'boolean');
    }

    public function test_merge_reassigns_orders_returns_refunds_to_target(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $order = $this->makeOrder($source, 'Delivered');
        $reason = ReturnReason::firstOrCreate(['name' => 'Test'], ['status' => 'Active']);
        $ret = OrderReturn::create([
            'order_id' => $order->id, 'customer_id' => $source->id, 'return_reason_id' => $reason->id,
            'return_status' => 'Pending', 'product_condition' => 'Good',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        $refund = Refund::create([
            'order_id' => $order->id, 'customer_id' => $source->id,
            'amount' => 100, 'status' => 'requested', 'requested_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect(route('customers.show', $target));

        $this->assertSame($target->id, (int) $order->fresh()->customer_id);
        $this->assertSame($target->id, (int) $ret->fresh()->customer_id);
        $this->assertSame($target->id, (int) $refund->fresh()->customer_id);
    }

    public function test_merge_reassigns_notes_and_addresses_and_unions_tags(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        // Notes
        $note = CustomerNote::create([
            'customer_id' => $source->id, 'note' => 'src note', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);
        // Addresses — source has a default, target does not
        $srcAddr = CustomerAddress::create([
            'customer_id' => $source->id, 'address' => 's', 'city' => 'C', 'country' => 'EG', 'is_default' => true,
        ]);
        // Tags — overlap with target's
        CustomerTag::create([
            'customer_id' => $source->id, 'tag' => 'VIP', 'created_by' => $this->admin->id,
        ]);
        CustomerTag::create([
            'customer_id' => $source->id, 'tag' => 'Repeat', 'created_by' => $this->admin->id,
        ]);
        CustomerTag::create([
            'customer_id' => $target->id, 'tag' => 'VIP', 'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $this->assertSame($target->id, (int) $note->fresh()->customer_id);
        $this->assertSame($target->id, (int) $srcAddr->fresh()->customer_id);
        // Tag union: target should now have VIP + Repeat (de-duped).
        $tagsOnTarget = CustomerTag::where('customer_id', $target->id)->pluck('tag')->sort()->values()->all();
        $this->assertSame(['Repeat', 'VIP'], $tagsOnTarget);
        $this->assertSame(0, CustomerTag::where('customer_id', $source->id)->count());
    }

    public function test_merge_target_default_address_is_preserved(): void
    {
        // Target has its own default; source has its own default. After
        // merge, target's default survives and source's default flag is
        // cleared so the invariant holds.
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        CustomerAddress::create([
            'customer_id' => $source->id, 'address' => 'src default', 'city' => 'C', 'country' => 'EG', 'is_default' => true,
        ]);
        $targetDefault = CustomerAddress::create([
            'customer_id' => $target->id, 'address' => 'tgt default', 'city' => 'C', 'country' => 'EG', 'is_default' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(
            1,
            CustomerAddress::where('customer_id', $target->id)->where('is_default', true)->count(),
            'Single-default invariant must hold on target after merge.',
        );
        $this->assertTrue((bool) $targetDefault->fresh()->is_default, "Target's original default should survive.");
    }

    public function test_merge_does_not_touch_order_snapshot_columns(): void
    {
        // Order's snapshot columns reflect the customer state at order
        // creation. Reassigning customer_id must NOT rewrite them.
        $source = $this->makeCustomer(['name' => 'Source Name', 'primary_phone' => '01055556666']);
        $target = $this->makeCustomer(['name' => 'Target Name', 'primary_phone' => '01077778888']);
        $order = $this->makeOrder($source, 'Delivered'); // snapshot is source's values

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $order->refresh();
        $this->assertSame($target->id, (int) $order->customer_id); // FK moved
        $this->assertSame('Source Name', $order->customer_name); // snapshot preserved
        $this->assertSame('01055556666', $order->customer_phone); // snapshot preserved
    }

    public function test_merge_marks_source_as_merged_tombstone(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $source->refresh();
        $this->assertSame($target->id, (int) $source->merged_into_customer_id);
        $this->assertNotNull($source->merged_at);
        $this->assertSame($this->admin->id, (int) $source->merged_by);
        $this->assertNull($source->deleted_at, 'Source must NOT be soft-deleted by C-5B.');
    }

    public function test_merge_creates_customer_merges_row_with_payload(): void
    {
        $source = $this->makeCustomer(['name' => 'Source']);
        $target = $this->makeCustomer();
        $order = $this->makeOrder($source);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $merge = CustomerMerge::firstOrFail();
        $this->assertSame($source->id, (int) $merge->source_customer_id);
        $this->assertSame($target->id, (int) $merge->target_customer_id);
        $this->assertSame($this->admin->id, (int) $merge->merged_by);
        $this->assertSame(1, (int) $merge->affected_orders_count);
        $this->assertIsArray($merge->payload);
        $this->assertSame('Source', $merge->payload['source_profile']['name'] ?? null);
        $this->assertContains($order->id, $merge->payload['affected_ids']['orders']);
    }

    public function test_merge_writes_audit_logs_on_both_sides(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'merged_out',
            'module' => 'customers',
            'record_id' => $source->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'merged_in',
            'module' => 'customers',
            'record_id' => $target->id,
        ]);
    }

    public function test_merge_rejects_already_merged_source(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $finalTarget = $this->makeCustomer();
        $source->update(['merged_into_customer_id' => $target->id, 'merged_at' => now()]);

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $finalTarget->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $finalTarget->id]), $this->validPayload())
            ->assertSessionHasErrors();

        // No new merge row.
        $this->assertSame(0, CustomerMerge::count());
    }

    public function test_merge_rejects_short_reason(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), [
                'reason' => 'short',
                'confirmation' => 'MERGE',
            ])
            ->assertSessionHasErrors(['reason']);

        $this->assertSame(0, CustomerMerge::count());
    }

    public function test_merge_rejects_wrong_confirmation_phrase(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), [
                'reason' => 'A valid 10+ character reason',
                'confirmation' => 'merge', // wrong case
            ])
            ->assertSessionHasErrors(['confirmation']);

        $this->assertSame(0, CustomerMerge::count());
    }

    public function test_merge_requires_customers_merge_permission(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        // User with customers.view + edit + delete but NOT customers.merge.
        $unprivileged = $this->userWith(['customers.view', 'customers.edit', 'customers.delete']);

        $this->actingAs($unprivileged)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertForbidden();

        $this->assertSame(0, CustomerMerge::count());
    }

    public function test_cross_phone_merge_requires_super_admin(): void
    {
        $source = $this->makeCustomer(['normalized_phone' => '+201011112222']);
        $target = $this->makeCustomer(['normalized_phone' => '+201033334444']);
        // Build a role that has customers.merge but is NOT super-admin.
        $editor = $this->userWith(['customers.view', 'customers.edit', 'customers.delete', 'customers.merge']);

        $this->actingAs($editor)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertSessionHasErrors();

        // Admin (= super-admin in the seeded fixture) CAN execute.
        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $this->assertSame(1, CustomerMerge::count());
    }

    public function test_duplicate_detector_excludes_already_merged_rows(): void
    {
        // Two customers share normalized phone; we merge one into the
        // other, then visit a THIRD customer's Show that also shares
        // the phone. The merged-out source should NOT surface as a
        // duplicate of the third.
        $a = $this->makeCustomer(['normalized_phone' => '+201099887766']);
        $b = $this->makeCustomer(['normalized_phone' => '+201099887766']);
        $c = $this->makeCustomer(['normalized_phone' => '+201099887766']);

        // Merge A into B.
        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$a->id, $b->id]), $this->validPayload())
            ->assertRedirect();

        // C's duplicate alert should now show only B (A is merged away).
        $this->actingAs($this->admin)
            ->get(route('customers.show', $c->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('duplicate_customers', 1)
                ->where('duplicate_customers.0.id', $b->id)
            );
    }

    public function test_target_timeline_emits_customer_merged_in_event(): void
    {
        $source = $this->makeCustomer(['name' => 'Old Account']);
        $target = $this->makeCustomer();
        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $response = $this->actingAs($this->admin)
            ->get(route('customers.show', $target->id))
            ->assertOk();

        $timeline = $response->viewData('page')['props']['timeline'];
        $types = array_column($timeline, 'type');
        $this->assertContains('customer_merged_in', $types);
    }

    public function test_source_show_renders_tombstone_payload(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validPayload())
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get(route('customers.show', $source->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('merge_tombstone.merged_into_customer_id', $target->id)
                ->where('merge_tombstone.merged_by_name', $this->admin->name)
            );
    }

    public function test_merge_preview_blocks_cross_phone_for_non_super_admin(): void
    {
        $source = $this->makeCustomer(['normalized_phone' => '+201011112222']);
        $target = $this->makeCustomer(['normalized_phone' => '+201033334444']);
        $editor = $this->userWith(['customers.view', 'customers.merge']);

        $this->actingAs($editor)
            ->get(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('can_merge', true)
                ->where('can_execute_merge', false)
                ->where('merge_blocked_reason', 'Cross-phone merge requires super-admin.')
            );
    }

    /* ─── helpers ─── */

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'reason' => 'Confirmed same customer — merging duplicates.',
            'confirmation' => 'MERGE',
            // C-5B Must-Fix M5: most existing tests deliberately move
            // data source→target where the recommendation heuristic
            // would prefer the other direction (source often has more
            // orders). Pre-ack the wrong-direction guard so these
            // tests still exercise the merge-execution code path.
            // Dedicated M5 tests in CustomerMergeMustFixTest assert
            // the guard fires when the ack is absent.
            'wrong_direction_ack' => '1',
        ], $overrides);
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-5B Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'normalized_phone' => '+2010' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(Customer $customer, string $status = 'New'): Order
    {
        return Order::create([
            'order_number' => 'ORD-C5B-' . uniqid(),
            'entry_code' => 'TST',
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'customer_phone' => $customer->primary_phone,
            'customer_address' => $customer->default_address,
            'city' => $customer->city,
            'country' => $customer->country,
            'currency_code' => 'EGP',
            'total_amount' => 100,
            'status' => $status,
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'C-5B ' . uniqid(),
            'slug' => 'c5b-' . uniqid(),
            'description' => 'C-5B test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'C-5B User',
            'email' => 'c5b+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
