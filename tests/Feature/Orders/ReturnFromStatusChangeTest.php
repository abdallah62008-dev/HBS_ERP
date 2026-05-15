<?php

namespace Tests\Feature\Orders;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\ShippingCompany;
use App\Models\ShippingLabel;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Returns/Refunds UX fix — when an operator changes an order's status
 * to `Returned`, the system must atomically create the linked return
 * record so Phase 5C refund-from-return becomes usable immediately.
 *
 * This test class pins the new behaviour without disturbing the
 * existing Order status-change tests in tests/Feature/Returns/.
 */
class ReturnFromStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customer;
    private ReturnReason $reason;
    private InventoryService $inventory;
    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();
        $this->inventory = app(InventoryService::class);
        $this->orderService = app(OrderService::class);

        $this->product = Product::create([
            'sku' => 'UX-RET-001',
            'name' => 'UX Return Test SKU',
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
            'name' => 'UX Return Test Customer',
            'primary_phone' => '01077778888',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 UX Street',
            'created_by' => $this->admin->id,
        ]);

        $this->reason = ReturnReason::firstOrCreate(
            ['name' => 'UX Return Reason'],
            ['status' => 'Active'],
        );

        // Opening balance so shipping doesn't fail on stock.
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

    /* ────────────────────── 1. Permission gating ────────────────────── */

    public function test_user_with_change_status_but_without_returns_create_cannot_set_returned(): void
    {
        $user = $this->userWith(['orders.change_status', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                ],
            ])
            ->assertForbidden();

        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }

    /* ────────────────────── 2. Validation gating ────────────────────── */

    public function test_setting_returned_without_return_payload_is_blocked_with_validation_error(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->from('/orders/' . $order->id)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                // no `return` key at all
            ]);

        $response->assertSessionHasErrors('return.return_reason_id');
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }

    public function test_setting_returned_with_unknown_reason_is_blocked(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->from('/orders/' . $order->id)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => ['return_reason_id' => 99999],
            ]);

        $response->assertSessionHasErrors('return.return_reason_id');
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }

    /* ────────────────────── 3. Happy path ────────────────────── */

    public function test_setting_returned_with_valid_payload_creates_return_and_changes_status_atomically(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                    'refund_amount' => 200,
                    'shipping_loss_amount' => 30,
                    'notes' => 'Customer returned in good condition',
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Returned', $order->fresh()->status);

        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);
        $this->assertSame('Good', $return->product_condition);
        $this->assertSame('200.00', (string) $return->refund_amount);
        $this->assertSame('30.00', (string) $return->shipping_loss_amount);
        $this->assertSame($this->reason->id, $return->return_reason_id);
    }

    public function test_success_redirects_to_the_new_return_show_page(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view', 'returns.view']);
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => ['return_reason_id' => $this->reason->id],
            ]);

        $return = OrderReturn::firstOrFail();
        $response->assertRedirect('/returns/' . $return->id);
        $response->assertSessionHas('success');
    }

    /* ────────────────────── 4. Duplicate-return protection ────────────────────── */

    public function test_setting_returned_when_order_already_has_a_return_is_blocked(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        // Pre-existing return — simulates a client trying to POST
        // status=Returned for an order that already has a return
        // (the modal normally hides this option, but the backend must
        // refuse even if the UI is bypassed).
        OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => false,
            'created_by' => $this->admin->id,
        ]);
        $this->assertSame(1, OrderReturn::where('order_id', $order->id)->count());

        $this->actingAs($user)
            ->from('/orders/' . $order->id)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => ['return_reason_id' => $this->reason->id],
            ])
            ->assertRedirect('/orders/' . $order->id);

        $this->assertSame(
            1,
            OrderReturn::where('order_id', $order->id)->count(),
            'Duplicate return must be blocked by the OrderStatusFlowService guard.',
        );

        // The duplicate attempt should also leave the order status untouched
        // (the wrapper throws before calling OrderService::changeStatus).
        $this->assertSame('Delivered', $order->fresh()->status);

        // Flash error surfaced to the operator.
        $this->assertNotNull(session('error'));
    }

    /* ────────────────────── 5. Pass-through for non-Returned statuses ────────────────────── */

    public function test_changing_to_other_statuses_still_works_and_does_not_create_a_return(): void
    {
        $user = $this->userWith(['orders.change_status', 'orders.view']);
        $order = $this->placedOrder(); // status='New'

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', ['status' => 'Confirmed'])
            ->assertRedirect();

        $this->assertSame('Confirmed', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::count());
    }

    /* ───── 5b. Real frontend payload — full `return` object on non-Returned status ───── */

    /**
     * Regression: the Orders/Show "Change status" modal and the
     * Orders/Edit form ALWAYS send a `return` object, even for
     * non-Returned status changes. `return.return_reason_id` leaves the
     * browser as '' which the global ConvertEmptyStringsToNull
     * middleware rewrites to null. Before the fix, the
     * `exists:return_reasons,id` rule ran against null and failed,
     * silently rejecting EVERY status change from the modal. These
     * tests post the EXACT payload shape the React forms send.
     */
    public function test_change_status_accepts_full_return_object_for_non_returned_status(): void
    {
        $user = $this->userWith(['orders.change_status', 'orders.view']);
        $order = $this->placedOrder(); // status='New'

        $this->actingAs($user)
            ->from('/orders/' . $order->id)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Confirmed',
                'note' => '',
                'return' => [
                    'return_reason_id' => '',
                    'product_condition' => 'Unknown',
                    'refund_amount' => 0,
                    'shipping_loss_amount' => 0,
                    'notes' => '',
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Confirmed', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::count(), 'No return should be created for a non-Returned status change.');
    }

    public function test_change_status_returned_with_empty_reason_in_return_object_is_blocked(): void
    {
        // The modal sends the `return` object with an EMPTY reason on the
        // Returned path. `nullable` only relaxes the rule for non-Returned
        // statuses — `required_if:status,Returned` must still reject this.
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->from('/orders/' . $order->id)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'note' => '',
                'return' => [
                    'return_reason_id' => '',
                    'product_condition' => 'Unknown',
                    'refund_amount' => 0,
                    'shipping_loss_amount' => 0,
                    'notes' => '',
                ],
            ])
            ->assertSessionHasErrors('return.return_reason_id');

        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::count());
    }

    public function test_order_update_accepts_full_return_object_for_non_returned_status(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view', 'orders.change_status']);
        $order = $this->placedOrder(); // status='New'

        $this->actingAs($user)
            ->from('/orders/' . $order->id . '/edit')
            ->put('/orders/' . $order->id, [
                'customer_address' => '1 Updated Street',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Confirmed',
                'status_note' => '',
                'return' => [
                    'return_reason_id' => '',
                    'product_condition' => 'Unknown',
                    'refund_amount' => 0,
                    'shipping_loss_amount' => 0,
                    'notes' => '',
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('Confirmed', $fresh->status);
        $this->assertSame('1 Updated Street', $fresh->customer_address);
        $this->assertSame(0, OrderReturn::count(), 'No return should be created for a non-Returned status change.');
    }

    /* ────────────────────── 6. Inventory + audit pass-through ────────────────────── */

    public function test_inventory_return_to_stock_still_fires_via_existing_orderservice_path(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder(qty: 2);

        $onHandBefore = $this->inventory->onHandStock($this->product->id, null);

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => ['return_reason_id' => $this->reason->id],
            ])
            ->assertRedirect();

        $rts = InventoryMovement::query()
            ->where('product_id', $this->product->id)
            ->where('movement_type', 'Return To Stock')
            ->where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($rts, 'Existing OrderService inventory hook must still fire under the new flow.');
        $this->assertSame(2, (int) $rts->quantity);
        // Order shipped 2 (-2), then returned (+2) → restored to original.
        $this->assertSame($onHandBefore + 2, $this->inventory->onHandStock($this->product->id, null));
    }

    public function test_audit_log_records_both_status_change_and_return_creation_in_same_transaction(): void
    {
        $user = $this->userWith(['orders.change_status', 'returns.create', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => ['return_reason_id' => $this->reason->id],
            ])
            ->assertRedirect();

        $statusChangeRow = AuditLog::query()
            ->where('module', 'orders')
            ->where('action', 'status_change')
            ->where('record_type', Order::class)
            ->where('record_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($statusChangeRow, 'OrderService status_change audit must still fire.');

        $return = OrderReturn::firstOrFail();
        $returnCreatedRow = AuditLog::query()
            ->where('module', 'returns')
            ->where('action', 'created')
            ->where('record_type', OrderReturn::class)
            ->where('record_id', $return->id)
            ->first();
        $this->assertNotNull($returnCreatedRow, 'ReturnService::open audit must still fire.');
    }

    /* ────────────────────── 7. Order Edit page — same atomic flow ────────────────────── */

    public function test_edit_page_receives_return_props(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view', 'returns.create']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)->get('/orders/' . $order->id . '/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Edit')
                ->has('return_reasons')
                ->has('return_conditions', 4)
                ->where('can_create_return', true)
                ->where('has_return', false)
                // Professional Return Management — when there is no
                // return yet, `existing_return` is explicitly null so
                // the frontend's Manage-return button / banner stays
                // hidden.
                ->where('existing_return', null)
            );
    }

    public function test_orders_show_and_edit_expose_existing_return_when_a_return_exists(): void
    {
        // After a Returned transition the operator should be able to
        // reach the return page directly from BOTH Orders/Show and
        // Orders/Edit — the controllers must surface the return's
        // id / status / condition so the frontend can render a
        // "Manage return" link to /returns/{id}.
        $user = $this->userWith(['orders.view', 'orders.edit', 'orders.change_status', 'returns.create', 'returns.view']);
        $order = $this->deliveredOrder();

        // Create the return via the supported atomic-flow endpoint.
        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                ],
            ])->assertRedirect();

        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();

        // Orders/Show exposes existing_return.
        $this->actingAs($user)->get('/orders/' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('has_return', true)
                ->where('existing_return.id', $return->id)
                ->where('existing_return.return_status', $return->return_status)
                ->where('existing_return.product_condition', 'Good')
            );

        // Orders/Edit exposes existing_return (symmetric — the same
        // banner/hint logic runs on the Edit page).
        $this->actingAs($user)->get('/orders/' . $order->id . '/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Edit')
                ->where('has_return', true)
                ->where('existing_return.id', $return->id)
            );
    }

    public function test_edit_page_can_create_return_flag_false_without_permission(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)->get('/orders/' . $order->id . '/edit')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can_create_return', false)
            );
    }

    public function test_order_update_route_blocks_status_returned_without_return_payload(): void
    {
        // Submitting the bulk edit form with status=Returned but no
        // return payload must surface a validation error on
        // return.return_reason_id (required_if rule). Nothing should save.
        $user = $this->userWith(['orders.edit', 'orders.view', 'returns.create']);
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->from('/orders/' . $order->id . '/edit')
            ->put('/orders/' . $order->id, [
                'customer_address' => 'Updated address line',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'status_note' => 'attempted via edit',
            ]);

        $response->assertSessionHasErrors('return.return_reason_id');
        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
        // The whole update is rejected by validation — no partial save.
        $this->assertNotSame('Updated address line', $order->fresh()->customer_address);
    }

    public function test_order_update_route_creates_return_atomically_when_status_returned(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view', 'returns.create', 'returns.view']);
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => '1 Edited Street',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'status_note' => 'via edit page',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                    'refund_amount' => 250,
                    'shipping_loss_amount' => 30,
                    'notes' => 'edit-page return',
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $fresh = $order->fresh();
        $this->assertSame('Returned', $fresh->status);
        // Other field edits persisted (atomic save).
        $this->assertSame('1 Edited Street', $fresh->customer_address);

        // Return record created.
        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);
        $this->assertSame('Good', $return->product_condition);
        $this->assertSame('250.00', (string) $return->refund_amount);
        $this->assertSame('30.00', (string) $return->shipping_loss_amount);
        $this->assertSame($this->reason->id, $return->return_reason_id);

        // Redirect goes to the new return.
        $response->assertRedirect('/returns/' . $return->id);
    }

    public function test_order_update_route_returned_requires_returns_create_permission(): void
    {
        // orders.edit alone is not enough — Returned transition needs returns.create.
        $user = $this->userWith(['orders.edit', 'orders.view']);
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => 'Address change',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                ],
            ])
            ->assertForbidden();

        $this->assertSame('Delivered', $order->fresh()->status);
        $this->assertSame(0, OrderReturn::where('order_id', $order->id)->count());
    }

    public function test_order_update_route_atomic_rollback_when_return_creation_fails(): void
    {
        // Simulate the duplicate-return guard: pre-existing return on
        // the order makes OrderStatusFlowService throw. The entire
        // update (including the customer_address edit) must roll back.
        $user = $this->userWith(['orders.edit', 'orders.view', 'returns.create']);
        $order = $this->deliveredOrder();

        OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => false,
            'created_by' => $this->admin->id,
        ]);
        $originalAddress = $order->customer_address;

        $this->actingAs($user)
            ->from('/orders/' . $order->id . '/edit')
            ->put('/orders/' . $order->id, [
                'customer_address' => 'Address change that should roll back',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'return' => ['return_reason_id' => $this->reason->id],
            ])
            ->assertRedirect();

        // Order status untouched.
        $this->assertSame('Delivered', $order->fresh()->status);
        // Only the pre-existing return — no duplicate.
        $this->assertSame(1, OrderReturn::where('order_id', $order->id)->count());
        // Address edit rolled back along with the failed return creation.
        $this->assertSame($originalAddress, $order->fresh()->customer_address);
        $this->assertNotNull(session('error'));
    }

    public function test_order_update_route_still_works_for_non_returned_status_changes(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view', 'orders.change_status']);
        $order = $this->placedOrder(); // status='New'

        $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => '1 Updated Street',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Confirmed',
            ])
            ->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('Confirmed', $fresh->status);
        $this->assertSame('1 Updated Street', $fresh->customer_address);
        $this->assertSame(0, OrderReturn::count());
    }

    public function test_order_update_route_keeps_returned_status_when_already_returned(): void
    {
        // An order ALREADY in Returned status can be edited for
        // non-status fields without re-triggering return creation.
        $user = $this->userWith(['orders.edit', 'orders.view', 'orders.change_status', 'returns.create']);
        $order = $this->deliveredOrder();

        // Use the modal flow to enter Returned legitimately.
        $this->actingAs($user)->post('/orders/' . $order->id . '/status', [
            'status' => 'Returned',
            'return' => ['return_reason_id' => $this->reason->id],
        ])->assertRedirect();
        $this->assertSame('Returned', $order->fresh()->status);

        // Now edit a non-status field while leaving status=Returned.
        $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => 'Post-return address fix',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
            ])
            ->assertRedirect();

        $this->assertSame('Post-return address fix', $order->fresh()->customer_address);
        $this->assertSame('Returned', $order->fresh()->status);
        $this->assertSame(1, OrderReturn::where('order_id', $order->id)->count(), 'No duplicate return.');
    }

    public function test_order_update_route_returned_does_not_create_refund_or_cashbox_tx(): void
    {
        $user = $this->userWith(['orders.edit', 'orders.view', 'returns.create']);
        $order = $this->deliveredOrder();

        $refundCountBefore = \App\Models\Refund::count();
        $cashboxTxCountBefore = \App\Models\CashboxTransaction::count();

        $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => 'no side effects',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'refund_amount' => 200,
                ],
            ])
            ->assertRedirect();

        $this->assertSame($refundCountBefore, \App\Models\Refund::count(), 'No refund should be auto-created.');
        $this->assertSame($cashboxTxCountBefore, \App\Models\CashboxTransaction::count(), 'No cashbox tx should be written.');
    }

    /* ────────────────────── 8. Phase 5C integration ────────────────────── */

    public function test_after_status_change_creates_return_phase_5c_refund_request_works(): void
    {
        $user = $this->userWith([
            'orders.change_status', 'returns.create', 'orders.view',
            'returns.inspect', 'returns.view',
            // Phase 5C uses the `refunds.create` slug for the
            // /returns/{return}/request-refund endpoint.
            'refunds.create',
        ]);
        $order = $this->deliveredOrder();

        // Step 1: status change creates a return in Pending.
        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'refund_amount' => 150,
                ],
            ])
            ->assertRedirect();

        $return = OrderReturn::firstOrFail();
        $this->assertSame('Pending', $return->return_status);

        // Step 2: inspect moves it to Restocked/Damaged depending on
        // condition. We pick Good+restockable so it lands as Restocked.
        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/inspect', [
                'product_condition' => 'Good',
                'restockable' => true,
                'refund_amount' => 150,
                'notes' => 'OK to resell',
            ])
            ->assertRedirect();

        $this->assertContains($return->fresh()->return_status, OrderReturn::REFUND_ELIGIBLE_STATUSES);

        // Step 3: Phase 5C — request refund from return.
        $this->actingAs($user)
            ->post('/returns/' . $return->id . '/request-refund', [
                'amount' => 100,
                'reason' => 'partial',
            ])
            ->assertRedirect();

        $refund = \App\Models\Refund::firstOrFail();
        $this->assertSame('requested', $refund->status);
        $this->assertSame($return->id, (int) $refund->order_return_id);
    }

    /* ────────────────────── 9. Order Agent role — RBAC for returns ────────────────────── */

    /**
     * Business decision: Order Agents process customer returns on
     * Delivered orders. The professional return flow gates the
     * `Returned` transition (which atomically creates a return record)
     * behind `returns.create`, so the seeded `order-agent` role must
     * carry both `returns.view` and `returns.create` — otherwise the
     * status dropdown silently hides the `Returned` option for them.
     */
    public function test_order_agent_role_has_returns_create_permission(): void
    {
        $role = Role::where('slug', 'order-agent')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();

        $this->assertContains('returns.view', $slugs);
        $this->assertContains('returns.create', $slugs);
        // Scope guard: order agents create/view returns but do NOT
        // approve or inspect them — those stay with manager + warehouse.
        $this->assertNotContains('returns.approve', $slugs);
        $this->assertNotContains('returns.inspect', $slugs);
    }

    public function test_order_agent_can_change_delivered_to_returned_from_show(): void
    {
        $user = $this->userWithSeededRole('order-agent');
        $order = $this->deliveredOrder();

        $this->actingAs($user)
            ->post('/orders/' . $order->id . '/status', [
                'status' => 'Returned',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                    'refund_amount' => 200,
                ],
            ])
            ->assertRedirect();

        $this->assertSame('Returned', $order->fresh()->status);
        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);
        $this->assertSame($this->reason->id, $return->return_reason_id);
    }

    public function test_order_agent_can_change_delivered_to_returned_from_edit(): void
    {
        $user = $this->userWithSeededRole('order-agent');
        $order = $this->deliveredOrder();

        $response = $this->actingAs($user)
            ->put('/orders/' . $order->id, [
                'customer_address' => '1 Order-Agent Street',
                'city' => 'Cairo',
                'country' => 'Egypt',
                'status' => 'Returned',
                'status_note' => 'via edit page by order agent',
                'return' => [
                    'return_reason_id' => $this->reason->id,
                    'product_condition' => 'Good',
                    'refund_amount' => 150,
                ],
            ]);

        $response->assertRedirect();

        $fresh = $order->fresh();
        $this->assertSame('Returned', $fresh->status);
        $this->assertSame('1 Order-Agent Street', $fresh->customer_address);

        $return = OrderReturn::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $return->return_status);
        $response->assertRedirect('/returns/' . $return->id);
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function placedOrder(int $qty = 1): Order
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

    /** Order in Delivered state — ready for Returned transition. */
    private function deliveredOrder(int $qty = 1): Order
    {
        $order = $this->placedOrder($qty);
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->satisfyShippingChecklist($order);
        $this->orderService->changeStatus($order->fresh(), 'Shipped');
        $this->orderService->changeStatus($order->fresh(), 'Delivered');
        return $order->fresh();
    }

    private function satisfyShippingChecklist(Order $order): void
    {
        $company = ShippingCompany::firstOrFail();

        $shipment = Shipment::create([
            'order_id' => $order->id,
            'shipping_company_id' => $company->id,
            'tracking_number' => 'UX-TRK-' . $order->id,
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

    private function userWith(array $slugs): User
    {
        $role = Role::create([
            'name' => 'UX Status Flow ' . uniqid(),
            'slug' => 'ux-status-flow-' . uniqid(),
            'description' => 'Test scope.',
            'is_system' => false,
        ]);
        $ids = Permission::whereIn('slug', $slugs)->pluck('id')->all();
        $role->permissions()->sync($ids);

        return User::create([
            'name' => 'UX Status Flow Test User',
            'email' => 'ux-status+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }

    /**
     * Create a user bound to one of the REAL seeded system roles
     * (e.g. 'order-agent') — as opposed to userWith(), which builds a
     * synthetic role from an explicit slug list. Used to prove the
     * shipped RBAC config, not a hand-picked permission set.
     */
    private function userWithSeededRole(string $slug): User
    {
        $role = Role::where('slug', $slug)->firstOrFail();

        return User::create([
            'name' => 'Seeded Role Test User',
            'email' => 'seeded-role+' . uniqid() . '@hbs.local',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
            'status' => 'Active',
        ]);
    }
}
