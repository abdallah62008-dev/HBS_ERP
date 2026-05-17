<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders & Products O-2 — backfill artisan command coverage.
 *
 * Verifies the command:
 *  - Is idempotent (re-runs are no-ops once `normalized_phone` is set).
 *  - Honours the `--country` hint.
 *  - Doesn't crash on un-parseable input (leaves row null).
 *  - Skips customers that already have `normalized_phone`.
 *  - Populates `orders.customer_phone_normalized` as a side effect.
 */
class BackfillCustomerPhonesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_backfill_populates_triple_on_pre_o2_customers(): void
    {
        $c = Customer::create([
            'name' => 'Pre O-2 Customer',
            'primary_phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Old Street',
            'created_by' => $this->admin->id,
        ]);
        $this->assertNull($c->normalized_phone);

        $this->artisan('customers:backfill-phones')
            ->assertExitCode(0);

        $c->refresh();
        $this->assertSame('+20', $c->country_code);
        $this->assertSame('01012345678', $c->local_phone);
        $this->assertSame('+201012345678', $c->normalized_phone);
    }

    public function test_backfill_is_idempotent(): void
    {
        $c = Customer::create([
            'name' => 'Idempotent',
            'primary_phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('customers:backfill-phones')->assertExitCode(0);
        $first = $c->fresh()->normalized_phone;

        // Second run: no customers should be processed because
        // `normalized_phone` is already set on every row.
        $this->artisan('customers:backfill-phones')
            ->expectsOutputToContain('No customers to backfill')
            ->assertExitCode(0);

        $this->assertSame($first, $c->fresh()->normalized_phone);
    }

    public function test_backfill_with_country_hint_uses_that_country(): void
    {
        $c = Customer::create([
            'name' => 'Saudi Customer',
            'primary_phone' => '0501234567',
            'city' => 'Riyadh',
            'country' => 'Saudi Arabia',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('customers:backfill-phones', ['--country' => '+966'])
            ->assertExitCode(0);

        $c->refresh();
        $this->assertSame('+966', $c->country_code);
        $this->assertSame('+966501234567', $c->normalized_phone);
    }

    public function test_backfill_dry_run_does_not_write(): void
    {
        $c = Customer::create([
            'name' => 'Dry Run',
            'primary_phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('customers:backfill-phones', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNull($c->fresh()->normalized_phone);
    }

    public function test_backfill_handles_un_parseable_phone_safely(): void
    {
        $c = Customer::create([
            'name' => 'Garbage Phone',
            'primary_phone' => '!!!', // not a number
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);

        $this->artisan('customers:backfill-phones')->assertExitCode(0);

        // Row stays null; command logs the failure but doesn't crash.
        $this->assertNull($c->fresh()->normalized_phone);
    }

    public function test_backfill_writes_order_snapshot_for_orders_without_one(): void
    {
        $c = Customer::create([
            'name' => 'Order Snapshot',
            'primary_phone' => '01012345678',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => 'addr',
            'created_by' => $this->admin->id,
        ]);
        // Create an order directly in the DB skipping OrderService so we
        // simulate a pre-O-2 order without a normalized snapshot.
        $order = Order::create([
            'order_number' => 'ORD-O2-BACKFILL-1',
            'entry_code' => 'TST',
            'fiscal_year_id' => \App\Models\FiscalYear::firstOrFail()->id,
            'customer_id' => $c->id,
            'customer_name' => $c->name,
            'customer_phone' => $c->primary_phone,
            'customer_address' => $c->default_address,
            'city' => $c->city,
            'country' => $c->country,
            'currency_code' => 'EGP',
            'status' => 'New',
            'collection_status' => 'Not Collected',
            'shipping_status' => 'Not Shipped',
            'created_by' => $this->admin->id,
        ]);
        $this->assertNull($order->customer_phone_normalized);

        $this->artisan('customers:backfill-phones')->assertExitCode(0);

        $this->assertSame('+201012345678', $order->fresh()->customer_phone_normalized);
    }
}
