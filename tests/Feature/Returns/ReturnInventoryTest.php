<?php

namespace Tests\Feature\Returns;

use App\Models\Attachment;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Product;
use App\Models\ReturnReason;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression coverage for the two High-severity bugs surfaced by UAT:
 *
 *   A. Delivered → Returned must write the optimistic Return To Stock
 *      movement (previously gated to oldStatus === 'Shipped').
 *
 *   B. Damaged-return inspection must NOT double-decrement on-hand
 *      (previously wrote both a Return To Stock reversal AND a
 *      Return Damaged -qty, totalling -2*qty against on-hand).
 */
class ReturnInventoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customer;
    private InventoryService $inventory;
    private OrderService $orderService;
    private ReturnService $returnService;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes tables per test; seed our reference data
        // explicitly so this class is independent of test ordering.
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();
        $this->inventory = app(InventoryService::class);
        $this->orderService = app(OrderService::class);
        $this->returnService = app(ReturnService::class);

        $this->actingAs($this->admin);

        $this->product = Product::create([
            'sku' => 'TEST-RET-001',
            'name' => 'Return Inventory Test SKU',
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
            'name' => 'Return Test Customer',
            'primary_phone' => '01099998888',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Test Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance: 100.
        $this->inventory->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'Test fixture opening balance',
        );
    }

    public function test_bug_a_delivered_to_returned_writes_return_to_stock(): void
    {
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order, 'Delivered');

        $this->assertSame(98, $this->inventory->onHandStock($this->product->id, null));

        $this->orderService->changeStatus($order->fresh(), 'Returned');

        $returnToStock = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return To Stock')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($returnToStock, 'Optimistic Return To Stock movement must be written for Delivered → Returned.');
        $this->assertSame(2, (int) $returnToStock->quantity);
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));
    }

    public function test_bug_a_pre_ship_to_returned_does_not_phantom_restock(): void
    {
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        // Stock has been reserved but never shipped.
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));
        $this->assertSame(2, $this->inventory->reservedQuantity($this->product->id, null));

        $this->orderService->changeStatus($order->fresh(), 'Returned');

        $rtsCount = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return To Stock')
            ->count();

        $this->assertSame(0, $rtsCount, 'No Return To Stock should be written if goods never shipped.');
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));
    }

    public function test_good_return_inspection_restores_full_on_hand(): void
    {
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order, 'Delivered');
        $this->orderService->changeStatus($order->fresh(), 'Returned');

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => ReturnReason::firstOrFail()->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->returnService->inspect(
            return: $return,
            condition: 'Good',
            restockable: true,
            refundAmount: 430,
            notes: 'OK to resell',
        );

        $this->assertSame('Restocked', $return->fresh()->return_status);
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));
    }

    public function test_bug_b_damaged_return_does_not_double_decrement(): void
    {
        $order = $this->placeOrderQty(3);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->assertSame(97, $this->inventory->onHandStock($this->product->id, null));

        $this->orderService->changeStatus($order->fresh(), 'Returned');
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => ReturnReason::firstOrFail()->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => false,
            'created_by' => $this->admin->id,
        ]);

        $this->returnService->inspect(
            return: $return,
            condition: 'Damaged',
            restockable: false,
            refundAmount: 0,
            notes: 'Crushed in transit',
        );

        $this->assertSame('Damaged', $return->fresh()->return_status);
        // Net effect: ship -3 and the optimistic +3 reversal = -3 total,
        // matching real-world stock state. NOT -6.
        $this->assertSame(97, $this->inventory->onHandStock($this->product->id, null));

        $damagedMovements = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return Damaged')
            ->count();

        $this->assertSame(0, $damagedMovements, 'No Return Damaged inventory movement should be written; the returns row carries the audit signal.');
    }

    public function test_bug_c_change_status_returns_flash_error_not_500(): void
    {
        $order = $this->placeOrderQty(1);
        $this->orderService->changeStatus($order, 'Confirmed');

        // Skip the shipping checklist gates — no carrier, no photo, no label.
        $response = $this->from('/orders/'.$order->id)
            ->post('/orders/'.$order->id.'/status', ['status' => 'Shipped']);

        $response->assertRedirect('/orders/'.$order->id);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('Shipping checklist failed', session('error'));
        $this->assertSame('Confirmed', $order->fresh()->status);
    }

    /** Satisfy every shipping-checklist gate so changeStatus('Shipped') passes. */
    private function satisfyShippingChecklist(Order $order): void
    {
        $company = ShippingCompany::firstOrFail();

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'tracking_number' => 'TEST-TRK-'.$order->id,
            'shipping_status' => 'Assigned',
            'assigned_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Attachment::create([
            'related_type' => Order::class,
            'related_id' => $order->id,
            'file_name' => 'preship-test.png',
            'file_url' => 'storage/test/preship.png',
            'file_type' => 'image/png',
            'file_size_bytes' => 68,
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

    private function placeOrderQty(int $qty): Order
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
}
