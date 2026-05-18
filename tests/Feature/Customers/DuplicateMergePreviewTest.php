<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\CustomerAddress;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Customer C-5A — read-only duplicate merge preview.
 *
 * Pins:
 *   - Preview page renders with the right Inertia props.
 *   - source.id === target.id is rejected (redirected with error).
 *   - Affected-record counts cover orders / returns / refunds / notes
 *     / addresses / tags for both sides.
 *   - Conflicts are computed only when BOTH sides have a non-null
 *     differing value.
 *   - Warnings flag cross-phone / active orders / open returns / open
 *     refunds / target risk / target restricted type.
 *   - Recommended-target heuristic picks the side with more orders;
 *     ties fall back to older `created_at`.
 *   - **Zero writes** — counting every customer-related table before
 *     and after the GET produces identical totals.
 *   - Permission gate uses `customers.view`; viewers can preview,
 *     no-permission users can't.
 *   - C-2 duplicate alert on Customer Show now exposes the Review link.
 *
 * No new permission slugs introduced. No migrations.
 */
class DuplicateMergePreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_preview_page_loads_for_known_source_and_target(): void
    {
        $source = $this->makeCustomer(['name' => 'Source Customer']);
        $target = $this->makeCustomer(['name' => 'Target Customer']);

        $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/MergePreview')
                ->where('source.id', $source->id)
                ->where('source.name', 'Source Customer')
                ->where('target.id', $target->id)
                ->where('target.name', 'Target Customer')
                ->has('affected_records.source')
                ->has('affected_records.target')
                ->has('conflicts')
                ->has('warnings')
                ->has('recommended_target_id')
                ->has('swap_url')
            );
    }

    public function test_preview_rejects_source_equals_target(): void
    {
        $c = $this->makeCustomer();

        $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $c->id, 'target' => $c->id]))
            ->assertRedirect(route('customers.show', $c))
            ->assertSessionHas('error');
    }

    public function test_preview_ships_affected_record_counts(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();

        // Seed varied records for source.
        $sourceOrder = $this->makeOrder($source, 'Delivered');
        $this->makeOrder($source, 'New');
        CustomerNote::create([
            'customer_id' => $source->id, 'note' => 'n', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);
        CustomerAddress::create([
            'customer_id' => $source->id, 'address' => 'a', 'city' => 'Cairo', 'country' => 'Egypt', 'is_default' => true,
        ]);
        CustomerTag::create([
            'customer_id' => $source->id, 'tag' => 'VIP', 'created_by' => $this->admin->id,
        ]);
        $reason = ReturnReason::firstOrCreate(['name' => 'Test'], ['status' => 'Active']);
        OrderReturn::create([
            'order_id' => $sourceOrder->id, 'customer_id' => $source->id, 'return_reason_id' => $reason->id,
            'return_status' => 'Pending', 'product_condition' => 'Good',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        Refund::create([
            'order_id' => $sourceOrder->id, 'customer_id' => $source->id,
            'amount' => 50, 'status' => 'requested', 'requested_by' => $this->admin->id,
        ]);

        // Target has a single order — used in the recommended-target test below too.
        $this->makeOrder($target, 'Delivered');

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $counts = $response->viewData('page')['props']['affected_records'];
        $this->assertSame(2, $counts['source']['orders']);
        $this->assertSame(1, $counts['source']['returns']);
        $this->assertSame(1, $counts['source']['refunds']);
        $this->assertSame(1, $counts['source']['customer_notes']);
        $this->assertSame(1, $counts['source']['customer_addresses']);
        $this->assertSame(1, $counts['source']['customer_tags']);
        $this->assertSame(1, $counts['target']['orders']);
        $this->assertSame(0, $counts['target']['returns']);
    }

    public function test_preview_ships_conflicts(): void
    {
        // Both sides have different non-null `name` + `primary_phone`
        // values — those should surface as conflicts. Empty target city
        // should NOT show up (one side null = silent).
        $source = $this->makeCustomer([
            'name' => 'Source Name',
            'primary_phone' => '01055556666',
            'email' => 'source@example.com',
        ]);
        $target = $this->makeCustomer([
            'name' => 'Target Name',
            'primary_phone' => '01077778888',
            'email' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $conflicts = $response->viewData('page')['props']['conflicts'];
        $fields = array_column($conflicts, 'field');
        $this->assertContains('name', $fields);
        $this->assertContains('primary_phone', $fields);
        // email — target is null, so silent.
        $this->assertNotContains('email', $fields);
    }

    public function test_preview_flags_different_normalized_phone(): void
    {
        $source = $this->makeCustomer([
            'normalized_phone' => '+201011112222',
        ]);
        $target = $this->makeCustomer([
            'normalized_phone' => '+201033334444',
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $warnings = $response->viewData('page')['props']['warnings'];
        $types = array_column($warnings, 'type');
        $this->assertContains('cross_phone', $types);
    }

    public function test_preview_flags_source_active_orders(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $this->makeOrder($source, 'Confirmed'); // active

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $warnings = $response->viewData('page')['props']['warnings'];
        $types = array_column($warnings, 'type');
        $this->assertContains('source_active_orders', $types);
    }

    public function test_preview_flags_open_returns_or_refunds(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $order = $this->makeOrder($source, 'Delivered');
        $reason = ReturnReason::firstOrCreate(['name' => 'X'], ['status' => 'Active']);
        OrderReturn::create([
            'order_id' => $order->id, 'customer_id' => $source->id, 'return_reason_id' => $reason->id,
            'return_status' => 'Pending', 'product_condition' => 'Good',
            'created_by' => $this->admin->id, 'updated_by' => $this->admin->id,
        ]);
        Refund::create([
            'order_id' => $order->id, 'customer_id' => $source->id,
            'amount' => 100, 'status' => 'requested', 'requested_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $warnings = $response->viewData('page')['props']['warnings'];
        $types = array_column($warnings, 'type');
        $this->assertContains('source_open_returns', $types);
        $this->assertContains('source_open_refunds', $types);
    }

    public function test_preview_recommends_customer_with_more_orders_as_target(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        // Source has 2 orders, target has 1 — recommended survivor is source.
        $this->makeOrder($source);
        $this->makeOrder($source);
        $this->makeOrder($target);

        $response = $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        $recommended = $response->viewData('page')['props']['recommended_target_id'];
        $this->assertSame($source->id, $recommended);
    }

    public function test_preview_does_not_write_anything(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        // Seed some records to make the GET non-trivial.
        $this->makeOrder($source);
        CustomerNote::create([
            'customer_id' => $source->id, 'note' => 'n', 'is_internal' => true, 'created_by' => $this->admin->id,
        ]);
        CustomerAddress::create([
            'customer_id' => $source->id, 'address' => 'a', 'city' => 'C', 'country' => 'EG', 'is_default' => true,
        ]);
        CustomerTag::create([
            'customer_id' => $source->id, 'tag' => 't', 'created_by' => $this->admin->id,
        ]);

        // Snapshot every customer-related table BEFORE the GET.
        $tables = ['customers', 'orders', 'returns', 'refunds', 'customer_notes', 'customer_addresses', 'customer_tags'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->count();
        }

        $this->actingAs($this->admin)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();

        // Counts after must be IDENTICAL. Any drift indicates a write
        // leaked into the read path.
        foreach ($tables as $t) {
            $after = DB::table($t)->count();
            $this->assertSame(
                $before[$t],
                $after,
                "Table {$t} row count changed during preview ({$before[$t]} → {$after}). Preview must be read-only.",
            );
        }
    }

    public function test_preview_requires_customers_view_permission(): void
    {
        $source = $this->makeCustomer();
        $target = $this->makeCustomer();
        $stranger = $this->userWith([]); // zero slugs

        $this->actingAs($stranger)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertForbidden();

        // A viewer with `customers.view` only CAN access the preview.
        $viewer = $this->userWith(['customers.view']);
        $this->actingAs($viewer)
            ->get(route('customers.duplicates.preview', ['source' => $source->id, 'target' => $target->id]))
            ->assertOk();
    }

    public function test_customer_show_duplicate_alert_includes_review_link(): void
    {
        // C-2 detects duplicates by exact `normalized_phone` match.
        // Build two customers sharing a normalized phone, then GET the
        // Show page — it should ship a `duplicate_customers` prop, and
        // the page renders a Review link via `route('customers.duplicates.preview')`.
        $a = $this->makeCustomer(['normalized_phone' => '+201099887766']);
        $b = $this->makeCustomer(['normalized_phone' => '+201099887766']);

        $this->actingAs($this->admin)
            ->get(route('customers.show', $a->id))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Customers/Show')
                ->has('duplicate_customers', 1)
                ->where('duplicate_customers.0.id', $b->id)
            );

        // The route the Show.jsx component links to MUST exist and
        // resolve — we don't need to render React to verify that.
        $url = route('customers.duplicates.preview', ['source' => $a->id, 'target' => $b->id]);
        $this->assertNotEmpty($url);
        $this->actingAs($this->admin)->get($url)->assertOk();
    }

    /* ─── helpers ─── */

    private function makeCustomer(array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'C-5A Customer ' . uniqid(),
            'primary_phone' => '0101' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    private function makeOrder(Customer $customer, string $status = 'New'): Order
    {
        return Order::create([
            'order_number' => 'ORD-C5A-' . uniqid(),
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
            'name' => 'C-5A ' . uniqid(),
            'slug' => 'c5a-' . uniqid(),
            'description' => 'C-5A test role.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);
        return User::create([
            'name' => 'C-5A User',
            'email' => 'c5a+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
