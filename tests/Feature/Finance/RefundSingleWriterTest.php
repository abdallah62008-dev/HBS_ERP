<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * R4 — RefundService is the single writer for refund rows.
 *
 * Before R4, RefundsController::store() called Refund::create() and
 * ::update() called $refund->fill()->save() directly — the over-refund
 * guards ran from the service but the row write bypassed it. R4 moves
 * both writes into RefundService::createRequested() / updateRequested()
 * so every requested-refund mutation flows through one service.
 *
 * These tests pin both halves:
 *   - the two new service methods (write + audit + guards + editability)
 *   - the HTTP store / update routes delegate to those methods
 *
 * The existing RefundTest.php remains the end-to-end regression net for
 * controller behaviour.
 */
class RefundSingleWriterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->customer = Customer::create([
            'name' => 'R4 Refund Customer',
            'primary_phone' => '01077770000',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Single Writer Street',
            'created_by' => $this->admin->id,
        ]);
        $this->actingAs($this->admin);
    }

    /* ───────────────── createRequested ───────────────── */

    public function test_create_requested_writes_a_requested_refund_and_audit_row(): void
    {
        $refund = app(RefundService::class)->createRequested([
            'amount' => 120,
            'reason' => 'service-created',
        ], $this->admin);

        $this->assertSame('requested', $refund->status);
        $this->assertSame('120.00', (string) $refund->amount);
        $this->assertSame($this->admin->id, $refund->requested_by);
        $this->assertDatabaseHas('refunds', ['id' => $refund->id, 'status' => 'requested']);

        $this->assertSame(1, AuditLog::where('module', 'finance.refund')
            ->where('action', 'refund_created')
            ->where('record_id', $refund->id)->count());
    }

    public function test_create_requested_enforces_the_over_refund_guard(): void
    {
        $collection = $this->makeCollection(80);

        app(RefundService::class)->createRequested([
            'amount' => 60,
            'collection_id' => $collection->id,
        ], $this->admin);

        // 60 + 30 = 90 > 80 → blocked, nothing written.
        try {
            app(RefundService::class)->createRequested([
                'amount' => 30,
                'collection_id' => $collection->id,
            ], $this->admin);
            $this->fail('Expected InvalidArgumentException from the over-refund guard.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(1, Refund::where('collection_id', $collection->id)->count());
        }
    }

    /* ───────────────── updateRequested ───────────────── */

    public function test_update_requested_updates_an_editable_refund_and_audits(): void
    {
        $refund = app(RefundService::class)->createRequested([
            'amount' => 100,
            'reason' => 'before',
        ], $this->admin);

        $updated = app(RefundService::class)->updateRequested($refund, [
            'amount' => 175,
            'reason' => 'after',
        ]);

        $this->assertSame('175.00', (string) $updated->amount);
        $this->assertSame('after', $updated->reason);
        $this->assertSame(1, AuditLog::where('module', 'finance.refund')
            ->where('action', 'refund_updated')
            ->where('record_id', $refund->id)->count());
    }

    public function test_update_requested_rejects_a_non_editable_refund(): void
    {
        $refund = app(RefundService::class)->createRequested(['amount' => 100], $this->admin);
        app(RefundService::class)->approve($refund);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be edited');

        app(RefundService::class)->updateRequested($refund->fresh(), ['amount' => 9999]);
    }

    public function test_update_requested_enforces_the_over_refund_guard(): void
    {
        $collection = $this->makeCollection(100);
        app(RefundService::class)->createRequested([
            'amount' => 70,
            'collection_id' => $collection->id,
        ], $this->admin);
        $second = app(RefundService::class)->createRequested([
            'amount' => 20,
            'collection_id' => $collection->id,
        ], $this->admin);

        // Raising the second refund to 40 → 70 + 40 = 110 > 100.
        $this->expectException(InvalidArgumentException::class);

        app(RefundService::class)->updateRequested($second, [
            'amount' => 40,
            'collection_id' => $collection->id,
        ]);
    }

    /* ───────────────── controller delegation ───────────────── */

    public function test_store_route_delegates_to_create_requested(): void
    {
        // Mockery verifies the expectation at teardown — if the controller
        // bypassed the service (wrote the row itself), this test fails.
        $mock = $this->mock(RefundService::class);
        $mock->shouldReceive('createRequested')->once()->andReturn(new Refund());

        $this->post('/refunds', ['amount' => 100, 'reason' => 'delegation'])
            ->assertRedirect('/refunds');
    }

    public function test_update_route_delegates_to_update_requested(): void
    {
        // Real refund created directly so the fixture does not depend on
        // the service that the test is about to mock.
        $refund = Refund::create([
            'amount' => 100,
            'reason' => 'before delegation',
            'status' => 'requested',
            'requested_by' => $this->admin->id,
        ]);

        $mock = $this->mock(RefundService::class);
        $mock->shouldReceive('updateRequested')->once()->andReturn($refund);

        $this->put('/refunds/' . $refund->id, ['amount' => 150, 'reason' => 'delegated'])
            ->assertRedirect('/refunds');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function makeCollection(float $amountCollected): Collection
    {
        static $counter = 0;
        $counter++;

        $order = Order::create([
            'order_number' => 'R4-SW-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => 'Delivered',
            'collection_status' => 'Collected',
            'shipping_status' => 'Delivered',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => $amountCollected,
            'created_by' => $this->admin->id,
        ]);

        return Collection::create([
            'order_id' => $order->id,
            'amount_due' => $amountCollected,
            'amount_collected' => $amountCollected,
            'collection_status' => 'Collected',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }
}
