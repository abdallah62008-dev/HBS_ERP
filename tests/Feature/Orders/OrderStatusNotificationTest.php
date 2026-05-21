<?php

namespace Tests\Feature\Orders;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R1 — in-app operator notifications on key order-status transitions.
 *
 * When an order moves to Confirmed / Shipped / Delivered / Returned,
 * OrderService::changeStatus writes `notifications` rows (type
 * 'Order Status') broadcast to the order-agent / manager / admin roles
 * via the existing in-app notification system. Non-notified statuses
 * (On Hold, Ready to Pack, …) emit nothing.
 */
class OrderStatusNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customer;
    private InventoryService $inventory;
    private OrderService $orderService;

    private const BROADCAST_ROLES = ['order-agent', 'manager', 'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();
        $this->inventory = app(InventoryService::class);
        $this->orderService = app(OrderService::class);
        $this->actingAs($this->admin);

        $this->product = Product::create([
            'sku' => 'R1-NOTIF-001',
            'name' => 'R1 Notification Test SKU',
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
            'name' => 'R1 Notification Customer',
            'primary_phone' => '01033332222',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Notify Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance so the Confirmed reservation never fails on stock.
        $this->inventory->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'R1 test fixture opening balance',
        );
    }

    public function test_confirming_an_order_notifies_the_broadcast_roles(): void
    {
        $order = $this->placeOrder();

        $this->orderService->changeStatus($order, 'Confirmed');

        $notifications = Notification::where('type', 'Order Status')->get();
        $this->assertCount(3, $notifications);

        $expectedRoleIds = Role::whereIn('slug', self::BROADCAST_ROLES)
            ->pluck('id')->sort()->values();
        $this->assertEquals(
            $expectedRoleIds->all(),
            $notifications->pluck('role_id')->sort()->values()->all(),
        );

        // Broadcast rows target a role, never an individual user.
        $this->assertTrue($notifications->every(fn ($n) => $n->user_id === null));
    }

    public function test_notification_carries_order_number_status_and_action_url(): void
    {
        $order = $this->placeOrder();

        $this->orderService->changeStatus($order, 'Confirmed');

        $n = Notification::where('type', 'Order Status')->firstOrFail();
        $this->assertStringContainsString($order->order_number, $n->title);
        $this->assertStringContainsString('Confirmed', $n->title);
        $this->assertStringContainsString($order->order_number, $n->message);
        $this->assertSame('/orders/' . $order->id, $n->action_url);
        $this->assertSame('Order Status', $n->type);
    }

    public function test_shipping_an_order_emits_a_notification(): void
    {
        $order = $this->placeOrder();
        $this->orderService->changeStatus($order, 'Confirmed');
        Notification::query()->delete(); // isolate the Shipped emission
        $this->satisfyShippingChecklist($order);

        $this->orderService->changeStatus($order->fresh(), 'Shipped');

        $shipped = Notification::where('type', 'Order Status')->get();
        $this->assertCount(3, $shipped);
        $this->assertStringContainsString('Shipped', $shipped->first()->title);
    }

    public function test_delivering_an_order_emits_a_notification(): void
    {
        $order = $this->placeOrder();
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        Notification::query()->delete(); // isolate the Delivered emission

        $this->orderService->changeStatus($order->fresh(), 'Delivered');

        $delivered = Notification::where('type', 'Order Status')->get();
        $this->assertCount(3, $delivered);
        $this->assertStringContainsString('Delivered', $delivered->first()->title);
    }

    public function test_returning_an_order_emits_a_notification(): void
    {
        // Pre-ship Confirmed -> Returned is legal per the R-11 DAG and
        // needs no shipping checklist.
        $order = $this->placeOrder();
        $this->orderService->changeStatus($order, 'Confirmed');
        Notification::query()->delete(); // isolate the Returned emission

        $this->orderService->changeStatus($order->fresh(), 'Returned');

        $returned = Notification::where('type', 'Order Status')->get();
        $this->assertCount(3, $returned);
        $this->assertStringContainsString('Returned', $returned->first()->title);
    }

    public function test_non_notified_status_emits_no_order_status_notification(): void
    {
        $order = $this->placeOrder();

        // New -> On Hold is a legal transition but not a notified status.
        $this->orderService->changeStatus($order, 'On Hold');

        $this->assertSame(0, Notification::where('type', 'Order Status')->count());
    }

    public function test_status_change_succeeds_and_notifies_in_one_call(): void
    {
        $order = $this->placeOrder();

        $updated = $this->orderService->changeStatus($order, 'Confirmed');

        // The status change itself is unaffected by the notification step.
        $this->assertSame('Confirmed', $updated->status);
        $this->assertSame(3, Notification::where('type', 'Order Status')->count());
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function placeOrder(int $qty = 1): Order
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
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);
    }

    private function satisfyShippingChecklist(Order $order): void
    {
        $company = ShippingCompany::firstOrFail();

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'tracking_number' => 'R1-TRK-' . $order->id,
            'shipping_status' => 'Assigned',
            'assigned_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Attachment::create([
            'related_type' => Order::class,
            'related_id' => $order->id,
            'file_name' => 'preship.png',
            'file_url' => 'storage/test/preship.png',
            'file_type' => 'image/png',
            'file_size_bytes' => 64,
            'attachment_type' => Attachment::TYPE_PRE_SHIPPING_PHOTO,
            'uploaded_by' => $this->admin->id,
        ]);

        ShippingLabel::create([
            'order_id' => $order->id,
            'shipment_id' => $shipment->id,
            'label_size' => '4x6',
            'tracking_number' => $shipment->tracking_number,
            'label_pdf_url' => 'storage/test/label.pdf',
            'printed_by' => $this->admin->id,
            'printed_at' => now(),
            'created_at' => now(),
        ]);

        $order->forceFill(['shipping_status' => 'Assigned'])->save();
    }
}
