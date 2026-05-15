<?php

namespace Tests\Feature\Returns;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\ReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Returns Phase 3 — optional Received checkpoint.
 *
 * Pins the new `Pending → Received → Inspect` path while protecting the
 * legacy `Pending → Inspect` fast-path. Receiving is a pure lifecycle
 * marker — it must NOT touch inventory, refunds, or cashboxes.
 *
 * Coverage map vs the Phase 3 prompt:
 *   1. mark_received_transitions_pending_to_received                  — ✓
 *   2. mark_received_requires_returns_receive_permission              — ✓
 *   3. order_agent_cannot_mark_received                               — ✓
 *   4. mark_received_is_blocked_after_inspection_or_close             — ✓
 *   5. mark_received_does_not_create_inventory_movement               — ✓
 *   6. mark_received_does_not_create_refund_or_cashbox_transaction    — ✓
 *   7. inspect_from_received_path_still_writes_correct_inventory      — ✓
 *   8. legacy_pending_to_inspect_shortcut_still_works                 — ✓
 *   9. received_status_visible_in_returns_index_and_reports           — ✓
 *  10. returns_receive_permission_is_seeded_for_warehouse_agent_and_not_order_agent — ✓
 */
class ReturnInspectionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ReturnReason $reason;
    private Warehouse $warehouse;
    private Product $product;
    private InventoryService $inventory;
    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->reason = ReturnReason::firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();
        $this->inventory = app(InventoryService::class);
        $this->orderService = app(OrderService::class);

        $this->customer = Customer::create([
            'name' => 'Phase3 Test Customer',
            'primary_phone' => '01099997777',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Phase3 Street',
            'created_by' => $this->admin->id,
        ]);

        $this->product = Product::create([
            'sku' => 'P3-WK-001',
            'name' => 'Phase 3 Test SKU',
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

        // Opening balance so on-hand math has a real starting point.
        $this->inventory->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'Phase 3 fixture opening balance',
        );
    }

    /* ────────────────────── 1. State transition ────────────────────── */

    public function test_mark_received_transitions_pending_to_received(): void
    {
        $return = $this->makeReturn(status: 'Pending');

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect()
            ->assertSessionHas('success', 'Return ' . $return->display_reference . ' marked as received.');

        $this->assertSame('Received', $return->fresh()->return_status);
        $this->assertSame($this->admin->id, $return->fresh()->updated_by);

        // Audit log row written.
        $this->assertSame(1, AuditLog::where('module', 'returns')
            ->where('action', 'received')
            ->where('record_type', OrderReturn::class)
            ->where('record_id', $return->id)
            ->count());
    }

    /* ────────────────────── 2. Permission gating ────────────────────── */

    public function test_mark_received_requires_returns_receive_permission(): void
    {
        $return = $this->makeReturn(status: 'Pending');
        $user = $this->userWith(['returns.view', 'returns.inspect']); // no returns.receive

        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/receive')
            ->assertForbidden();

        $this->assertSame('Pending', $return->fresh()->return_status);
    }

    public function test_order_agent_cannot_mark_received(): void
    {
        $orderAgent = $this->userWithRoleSlug('order-agent');
        $return = $this->makeReturn(status: 'Pending');

        $this->actingAs($orderAgent)
            ->post('/returns/' . $return->id . '/receive')
            ->assertForbidden();

        $this->assertSame('Pending', $return->fresh()->return_status,
            'Order agents create returns at intake; physical receive is warehouse-side and must be denied.');
    }

    public function test_warehouse_agent_can_mark_received(): void
    {
        $warehouseAgent = $this->userWithRoleSlug('warehouse-agent');
        $return = $this->makeReturn(status: 'Pending');

        $this->actingAs($warehouseAgent)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();

        $this->assertSame('Received', $return->fresh()->return_status);
    }

    /* ────────────────────── 3. Blocked from invalid statuses ────────────────────── */

    public function test_mark_received_is_blocked_after_inspection_or_close(): void
    {
        foreach (['Received', 'Restocked', 'Damaged', 'Closed'] as $status) {
            $return = $this->makeReturn(status: $status);

            $this->actingAs($this->admin)
                ->post('/returns/' . $return->id . '/receive')
                ->assertRedirect()
                ->assertSessionHas('error', fn ($msg) => is_string($msg)
                    && str_contains($msg, 'cannot be marked as received'));

            // Status unchanged.
            $this->assertSame($status, $return->fresh()->return_status,
                "Return in status '{$status}' must NOT be moved back to Received.");
        }
    }

    /* ────────────────────── 4. No side-effects on inventory / finance ────────────────────── */

    public function test_mark_received_does_not_create_inventory_movement(): void
    {
        $return = $this->makeReturn(status: 'Pending');
        $movementsBefore = InventoryMovement::count();

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();

        $this->assertSame($movementsBefore, InventoryMovement::count(),
            'Marking a return as received must NOT write any inventory_movements row.');
    }

    public function test_mark_received_does_not_create_refund_or_cashbox_transaction(): void
    {
        $return = $this->makeReturn(status: 'Pending', refundAmount: 200);
        $refundCountBefore = Refund::count();
        $txCountBefore = CashboxTransaction::count();

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();

        $this->assertSame($refundCountBefore, Refund::count(),
            'Marking received must NOT create a refund.');
        $this->assertSame($txCountBefore, CashboxTransaction::count(),
            'Marking received must NOT post to any cashbox.');
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count());
    }

    /* ────────────────────── 5. Inspect from Received still writes correct inventory ────────────────────── */

    public function test_inspect_from_received_path_still_writes_correct_inventory_on_damaged(): void
    {
        // Build an order with one shipped → returned cycle so the optimistic
        // +qty is already on the books. Then march Pending → Received → inspect(Damaged).
        $order = $this->makeShippedThenReturnedOrder(quantity: 3);
        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);

        // The optimistic +3 written at order-status-→-Returned.
        $optimisticPlus = InventoryMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('movement_type', 'Return To Stock')
            ->sum('quantity');
        $this->assertSame(3, (int) $optimisticPlus);

        // Pending → Received.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();
        $this->assertSame('Received', $return->fresh()->return_status);

        // Received → Inspect (Damaged, not restockable).
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/inspect', [
                'product_condition' => 'Damaged',
                'restockable' => false,
            ])
            ->assertRedirect();

        $this->assertSame('Damaged', $return->fresh()->return_status);

        // Reversal -3 written referenced to the return.
        $reversal = InventoryMovement::where('reference_type', OrderReturn::class)
            ->where('reference_id', $return->id)
            ->where('movement_type', 'Return To Stock')
            ->sum('quantity');
        $this->assertSame(-3, (int) $reversal,
            'Inspect-from-Received must still write the reversal exactly like inspect-from-Pending.');
    }

    public function test_inspect_from_received_path_writes_no_extra_movement_on_good_restockable(): void
    {
        $order = $this->makeShippedThenReturnedOrder(quantity: 3);
        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $movementsBefore = InventoryMovement::count();

        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/inspect', [
                'product_condition' => 'Good',
                'restockable' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Restocked', $return->fresh()->return_status);
        $this->assertSame($movementsBefore, InventoryMovement::count(),
            'Good + restockable from the Received path must write no further movement — the optimistic +qty stands.');
    }

    /* ────────────────────── 6. Legacy fast-path still works ────────────────────── */

    public function test_legacy_pending_to_inspect_shortcut_still_works(): void
    {
        $order = $this->makeShippedThenReturnedOrder(quantity: 2);
        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);

        // Skip receive entirely — inspect directly from Pending.
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/inspect', [
                'product_condition' => 'Good',
                'restockable' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Restocked', $return->fresh()->return_status,
            'The Pending → Inspect fast-path must remain supported. Receive is optional, not a prerequisite.');
    }

    /* ────────────────────── 7. Received status surfaces in indices ────────────────────── */

    public function test_received_status_appears_in_returns_index_when_filtered(): void
    {
        // Create one Received return; confirm /returns?status=Received shows it.
        $return = $this->makeReturn(status: 'Pending');
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get('/returns?status=Received')
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Index')
                ->where('returns.data', function ($rows) use ($return) {
                    return collect($rows)->contains(fn ($r) => $r['id'] === $return->id);
                })
                ->where('counts.by_status.Received', fn ($v) => (int) $v >= 1)
            );
    }

    public function test_received_status_appears_in_returns_report_by_status_breakdown(): void
    {
        $return = $this->makeReturn(status: 'Pending');
        $this->actingAs($this->admin)
            ->post('/returns/' . $return->id . '/receive')
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->get('/reports/returns')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('by_status', function ($rows) {
                    $received = collect($rows)->firstWhere('status', 'Received');
                    return $received !== null && (int) $received['count'] === 1 && $received['bucket'] === 'active';
                })
            );
    }

    /* ────────────────────── 8. Permission seeding ────────────────────── */

    public function test_returns_receive_permission_is_seeded(): void
    {
        $this->assertSame(1, Permission::where('slug', 'returns.receive')->count(),
            'The returns.receive permission slug must be seeded by PermissionsSeeder.');
    }

    public function test_warehouse_agent_role_grants_returns_receive(): void
    {
        $role = Role::where('slug', 'warehouse-agent')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();
        $this->assertContains('returns.receive', $slugs,
            'Warehouse agents must have returns.receive — they handle physical receipt.');
        $this->assertContains('returns.inspect', $slugs);
    }

    public function test_manager_role_grants_returns_receive(): void
    {
        $role = Role::where('slug', 'manager')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();
        $this->assertContains('returns.receive', $slugs);
    }

    public function test_order_agent_role_does_not_grant_returns_receive(): void
    {
        $role = Role::where('slug', 'order-agent')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();
        $this->assertNotContains('returns.receive', $slugs,
            'Order agents are the intake role — receiving is warehouse work and must NOT be granted.');
    }

    public function test_viewer_role_does_not_grant_returns_receive(): void
    {
        $role = Role::where('slug', 'viewer')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();
        $this->assertNotContains('returns.receive', $slugs);
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(string $status = 'Delivered'): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'WK-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
            'fiscal_year_id' => FiscalYear::firstOrFail()->id,
            'customer_id' => $this->customer->id,
            'status' => $status,
            'collection_status' => 'Collected',
            'shipping_status' => $status === 'Delivered' ? 'Delivered' : 'Not Shipped',
            'customer_name' => $this->customer->name,
            'customer_phone' => $this->customer->primary_phone,
            'customer_address' => $this->customer->default_address,
            'city' => 'Cairo',
            'country' => 'Egypt',
            'currency_code' => 'EGP',
            'total_amount' => 200,
            'created_by' => $this->admin->id,
        ]);
    }

    private function makeReturn(string $status = 'Pending', float $refundAmount = 0): OrderReturn
    {
        $order = $this->makeOrder();
        return OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => $status,
            'product_condition' => 'Unknown',
            'refund_amount' => $refundAmount,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    /**
     * Build a real Delivered → Returned cycle so the OPTIMISTIC +qty
     * inventory movement is on the books AND a Pending OrderReturn
     * exists. Returns the parent Order; the linked OrderReturn can be
     * fetched via `OrderReturn::where('order_id', ...)`.
     *
     * Drives the order through the same lifecycle the production code
     * does — Confirmed → Shipped → Delivered → Returned — using the
     * service layer directly, and finishes the atomic Returned step via
     * the HTTP route so `OrderStatusFlowService` creates the OrderReturn
     * in lockstep with the optimistic +qty.
     */
    private function makeShippedThenReturnedOrder(int $quantity): Order
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
                'quantity' => $quantity,
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);

        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order->fresh(), 'Delivered');

        // Atomic Delivered → Returned via the HTTP route so
        // OrderStatusFlowService creates the OrderReturn AND writes the
        // optimistic +qty in one transaction.
        $this->actingAs($this->admin)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Unknown',
                ],
            ])
            ->assertRedirect();

        return $order->refresh();
    }

    /**
     * Minimum fixture that satisfies the shipping-checklist guard so
     * `OrderService::changeStatus($order, 'Shipped')` doesn't throw.
     * Mirrors the helper in ReturnInventoryTest.
     */
    private function satisfyShippingChecklist(Order $order): void
    {
        $company = ShippingCompany::firstOrFail();

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'tracking_number' => 'P3-TRK-' . $order->id,
            'shipping_status' => 'Assigned',
            'assigned_at' => now(),
            'created_by' => $this->admin->id,
        ]);

        Attachment::create([
            'related_type' => Order::class,
            'related_id' => $order->id,
            'file_name' => 'p3-preship.png',
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
        ]);
    }

    /**
     * Build a user attached to one of the seeded *system* roles (so we
     * test the real production role surface).
     */
    private function userWithRoleSlug(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();
        return User::create([
            'name' => 'Phase3-' . $slug . '-' . uniqid(),
            'email' => $slug . '+p3+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    /**
     * Build a user with an ad-hoc role granting exactly the listed slugs.
     */
    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'Phase3 ad-hoc ' . uniqid(),
            'slug' => 'phase3-adhoc-' . uniqid(),
            'description' => 'Phase 3 inspection workflow test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'Phase3 ad-hoc user',
            'email' => 'phase3-adhoc+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
