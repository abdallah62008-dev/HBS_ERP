<?php

namespace Tests\Feature\Orders;

use App\Exceptions\IllegalOrderTransitionException;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * R11 — Order status Transition DAG.
 *
 * Pins two layers:
 *   1. The DAG itself — Order::ALLOWED_TRANSITIONS + Order::isLegalTransition.
 *   2. The enforcement gate inside OrderService::changeStatus — an illegal
 *      jump must throw IllegalOrderTransitionException BEFORE any
 *      side-effect (history row, audit log, inventory movement) runs.
 *
 * The DAG edges are (inferred): the source Order_P0_Doc enumerates the 13
 * statuses but not the legal edges between them. The matrix under test was
 * reconciled against the live ShippingController and the existing Returns
 * test fixtures so it rejects no transition the system already performs.
 */
class OrderTransitionDagTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;
    private Customer $customer;
    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->orderService = app(OrderService::class);

        $warehouse = Warehouse::firstOrFail();

        $this->product = Product::create([
            'sku' => 'R11-DAG-001',
            'name' => 'R11 DAG Test SKU',
            'description' => 'fixture',
            'cost_price' => 50,
            'selling_price' => 200,
            'marketer_trade_price' => 150,
            'minimum_selling_price' => 100,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'R11 DAG Test Customer',
            'primary_phone' => '01099990000',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 DAG Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance so any reservation side-effect never fails on
        // insufficient stock (defensive — most tests don't reach it).
        app(InventoryService::class)->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'R11 DAG test fixture opening balance',
        );

        $this->actingAs($this->admin);
    }

    /* ───────────────────── 1. DAG structure ───────────────────── */

    public function test_allowed_transitions_covers_every_status(): void
    {
        foreach (Order::STATUSES as $status) {
            $this->assertArrayHasKey(
                $status,
                Order::ALLOWED_TRANSITIONS,
                "Status '{$status}' is missing from ALLOWED_TRANSITIONS."
            );
        }
    }

    public function test_every_transition_target_is_a_known_status(): void
    {
        foreach (Order::ALLOWED_TRANSITIONS as $from => $targets) {
            foreach ($targets as $to) {
                $this->assertContains(
                    $to,
                    Order::STATUSES,
                    "Edge {$from} -> {$to} points to an unknown status."
                );
            }
        }
    }

    public function test_terminal_statuses_have_no_outgoing_edges(): void
    {
        $this->assertSame([], Order::ALLOWED_TRANSITIONS['Returned']);
        $this->assertSame([], Order::ALLOWED_TRANSITIONS['Cancelled']);
    }

    public function test_is_legal_transition_agrees_with_the_constant(): void
    {
        foreach (Order::STATUSES as $from) {
            foreach (Order::STATUSES as $to) {
                $declared = in_array($to, Order::ALLOWED_TRANSITIONS[$from] ?? [], true);
                $this->assertSame(
                    $declared,
                    Order::isLegalTransition($from, $to),
                    "isLegalTransition('{$from}','{$to}') disagrees with ALLOWED_TRANSITIONS."
                );
            }
        }
    }

    /**
     * @dataProvider legalEdgeProvider
     */
    public function test_known_legal_edges_are_accepted(string $from, string $to): void
    {
        $this->assertTrue(
            Order::isLegalTransition($from, $to),
            "Expected {$from} -> {$to} to be legal."
        );
    }

    /**
     * @dataProvider illegalEdgeProvider
     */
    public function test_known_illegal_edges_are_rejected(string $from, string $to): void
    {
        $this->assertFalse(
            Order::isLegalTransition($from, $to),
            "Expected {$from} -> {$to} to be illegal."
        );
    }

    public static function legalEdgeProvider(): array
    {
        return [
            'New -> Confirmed'                => ['New', 'Confirmed'],
            'New -> Pending Confirmation'     => ['New', 'Pending Confirmation'],
            'New -> On Hold'                  => ['New', 'On Hold'],
            'Confirmed -> Shipped (fast-fwd)' => ['Confirmed', 'Shipped'],
            'Confirmed -> Ready to Ship'      => ['Confirmed', 'Ready to Ship'],
            'Confirmed -> Packed'             => ['Confirmed', 'Packed'],
            'Confirmed -> Returned (pre-ship)' => ['Confirmed', 'Returned'],
            'Packed -> Returned (pre-ship)'   => ['Packed', 'Returned'],
            'Packed -> Shipped'               => ['Packed', 'Shipped'],
            'Shipped -> Delivered'            => ['Shipped', 'Delivered'],
            'Shipped -> Returned'             => ['Shipped', 'Returned'],
            'Delivered -> Returned'           => ['Delivered', 'Returned'],
            'On Hold -> Confirmed (resume)'   => ['On Hold', 'Confirmed'],
        ];
    }

    public static function illegalEdgeProvider(): array
    {
        return [
            'New -> Delivered (skip shipment)'   => ['New', 'Delivered'],
            'New -> Shipped (skip confirm)'      => ['New', 'Shipped'],
            'Confirmed -> Delivered (skip ship)' => ['Confirmed', 'Delivered'],
            'Packed -> Delivered (skip ship)'    => ['Packed', 'Delivered'],
            'Shipped -> New (un-ship)'           => ['Shipped', 'New'],
            'Shipped -> Cancelled (post-ship)'   => ['Shipped', 'Cancelled'],
            'Delivered -> Confirmed (rewind)'    => ['Delivered', 'Confirmed'],
            'Returned -> Delivered (un-return)'  => ['Returned', 'Delivered'],
            'Cancelled -> Confirmed (resurrect)' => ['Cancelled', 'Confirmed'],
        ];
    }

    /* ─────────────── 2. changeStatus gate enforcement ─────────────── */

    public function test_change_status_rejects_illegal_jump_with_typed_exception(): void
    {
        $order = $this->orderAt('New');

        try {
            $this->orderService->changeStatus($order, 'Delivered');
            $this->fail('Expected IllegalOrderTransitionException for New -> Delivered.');
        } catch (IllegalOrderTransitionException $e) {
            $this->assertSame('New', $e->fromStatus);
            $this->assertSame('Delivered', $e->toStatus);
            $this->assertSame($order->id, $e->orderId);
            $this->assertNotContains('Delivered', $e->allowedTargets());
        }
    }

    public function test_illegal_transition_writes_no_side_effects(): void
    {
        $order = $this->orderAt('Delivered');
        $historyBefore = OrderStatusHistory::where('order_id', $order->id)->count();

        try {
            $this->orderService->changeStatus($order, 'Confirmed');
            $this->fail('Expected IllegalOrderTransitionException for Delivered -> Confirmed.');
        } catch (IllegalOrderTransitionException $e) {
            // expected
        }

        $this->assertSame('Delivered', $order->fresh()->status, 'Status must be untouched.');
        $this->assertSame(
            $historyBefore,
            OrderStatusHistory::where('order_id', $order->id)->count(),
            'A rejected transition must not write an order_status_history row.'
        );
    }

    public function test_change_status_allows_a_legal_transition(): void
    {
        // New -> On Hold is legal and has no inventory side-effect, so it
        // exercises the gate's happy path without warehouse coupling.
        $order = $this->orderAt('New');

        $updated = $this->orderService->changeStatus($order, 'On Hold');

        $this->assertSame('On Hold', $updated->status);
    }

    public function test_returned_is_terminal_in_change_status(): void
    {
        $order = $this->orderAt('Returned');

        foreach (['Confirmed', 'Shipped', 'Delivered', 'New', 'Cancelled'] as $target) {
            try {
                $this->orderService->changeStatus($order->fresh(), $target);
                $this->fail("Expected exception for Returned -> {$target}.");
            } catch (IllegalOrderTransitionException $e) {
                $this->assertSame('Returned', $e->fromStatus);
            }
        }
    }

    public function test_cancelled_is_terminal_in_change_status(): void
    {
        $order = $this->orderAt('Cancelled');

        foreach (['Confirmed', 'Shipped', 'New', 'On Hold'] as $target) {
            try {
                $this->orderService->changeStatus($order->fresh(), $target);
                $this->fail("Expected exception for Cancelled -> {$target}.");
            } catch (IllegalOrderTransitionException $e) {
                $this->assertSame('Cancelled', $e->fromStatus);
            }
        }
    }

    public function test_same_status_short_circuits_without_throwing(): void
    {
        $order = $this->orderAt('Confirmed');

        $result = $this->orderService->changeStatus($order, 'Confirmed');

        $this->assertSame('Confirmed', $result->status);
    }

    public function test_unknown_status_still_throws_plain_runtime_exception(): void
    {
        // Pre-R11 behaviour for a completely unknown value is preserved:
        // the unknown-status guard runs before the DAG gate.
        $order = $this->orderAt('New');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown order status: Imaginary');

        $this->orderService->changeStatus($order, 'Imaginary');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Build a real order, then force it to the requested start status.
     * createFromPayload always starts an order at 'New'; the forceFill
     * lets a test probe transitions from any of the 13 statuses without
     * walking the whole DAG to reach the start point.
     */
    private function orderAt(string $status): Order
    {
        $order = $this->orderService->createFromPayload([
            'customer_id' => $this->customer->id,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'source' => 'phpunit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'quantity' => 1,
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);

        if ($status !== 'New') {
            $order->forceFill(['status' => $status])->save();
        }

        return $order->fresh();
    }
}
