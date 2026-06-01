<?php

namespace Tests\Feature\Orders;

use App\Exceptions\ApprovalRequiredException;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ApprovalService;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * R6 — Order Approval Gate.
 *
 * High-value orders (total_amount >= 10000 by default) and high-discount
 * orders (header discount >= 10% of subtotal) require an approval
 * before they can confirm. The gate creates an ApprovalRequest of type
 * 'High-Value Order Confirmation' via the existing ApprovalService and
 * throws ApprovalRequiredException — the status stays at the previous
 * value. Approving the request executes the actual confirmation via
 * the handler (bypassApprovalGate=true), so DAG / inventory reservation
 * / R1 notification all fire normally.
 *
 * Thresholds:
 *   - order_approval_high_value_threshold (default 10000)
 *   - order_approval_high_discount_percent (default 10)
 *
 * "Non-standard shipping" is deferred (no shipping_method column today).
 */
class OrderApprovalGateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $approver;
    private Product $product;
    private Customer $customer;
    private OrderService $orderService;
    private ApprovalService $approvalService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->approver = User::create([
            'name' => 'R6 Approver',
            'email' => 'r6-approver+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $this->admin->role_id,
            'status' => 'Active',
        ]);

        $this->orderService = app(OrderService::class);
        $this->approvalService = app(ApprovalService::class);

        $warehouse = Warehouse::firstOrFail();

        $this->product = Product::create([
            'sku' => 'R6-APPR-001',
            'name' => 'R6 Approval Test SKU',
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
            'name' => 'R6 Approval Customer',
            'primary_phone' => '01055556666',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Approval Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance so any approved Confirmed transition can
        // reserve stock through OrderService::applyInventoryForTransition.
        app(InventoryService::class)->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'R6 fixture opening balance',
        );

        $this->actingAs($this->admin);
    }

    public function test_order_below_threshold_confirms_normally(): void
    {
        $order = $this->placeOrder(unitPrice: 200, qty: 1, headerDiscount: 0);

        $updated = $this->orderService->changeStatus($order, 'Confirmed');

        $this->assertSame('Confirmed', $updated->status);
        $this->assertSame(0, ApprovalRequest::query()
            ->where('related_type', Order::class)
            ->where('related_id', $order->id)
            ->count());
    }

    public function test_high_value_order_creates_approval_request(): void
    {
        $order = $this->placeOrder(unitPrice: 10000, qty: 2);

        try {
            $this->orderService->changeStatus($order, 'Confirmed');
            $this->fail('Expected ApprovalRequiredException for high-value order.');
        } catch (ApprovalRequiredException $e) {
            // expected
        }

        $this->assertSame('New', $order->fresh()->status, 'Status must stay New until approved.');

        $req = ApprovalRequest::query()
            ->where('approval_type', 'High-Value Order Confirmation')
            ->where('related_type', Order::class)
            ->where('related_id', $order->id)
            ->firstOrFail();
        $this->assertSame('Pending', $req->status);
        $this->assertSame($this->admin->id, (int) $req->requested_by);
    }

    public function test_high_discount_order_creates_approval_request(): void
    {
        // subtotal=500, header discount=100 → discount/subtotal=20% > 10%
        // total ≈ 430, well below the 10000 value threshold — discount alone trips.
        $order = $this->placeOrder(unitPrice: 500, qty: 1, headerDiscount: 100);

        try {
            $this->orderService->changeStatus($order, 'Confirmed');
            $this->fail('Expected ApprovalRequiredException for high-discount order.');
        } catch (ApprovalRequiredException $e) {
            // expected
        }

        $this->assertSame('New', $order->fresh()->status);
        $this->assertSame(1, ApprovalRequest::query()
            ->where('approval_type', 'High-Value Order Confirmation')
            ->where('related_id', $order->id)
            ->count());
    }

    public function test_pending_approval_blocks_direct_confirmation_no_duplicate(): void
    {
        $order = $this->placeOrder(unitPrice: 10000, qty: 2);

        // First attempt — creates the pending request.
        try {
            $this->orderService->changeStatus($order, 'Confirmed');
        } catch (ApprovalRequiredException $e) {
            // expected
        }
        $this->assertSame(1, ApprovalRequest::query()
            ->where('related_id', $order->id)->count());

        // Second attempt while pending — throws AND creates no second row.
        try {
            $this->orderService->changeStatus($order->fresh(), 'Confirmed');
            $this->fail('Expected ApprovalRequiredException on repeat.');
        } catch (ApprovalRequiredException $e) {
            // expected
        }
        $this->assertSame(1, ApprovalRequest::query()
            ->where('related_id', $order->id)->count(),
            'A second pending request must NOT be created while one is already open.');
    }

    public function test_approved_request_confirms_the_order(): void
    {
        $order = $this->placeOrder(unitPrice: 10000, qty: 2);

        // Creator attempts → pending request created.
        try {
            $this->orderService->changeStatus($order, 'Confirmed');
        } catch (ApprovalRequiredException $e) {
            // expected
        }
        $req = ApprovalRequest::query()->where('related_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $req->status);

        // Approver (different user) approves → handler runs → order confirmed.
        $this->actingAs($this->approver);
        $this->approvalService->approve($req);

        $this->assertSame('Confirmed', $order->fresh()->status);
        $this->assertSame('Approved', $req->fresh()->status);
    }

    public function test_rejected_request_does_not_confirm_the_order(): void
    {
        $order = $this->placeOrder(unitPrice: 10000, qty: 2);

        try {
            $this->orderService->changeStatus($order, 'Confirmed');
        } catch (ApprovalRequiredException $e) {
            // expected
        }
        $req = ApprovalRequest::query()->where('related_id', $order->id)->firstOrFail();

        $this->actingAs($this->approver);
        $this->approvalService->reject($req, 'too risky');

        $this->assertSame('New', $order->fresh()->status, 'Rejected request must NOT confirm.');
        $this->assertSame('Rejected', $req->fresh()->status);
    }

    public function test_settings_threshold_override_skips_approval(): void
    {
        // Lift the high-value threshold to 50000 — an order at 20030
        // should now skip the gate entirely.
        SettingsService::set('order_approval_high_value_threshold', 50000, 'orders', 'number');

        $order = $this->placeOrder(unitPrice: 10000, qty: 2);

        $updated = $this->orderService->changeStatus($order, 'Confirmed');

        $this->assertSame('Confirmed', $updated->status);
        $this->assertSame(0, ApprovalRequest::query()
            ->where('related_id', $order->id)->count(),
            'Approval threshold override must skip the gate when neither limit is exceeded.');
    }

    /* ───────────────────────── helper ───────────────────────── */

    private function placeOrder(int $unitPrice, int $qty = 1, int $headerDiscount = 0): Order
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
            'discount_amount' => $headerDiscount,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);
    }
}
