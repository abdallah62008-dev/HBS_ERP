<?php

namespace Tests\Feature\Orders;

use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R17 — Bulk order status transition.
 *
 * Each order in the batch is processed individually through
 * OrderService::changeStatus, so every per-order gate (R-11 DAG, R6
 * approval, shipping checklist, inventory hooks, R1 notifications,
 * audit log) still fires. The bulk endpoint never writes the status
 * directly.
 */
class OrderBulkChangeStatusTest extends TestCase
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
            'sku' => 'R17-BULK-001',
            'name' => 'R17 Bulk Test SKU',
            'description' => 'fixture',
            'cost_price' => 50,
            'selling_price' => 200,
            'marketer_trade_price' => 150,
            'minimum_selling_price' => 50,
            'tax_enabled' => false,
            'tax_rate' => 0,
            'reorder_level' => 5,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::create([
            'name' => 'R17 Bulk Customer',
            'primary_phone' => '01077778888',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Bulk Street',
            'created_by' => $this->admin->id,
        ]);

        app(InventoryService::class)->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 500,
            unitCost: 50,
            notes: 'R17 fixture opening balance',
        );

        $this->actingAs($this->admin);
    }

    public function test_bulk_confirms_multiple_orders_in_one_call(): void
    {
        $orders = collect([1, 2, 3])->map(fn () => $this->placeOrder(200));

        $this->post('/orders/bulk/status', [
            'order_ids' => $orders->pluck('id')->all(),
            'status' => 'Confirmed',
        ])->assertRedirect();

        foreach ($orders as $o) {
            $this->assertSame('Confirmed', $o->fresh()->status);
        }
        $this->assertStringContainsString('3 ok', session('success') ?? '');
    }

    public function test_each_order_passes_through_change_status_side_effects(): void
    {
        // Two orders bulk-confirmed → each writes a status_history row
        // AND emits R1 notifications (3 broadcast rows per confirmed
        // order × 2 orders = 6 in total).
        $o1 = $this->placeOrder(200);
        $o2 = $this->placeOrder(200);

        $this->post('/orders/bulk/status', [
            'order_ids' => [$o1->id, $o2->id],
            'status' => 'Confirmed',
        ])->assertRedirect();

        $this->assertSame(1, OrderStatusHistory::where('order_id', $o1->id)
            ->where('new_status', 'Confirmed')->count());
        $this->assertSame(1, OrderStatusHistory::where('order_id', $o2->id)
            ->where('new_status', 'Confirmed')->count());

        $this->assertSame(6, Notification::where('type', 'Order Status')->count(),
            'R1 must emit one broadcast trio per confirmed order.');
    }

    public function test_bulk_respects_r11_dag_per_order(): void
    {
        // New → Confirmed is legal; Delivered → Confirmed is illegal
        // per R-11. The legal one must confirm, the illegal one must
        // be reported as failed without touching its status.
        $newOrder = $this->placeOrder(200);
        $deliveredOrder = $this->placeOrder(200);
        $deliveredOrder->forceFill(['status' => 'Delivered'])->save();

        $this->post('/orders/bulk/status', [
            'order_ids' => [$newOrder->id, $deliveredOrder->id],
            'status' => 'Confirmed',
        ])->assertRedirect();

        $this->assertSame('Confirmed', $newOrder->fresh()->status);
        $this->assertSame('Delivered', $deliveredOrder->fresh()->status,
            'Illegal DAG transition must NOT flip the status.');

        $flash = session('error') ?? '';
        $this->assertStringContainsString('1 ok', $flash);
        $this->assertStringContainsString('1 failed', $flash);
    }

    public function test_bulk_triggers_r6_approval_per_order_for_high_value(): void
    {
        $small = $this->placeOrder(200);
        // Above R6 default 10000 → triggers the approval gate.
        $highValue = $this->placeOrder(10000, qty: 2);

        $this->post('/orders/bulk/status', [
            'order_ids' => [$small->id, $highValue->id],
            'status' => 'Confirmed',
        ])->assertRedirect();

        $this->assertSame('Confirmed', $small->fresh()->status);
        $this->assertSame('New', $highValue->fresh()->status,
            'High-value order must NOT be confirmed by bulk — R6 intercepts.');

        $req = ApprovalRequest::query()
            ->where('approval_type', 'High-Value Order Confirmation')
            ->where('related_id', $highValue->id)
            ->firstOrFail();
        $this->assertSame('Pending', $req->status);

        $flash = session('error') ?? '';
        $this->assertStringContainsString('1 ok', $flash);
        $this->assertStringContainsString('1 need approval', $flash);
    }

    public function test_bulk_skips_orders_already_at_target_status(): void
    {
        $toConfirm = $this->placeOrder(200);
        $alreadyConfirmed = $this->placeOrder(200);
        $alreadyConfirmed->forceFill(['status' => 'Confirmed'])->save();

        $this->post('/orders/bulk/status', [
            'order_ids' => [$toConfirm->id, $alreadyConfirmed->id],
            'status' => 'Confirmed',
        ])->assertRedirect();

        $this->assertSame('Confirmed', $toConfirm->fresh()->status);

        $flash = session('success') ?? '';
        $this->assertStringContainsString('1 ok', $flash);
        $this->assertStringContainsString('1 already Confirmed', $flash);
    }

    public function test_bulk_to_returned_is_rejected(): void
    {
        $order = $this->placeOrder(200);
        $order->forceFill(['status' => 'Delivered'])->save();

        $this->post('/orders/bulk/status', [
            'order_ids' => [$order->id],
            'status' => 'Returned',
        ])->assertRedirect();

        $this->assertSame('Delivered', $order->fresh()->status,
            'Bulk → Returned must NOT flip any status.');
        $this->assertStringContainsString('not supported', session('error') ?? '');
    }

    public function test_empty_order_ids_is_rejected_by_validation(): void
    {
        $this->from('/orders')
            ->post('/orders/bulk/status', [
                'order_ids' => [],
                'status' => 'Confirmed',
            ])
            ->assertSessionHasErrors('order_ids');
    }

    public function test_oversized_batch_is_rejected_by_validation(): void
    {
        // 201 IDs trips the array max:200 rule before per-element checks.
        $ids = range(1, 201);

        $this->from('/orders')
            ->post('/orders/bulk/status', [
                'order_ids' => $ids,
                'status' => 'Confirmed',
            ])
            ->assertSessionHasErrors('order_ids');
    }

    public function test_user_without_orders_change_status_is_forbidden(): void
    {
        $denied = $this->userWith(['orders.view']); // no orders.change_status
        $order = $this->placeOrder(200);

        $this->actingAs($denied)
            ->post('/orders/bulk/status', [
                'order_ids' => [$order->id],
                'status' => 'Confirmed',
            ])
            ->assertForbidden();

        $this->assertSame('New', $order->fresh()->status);
    }

    /* ───────────────────── helpers ───────────────────── */

    private function placeOrder(int $unitPrice, int $qty = 1): Order
    {
        return $this->orderService->createFromPayload([
            'customer_id' => $this->customer->id,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'source' => 'phpunit',
            'items' => [[
                'product_id' => $this->product->id,
                'product_variant_id' => null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);
    }

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'R17 Bulk Test ' . uniqid(),
            'slug' => 'r17-bulk-test-' . uniqid(),
            'description' => 'Test scope.',
            'is_system' => false,
        ]);
        $role->permissions()->sync(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );

        return User::create([
            'name' => 'R17 Bulk Test User',
            'email' => 'r17-bulk+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
