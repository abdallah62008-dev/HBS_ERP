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
 * Inventory side-effects for returns under the Phase 4B policy:
 * restock-after-good-inspection.
 *
 * Pinned invariants:
 *   - `Order → Returned` writes NO inventory movement (optimistic +qty
 *     removed in Phase 4B). True for both pre-ship and post-ship origins.
 *   - `ReturnService::inspect('Good', restockable=true)` writes ONE +qty
 *     `Return To Stock` per item, referenced to the OrderReturn.
 *   - Any other inspection verdict (Damaged / Missing Parts / Unknown / or
 *     restockable=false) writes NOTHING. The Ship -qty stays as the
 *     write-off baseline.
 *   - `markReceived()` and `close()` write nothing.
 *
 * The previous optimistic-restock model and its `-qty` reversal on Damaged
 * are no longer in the codebase. Tests previously named `bug_a_*` and
 * `bug_b_*` referred to UAT bugs against that old model; under the new
 * policy the same scenarios produce different (correct) outcomes and
 * have been renamed.
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

    public function test_delivered_to_returned_does_not_write_return_to_stock(): void
    {
        // Phase 4B policy — the post-ship → Returned transition no longer
        // writes the optimistic +qty. On-hand stays at the post-Ship level
        // until inspection happens.
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order, 'Delivered');

        $this->assertSame(98, $this->inventory->onHandStock($this->product->id, null),
            'Pre-condition: post-ship on-hand is 100 − 2 = 98.');

        $this->orderService->changeStatus($order->fresh(), 'Returned');

        $rts = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return To Stock')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->count();

        $this->assertSame(0, $rts,
            'Phase 4B: Delivered → Returned MUST NOT write the optimistic Return To Stock movement.');
        $this->assertSame(98, $this->inventory->onHandStock($this->product->id, null),
            'On-hand must stay at the post-Ship level until inspection decides the verdict.');
    }

    public function test_pre_ship_to_returned_still_does_not_phantom_restock(): void
    {
        // Behaviour unchanged from Phase 4A: pre-ship → Returned has no
        // on-hand to restore and writes nothing.
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
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

    public function test_good_restockable_inspection_writes_return_to_stock(): void
    {
        // Phase 4B — the +qty is now written by inspect(), not by the
        // status transition. The movement's reference is the OrderReturn.
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order, 'Delivered');
        $this->orderService->changeStatus($order->fresh(), 'Returned');

        // Before inspection: on-hand is still the post-Ship level.
        $this->assertSame(98, $this->inventory->onHandStock($this->product->id, null));

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

        // After Good + restockable inspection: stock is restored.
        $this->assertSame('Restocked', $return->fresh()->return_status);
        $this->assertSame(100, $this->inventory->onHandStock($this->product->id, null));

        $rts = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return To Stock')
            ->where('reference_type', OrderReturn::class)
            ->where('reference_id', $return->id)
            ->first();

        $this->assertNotNull($rts,
            'Good + restockable inspection MUST write a +qty Return To Stock referenced to the OrderReturn.');
        $this->assertSame(2, (int) $rts->quantity);
    }

    public function test_damaged_inspection_writes_no_inventory_movement(): void
    {
        $order = $this->placeOrderQty(3);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->assertSame(97, $this->inventory->onHandStock($this->product->id, null));

        $this->orderService->changeStatus($order->fresh(), 'Returned');
        // Phase 4B: Returned no longer +3s on-hand. Stays at 97.
        $this->assertSame(97, $this->inventory->onHandStock($this->product->id, null));

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

        $movementsBefore = InventoryMovement::count();

        $this->returnService->inspect(
            return: $return,
            condition: 'Damaged',
            restockable: false,
            refundAmount: 0,
            notes: 'Crushed in transit',
        );

        $this->assertSame('Damaged', $return->fresh()->return_status);
        $this->assertSame($movementsBefore, InventoryMovement::count(),
            'Phase 4B: Damaged inspection MUST write zero inventory rows — there is no phantom +qty to reverse.');
        // On-hand reflects the Ship -3 only; the damaged goods are written off.
        $this->assertSame(97, $this->inventory->onHandStock($this->product->id, null));

        $damagedMovements = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return Damaged')
            ->count();

        $this->assertSame(0, $damagedMovements, 'No Return Damaged inventory movement should be written; the returns row carries the audit signal.');
    }

    public function test_missing_parts_or_not_restockable_inspection_writes_no_inventory_movement(): void
    {
        // Two non-Good verdicts in one test: 'Missing Parts' (a non-Good
        // condition) and 'Good' + restockable=false (Good condition but
        // operator explicitly says "don't restock"). Both must produce
        // zero inventory rows under the new policy.
        foreach ([
            ['condition' => 'Missing Parts', 'restockable' => true, 'note' => 'Missing accessories'],
            ['condition' => 'Unknown', 'restockable' => true, 'note' => 'Could not verify'],
            ['condition' => 'Good', 'restockable' => false, 'note' => 'Good but operator overrode'],
        ] as $verdict) {
            $order = $this->placeOrderQty(1);
            $this->orderService->changeStatus($order, 'Confirmed');
            $this->satisfyShippingChecklist($order);
            $this->orderService->changeStatus($order->fresh(), 'Shipped');
            $this->orderService->changeStatus($order->fresh(), 'Delivered');
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

            $movementsBefore = InventoryMovement::count();

            $this->returnService->inspect(
                return: $return,
                condition: $verdict['condition'],
                restockable: $verdict['restockable'],
                refundAmount: 0,
                notes: $verdict['note'],
            );

            $this->assertSame(
                $movementsBefore,
                InventoryMovement::count(),
                "Verdict ({$verdict['condition']}, restockable=" . ($verdict['restockable'] ? 'true' : 'false')
                . ') must write zero inventory rows under Phase 4B.'
            );
        }
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

    /**
     * Phase 4A audit pin — closing a return must NOT write any
     * inventory_movements row. Closure is a pure lifecycle marker; by
     * the time it runs, inspection has already either kept the
     * optimistic +qty (Restocked) or reversed it (Damaged), and on-hand
     * is correct. Writing anything on close would double-count.
     *
     * This is the regression test for the Phase 4A audit. Without it, a
     * future change that accidentally couples `ReturnService::close()`
     * to inventory would slip past every existing test.
     */
    public function test_closing_return_does_not_create_inventory_movement(): void
    {
        $order = $this->placeOrderQty(2);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order->fresh(), 'Delivered');
        $this->orderService->changeStatus($order->fresh(), 'Returned');

        // Open and inspect the return so it's eligible to close. We use
        // Good + restockable so close() runs after a successful "no further
        // movement" inspection, but the assertion below holds for either
        // verdict — close() always writes zero rows.
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => ReturnReason::firstOrFail()->id,
            'return_status' => 'Restocked',
            'product_condition' => 'Good',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
            'inspected_by' => $this->admin->id,
            'inspected_at' => now(),
        ]);

        $movementsBefore = InventoryMovement::count();
        $onHandBefore = $this->inventory->onHandStock($this->product->id, null);

        app(\App\Services\ReturnService::class)->close($return, 'Audit test closure.');

        $this->assertSame('Closed', $return->fresh()->return_status);
        $this->assertSame($movementsBefore, InventoryMovement::count(),
            'Closing a return must NOT write any inventory_movements row — closure is a pure lifecycle marker.'
        );
        $this->assertSame($onHandBefore, $this->inventory->onHandStock($this->product->id, null),
            'Closing a return must NOT change on-hand by even a single unit.'
        );
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
