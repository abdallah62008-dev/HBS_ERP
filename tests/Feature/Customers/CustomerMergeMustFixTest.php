<?php

namespace Tests\Feature\Customers;

use App\Events\CustomerRecordsReassigned;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerMerge;
use App\Models\CustomerNote;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-5B Must-Fix coverage (M1–M6 + feature flag).
 *
 * Companion suite to `CustomerMergeExecutionTest` — the original suite
 * pins the happy-path merge behaviour; this one pins the safety gates
 * the architecture review surfaced:
 *
 *   M1 — write leaks on merged sources blocked
 *   M2 — CustomerRecordsReassigned event dispatched after commit
 *   M3a — feature flag `customer_merge_enabled` enforced
 *   M3b — field-merge policies on target (secondary phone, email,
 *         customer_type, risk_level, legacy notes)
 *   M4 — tombstone stats served from merge-log snapshot
 *   M5 — wrong-direction merge requires explicit ack
 *   M6 — every rejection path writes an audit log row
 */
class CustomerMergeMustFixTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        // Enable the workflow for every test in this suite — the
        // feature-flag-off scenario is exercised by its own test that
        // overrides this in-line.
        SettingsService::set('customer_merge_enabled', true, 'customers', 'boolean');
    }

    /* ──────────────── M1 — write-leaks blocked on merged source ──────────────── */

    public function test_M1_storing_note_on_merged_source_redirects_to_target(): void
    {
        $source = $this->mergedSource($target);

        $this->actingAs($this->admin)
            ->from(route('customers.show', $source->id))
            ->post(route('customers.notes.store', $source->id), ['note' => 'should not land'])
            ->assertRedirect(route('customers.show', $target->id))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('customer_notes', [
            'customer_id' => $source->id,
            'note' => 'should not land',
        ]);
    }

    public function test_M1_storing_address_on_merged_source_redirects_to_target(): void
    {
        $source = $this->mergedSource($target);

        $this->actingAs($this->admin)
            ->post(route('customers.addresses.store', $source->id), [
                'address' => 'leak', 'city' => 'Cairo', 'country' => 'Egypt',
            ])
            ->assertRedirect(route('customers.show', $target->id));

        $this->assertDatabaseMissing('customer_addresses', [
            'customer_id' => $source->id,
            'address' => 'leak',
        ]);
    }

    public function test_M1_updating_address_on_merged_source_redirects(): void
    {
        $source = $this->mergedSource($target);
        $addr = CustomerAddress::create([
            'customer_id' => $target->id, 'address' => 'pre-existing', 'city' => 'C', 'country' => 'EG', 'is_default' => true,
        ]);

        // Operator tries to PUT against the merged source's id even
        // though the address row belongs to target.
        $this->actingAs($this->admin)
            ->put(route('customers.addresses.update', [$source->id, $addr->id]), [
                'address' => 'leak', 'city' => 'C', 'country' => 'EG',
            ])
            ->assertRedirect(route('customers.show', $target->id));

        $this->assertSame('pre-existing', $addr->fresh()->address);
    }

    public function test_M1_set_default_address_on_merged_source_redirects(): void
    {
        $source = $this->mergedSource($target);
        $addr = CustomerAddress::create([
            'customer_id' => $target->id, 'address' => 'a', 'city' => 'C', 'country' => 'EG', 'is_default' => false,
        ]);
        $this->actingAs($this->admin)
            ->patch(route('customers.addresses.default', [$source->id, $addr->id]))
            ->assertRedirect(route('customers.show', $target->id));
        $this->assertFalse((bool) $addr->fresh()->is_default);
    }

    public function test_M1_destroying_address_on_merged_source_redirects(): void
    {
        $source = $this->mergedSource($target);
        $addr = CustomerAddress::create([
            'customer_id' => $target->id, 'address' => 'a', 'city' => 'C', 'country' => 'EG', 'is_default' => false,
        ]);
        $this->actingAs($this->admin)
            ->delete(route('customers.addresses.destroy', [$source->id, $addr->id]))
            ->assertRedirect(route('customers.show', $target->id));
        $this->assertDatabaseHas('customer_addresses', ['id' => $addr->id]);
    }

    public function test_M1_destroying_note_on_merged_source_redirects(): void
    {
        $source = $this->mergedSource($target);
        $note = CustomerNote::create([
            'customer_id' => $target->id, 'note' => 'preserved', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin)
            ->delete(route('customers.notes.destroy', [$source->id, $note->id]))
            ->assertRedirect(route('customers.show', $target->id));
        $this->assertDatabaseHas('customer_notes', ['id' => $note->id]);
    }

    public function test_M1_order_create_with_merged_customer_id_redirects_to_target(): void
    {
        $source = $this->mergedSource($target);

        $this->actingAs($this->admin)
            ->get(route('orders.create', ['customer_id' => $source->id]))
            ->assertRedirect(route('orders.create', ['customer_id' => $target->id]));
    }

    public function test_M1_order_store_with_merged_customer_id_is_rejected(): void
    {
        $source = $this->mergedSource($target);
        $product = $this->makeProduct();

        $payload = [
            'customer_id' => $source->id,
            'customer_address' => 'a', 'city' => 'C', 'country' => 'EG',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ];

        $this->actingAs($this->admin)
            ->from(route('orders.create'))
            ->post(route('orders.store'), $payload)
            ->assertSessionHasErrors(['customer_id']);

        // No order was created.
        $this->assertSame(0, Order::where('customer_id', $source->id)->count());
        $this->assertSame(0, Order::where('customer_id', $target->id)->count());
    }

    public function test_M1_blocked_writes_are_audited(): void
    {
        $source = $this->mergedSource($target);

        $this->actingAs($this->admin)
            ->post(route('customers.notes.store', $source->id), ['note' => 'attempt']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'write_blocked_merged_source',
            'module' => 'customers',
            'record_id' => $source->id,
        ]);
    }

    /* ──────────────── M2 — CustomerRecordsReassigned event ──────────────── */

    public function test_M2_dispatches_customer_records_reassigned_after_merge(): void
    {
        Event::fake([CustomerRecordsReassigned::class]);

        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $order = $this->makeOrder($source);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload())
            ->assertRedirect();

        Event::assertDispatched(CustomerRecordsReassigned::class, function ($evt) use ($source, $target, $order) {
            return $evt->sourceCustomerId === $source->id
                && $evt->targetCustomerId === $target->id
                && in_array($order->id, $evt->orderIds, true)
                && $evt->actorId === $this->admin->id;
        });
    }

    /* ──────────────── M3a — feature flag enforcement ──────────────── */

    public function test_M3a_feature_flag_off_rejects_execute(): void
    {
        SettingsService::set('customer_merge_enabled', false, 'customers', 'boolean');

        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload())
            ->assertSessionHasErrors(['reason']);

        $this->assertSame(0, CustomerMerge::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'merge_rejected',
            'module' => 'customers',
        ]);
    }

    public function test_M3a_preview_ships_feature_enabled_flag(): void
    {
        SettingsService::set('customer_merge_enabled', false, 'customers', 'boolean');
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('feature_enabled', false)
                ->where('can_execute_merge', false)
            );
    }

    /* ──────────────── M3b — field-merge policies ──────────────── */

    public function test_M3b_copies_secondary_phone_when_target_empty(): void
    {
        $source = $this->makeCustomer([
            'secondary_phone' => '01055556666',
            'secondary_country_code' => '+20',
            'secondary_normalized_phone' => '+201055556666',
        ]);
        $target = $this->makeCustomer(['secondary_phone' => null]);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $this->assertSame('01055556666', $target->fresh()->secondary_phone);
        $this->assertSame('+201055556666', $target->fresh()->secondary_normalized_phone);
    }

    public function test_M3b_does_not_overwrite_target_secondary_when_set(): void
    {
        $source = $this->makeCustomer(['secondary_phone' => '01055556666']);
        $target = $this->makeCustomer(['secondary_phone' => '01088887777']);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $this->assertSame('01088887777', $target->fresh()->secondary_phone);
    }

    public function test_M3b_copies_email_when_target_empty(): void
    {
        $source = $this->makeCustomer(['email' => 'src@example.com']);
        $target = $this->makeCustomer(['email' => null]);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $this->assertSame('src@example.com', $target->fresh()->email);
    }

    public function test_M3b_promotes_customer_type_to_more_restrictive(): void
    {
        // Source Blacklist > Target Normal → target becomes Blacklist.
        $source = $this->makeCustomer(['customer_type' => 'Blacklist']);
        $target = $this->makeCustomer(['customer_type' => 'Normal']);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $this->assertSame('Blacklist', $target->fresh()->customer_type);
    }

    public function test_M3b_does_not_demote_target_customer_type(): void
    {
        // Source Normal vs target VIP — target stays VIP.
        $source = $this->makeCustomer(['customer_type' => 'Normal']);
        $target = $this->makeCustomer(['customer_type' => 'VIP']);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $this->assertSame('VIP', $target->fresh()->customer_type);
    }

    public function test_M3b_converts_legacy_notes_to_customer_note_on_target(): void
    {
        $source = $this->makeCustomer(['notes' => 'Delivery preference: afternoon only.']);
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        // A new customer_notes row landed on target containing source's
        // legacy notes text.
        $note = CustomerNote::where('customer_id', $target->id)
            ->where('note', 'like', '%Delivery preference%')
            ->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString("Imported from merged customer #{$source->id}", $note->note);
    }

    public function test_M3b_payload_snapshots_pre_merge_target_profile(): void
    {
        $source = $this->makeCustomer(['secondary_phone' => '01055556666']);
        $target = $this->makeCustomer(['secondary_phone' => null, 'name' => 'Pre-merge Target']);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload());

        $merge = CustomerMerge::firstOrFail();
        $this->assertArrayHasKey('target_profile_pre_merge', $merge->payload);
        $this->assertSame('Pre-merge Target', $merge->payload['target_profile_pre_merge']['name']);
        // The patch we applied should be recorded so rollback can undo it.
        $this->assertArrayHasKey('target_patch_applied', $merge->payload);
        $this->assertArrayHasKey('secondary_phone', $merge->payload['target_patch_applied']);
    }

    /* ──────────────── M4 — tombstone stats from merge log ──────────────── */

    public function test_M4_tombstone_show_displays_merge_log_counts_not_live(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->makeOrder($source);
        $this->makeOrder($source);
        $this->makeOrder($source); // 3 orders pre-merge

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), $this->validMergePayload())
            ->assertRedirect();

        // Live `orders.customer_id` is now target; source has 0 live.
        // But the Show page's `stats.total_orders` MUST be 3 — sourced
        // from the merge log, not the live query.
        $this->actingAs($this->admin)
            ->get(route('customers.show', $source->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('stats.from_merge_log', true)
                ->where('stats.total_orders', 3)
                ->has('stats.merge_id')
            );
    }

    /* ──────────────── M5 — wrong-direction acknowledgement ──────────────── */

    public function test_M5_wrong_direction_merge_without_ack_is_rejected(): void
    {
        // Source has more orders → recommended survivor is source.
        // Merging source→target is the wrong direction.
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->makeOrder($source); // 1 order on source, 0 on target

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), [
                'reason' => 'Merging anyway despite the recommendation.',
                'confirmation' => 'MERGE',
                // wrong_direction_ack deliberately absent
            ])
            ->assertSessionHasErrors(['wrong_direction_ack']);

        $this->assertSame(0, CustomerMerge::count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'merge_rejected',
            'module' => 'customers',
            'record_id' => $source->id,
        ]);
    }

    public function test_M5_wrong_direction_merge_with_ack_succeeds(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->makeOrder($source);

        $this->actingAs($this->admin)
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), [
                'reason' => 'Operator acknowledged the wrong direction.',
                'confirmation' => 'MERGE',
                'wrong_direction_ack' => '1',
            ])
            ->assertRedirect(route('customers.show', $target));

        $this->assertSame(1, CustomerMerge::count());
    }

    public function test_M5_preview_ships_wrong_direction_flag(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->makeOrder($source);

        $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('wrong_direction', true)
                ->where('recommended_target_id', $source->id)
            );
    }

    /* ──────────────── M6 — rejection audit log entries ──────────────── */

    public function test_M6_audits_rejection_for_wrong_confirmation_phrase(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$source->id, $target->id]))
            ->post(route('customers.duplicates.merge', [$source->id, $target->id]), [
                'reason' => 'A valid 10+ character reason.',
                'confirmation' => 'merge', // wrong case
            ])
            ->assertSessionHasErrors(['confirmation']);

        $audit = \App\Models\AuditLog::where('action', 'merge_rejected')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('wrong_confirmation', $audit->new_values_json['reason_code']);
    }

    public function test_M6_audits_rejection_for_already_merged_source(): void
    {
        $merged = $this->mergedSource($target);
        $other = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->from(route('customers.duplicates.preview', [$merged->id, $other->id]))
            ->post(route('customers.duplicates.merge', [$merged->id, $other->id]), [
                'reason' => 'attempted re-merge of an already-merged source',
                'confirmation' => 'MERGE',
                'wrong_direction_ack' => '1',
            ]);

        $audit = \App\Models\AuditLog::where('action', 'merge_rejected')
            ->where('new_values_json', 'like', '%already_merged_source%')
            ->first();
        $this->assertNotNull($audit);
    }

    /* ─── helpers ─── */

    /**
     * Build a source customer that has already been merged into a
     * fresh target — returns the source. The target is set on the
     * provided reference. Used by all M1 tests.
     */
    private function mergedSource(?Customer &$target = null): Customer
    {
        $target = $this->makeCustomer(['name' => 'Surviving Target']);
        $source = $this->makeCustomer(['name' => 'Merged Source']);
        // Mark source as merged WITHOUT executing the full service —
        // we just need the tombstone state for the write-leak tests.
        $source->fill([
            'merged_into_customer_id' => $target->id,
            'merged_at' => now(),
            'merged_by' => $this->admin->id,
        ])->save();
        return $source;
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-5B MF ' . uniqid(),
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
            'order_number' => 'ORD-MF-' . uniqid(),
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

    private function makeProduct(): \App\Models\Product
    {
        return \App\Models\Product::create([
            'sku' => 'MF-' . uniqid(),
            'name' => 'MF Test Product',
            'description' => 'fixture',
            'cost_price' => 50,
            'selling_price' => 100,
            'marketer_trade_price' => 80,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    private function validMergePayload(array $overrides = []): array
    {
        return array_merge([
            'reason' => 'Must-fix test merge payload.',
            'confirmation' => 'MERGE',
            'wrong_direction_ack' => '1',
        ], $overrides);
    }
}
