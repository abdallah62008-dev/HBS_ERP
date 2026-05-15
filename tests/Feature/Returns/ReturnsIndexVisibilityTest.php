<?php

namespace Tests\Feature\Returns;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Return Management UX — list visibility.
 *
 * The /returns index is the operator's queue: by default it should
 * surface only returns that still need action. Resolved statuses
 * (Restocked / Closed) drop out of the default view but stay reachable
 * via the explicit `?status=all` filter or by selecting that specific
 * status. This pins those rules so a stray "show all" default can't
 * sneak back.
 */
class ReturnsIndexVisibilityTest extends TestCase
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
            'name' => 'Returns Visibility Customer',
            'primary_phone' => '01099887766',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Visibility Street',
            'created_by' => $this->admin->id,
        ]);
        $this->reason = ReturnReason::firstOrCreate(
            ['name' => 'Visibility Test Reason'],
            ['status' => 'Active'],
        );
    }

    /* ────────────────────── default-active behaviour ────────────────────── */

    public function test_default_index_excludes_restocked_and_closed(): void
    {
        // Seed one return per status so we can prove which slip through.
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Returns/Index')
            ->has('returns')
        );

        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->values()->all();

        // Active statuses present, resolved absent.
        $this->assertContains('Pending',   $statuses);
        $this->assertContains('Received',  $statuses);
        $this->assertContains('Inspected', $statuses);
        $this->assertContains('Damaged',   $statuses);
        $this->assertNotContains('Restocked', $statuses, 'Restocked is resolved — must NOT appear in the default queue.');
        $this->assertNotContains('Closed',    $statuses, 'Closed is resolved — must NOT appear in the default queue.');
    }

    /* ────────────────────── explicit `?status=all` ────────────────────── */

    public function test_index_with_status_all_includes_restocked_and_closed(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index', ['status' => 'all']));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->values()->all();

        foreach (OrderReturn::STATUSES as $status) {
            $this->assertContains($status, $statuses, "Status `{$status}` must appear under ?status=all.");
        }
    }

    /* ────────────────────── per-status drill-down ────────────────────── */

    public function test_index_with_status_restocked_filters_to_restocked_only(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index', ['status' => 'Restocked']));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->values()->all();

        $this->assertSame(['Restocked'], $statuses, 'Restocked filter must return ONLY restocked returns.');
        $this->assertGreaterThanOrEqual(1, $rows->count());
    }

    public function test_index_with_status_pending_filters_to_pending_only(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index', ['status' => 'Pending']));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->values()->all();

        $this->assertSame(['Pending'], $statuses);
    }

    /* ────────────────────── status_groups prop surface ────────────────────── */

    public function test_index_exposes_status_groups_for_active_and_resolved(): void
    {
        $viewer = $this->userWithSlugs(['returns.view']);
        $this->actingAs($viewer)->get(route('returns.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('status_groups.resolved', ['Restocked', 'Closed'])
                ->where('status_groups.active', ['Pending', 'Received', 'Inspected', 'Damaged'])
            );
    }

    /* ────────────────────── resolved filter ────────────────────── */

    public function test_index_with_status_resolved_includes_only_restocked_and_closed(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index', ['status' => 'resolved']));

        $response->assertOk();
        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->sort()->values()->all();

        $this->assertSame(['Closed', 'Restocked'], $statuses, 'Resolved filter must show ONLY Restocked + Closed.');
        $this->assertSame(2, $rows->count(), 'One row per resolved status from the fixture.');
    }

    public function test_index_with_status_resolved_excludes_active_statuses(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        $response = $this->actingAs($viewer)->get(route('returns.index', ['status' => 'resolved']));

        $rows = collect($response->viewData('page')['props']['returns']['data'] ?? []);
        $statuses = $rows->pluck('return_status')->unique()->values()->all();

        foreach (['Pending', 'Received', 'Inspected', 'Damaged'] as $activeStatus) {
            $this->assertNotContains($activeStatus, $statuses, "Resolved view must NOT include `{$activeStatus}`.");
        }
    }

    /* ────────────────────── counts payload ────────────────────── */

    public function test_index_exposes_active_resolved_all_counts(): void
    {
        $this->seedReturnsAcrossStatuses();

        $viewer = $this->userWithSlugs(['returns.view']);
        // Active (default), Resolved, and All views should all expose
        // the SAME counts payload — they describe the whole dataset, not
        // just the currently-visible rows.
        foreach (['', 'resolved', 'all', 'Pending', 'Restocked'] as $statusParam) {
            $params = $statusParam ? ['status' => $statusParam] : [];
            $this->actingAs($viewer)->get(route('returns.index', $params))
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('counts.active', 4)      // Pending, Received, Inspected, Damaged
                    ->where('counts.resolved', 2)    // Restocked, Closed
                    ->where('counts.all', 6)
                    ->where('counts.by_status.Pending', 1)
                    ->where('counts.by_status.Received', 1)
                    ->where('counts.by_status.Inspected', 1)
                    ->where('counts.by_status.Restocked', 1)
                    ->where('counts.by_status.Damaged', 1)
                    ->where('counts.by_status.Closed', 1)
                );
        }
    }

    public function test_counts_respect_search_query(): void
    {
        // Counts should reflect the active search — otherwise the tab
        // labels disagree with the table.
        $this->seedReturnsAcrossStatuses();

        // Pick one fixture order to search for. The seedReturnsAcrossStatuses()
        // helper names orders with the status in upper-case.
        $orderNumber = Order::orderBy('id')->value('order_number');
        $this->assertNotNull($orderNumber);

        $viewer = $this->userWithSlugs(['returns.view']);
        $this->actingAs($viewer)
            ->get(route('returns.index', ['q' => $orderNumber, 'status' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Exactly one fixture matches — counts should narrow.
                ->where('counts.all', 1)
            );
    }

    /* ────────────────────── view_mode prop ────────────────────── */

    public function test_index_view_mode_reflects_filter(): void
    {
        $this->seedReturnsAcrossStatuses();
        $viewer = $this->userWithSlugs(['returns.view']);

        $cases = [
            ''         => 'active',
            'resolved' => 'resolved',
            'all'      => 'all',
            'Pending'  => 'status:Pending',
            'Closed'   => 'status:Closed',
        ];

        foreach ($cases as $param => $expected) {
            $params = $param === '' ? [] : ['status' => $param];
            $this->actingAs($viewer)->get(route('returns.index', $params))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('view_mode', $expected));
        }
    }

    /* ────────────────────── helpers ────────────────────── */

    private function seedReturnsAcrossStatuses(): void
    {
        // One return per status — each on its own throw-away order so the
        // one-return-per-order rule isn't violated and we don't depend on
        // the full status lifecycle service path.
        foreach (OrderReturn::STATUSES as $status) {
            $order = Order::create([
                'order_number' => 'VIS-' . strtoupper($status) . '-' . uniqid(),
                'fiscal_year_id' => \App\Models\FiscalYear::where('status', 'Open')->latest('start_date')->firstOrFail()->id,
                'customer_id' => $this->customer->id,
                'status' => 'Delivered',
                'collection_status' => 'Not Collected',
                'shipping_status' => 'Not Shipped',
                'customer_name' => $this->customer->name,
                'customer_phone' => $this->customer->primary_phone,
                'customer_address' => $this->customer->default_address,
                'city' => $this->customer->city,
                'country' => $this->customer->country,
                'currency_code' => 'EGP',
                'subtotal' => 100,
                'total_amount' => 100,
                'cod_amount' => 100,
                'created_by' => $this->admin->id,
            ]);

            OrderReturn::create([
                'order_id' => $order->id,
                'customer_id' => $this->customer->id,
                'return_reason_id' => $this->reason->id,
                'return_status' => $status,
                'product_condition' => 'Good',
                'refund_amount' => 0,
                'shipping_loss_amount' => 0,
                'restockable' => $status === 'Restocked',
                'created_by' => $this->admin->id,
            ]);
        }
    }

    private function userWithSlugs(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Returns Visibility ' . uniqid(),
            'slug' => 'returns-visibility-' . uniqid(),
            'description' => 'Visibility test scope.',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );
        return User::create([
            'name' => 'Returns Visibility Test User',
            'email' => 'returns-visibility+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
