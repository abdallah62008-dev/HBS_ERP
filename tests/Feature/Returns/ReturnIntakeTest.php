<?php

namespace Tests\Feature\Returns;

use App\Models\CashboxTransaction;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\Permission;
use App\Models\Refund;
use App\Models\ReturnReason;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Returns Phase 2 — Return Intake & RMA Standards.
 *
 * The change-status intake path (Orders/Show modal + Orders/Edit) is
 * already pinned by `tests/Feature/Orders/ReturnFromStatusChangeTest.php`
 * (permission gates, reason required, atomic creation, redirect, duplicate
 * block, no refund/cashbox side effects). This file fills the gaps for
 * the *direct* intake path (`POST /returns` from `/returns/create`) and
 * pins the new RMA `display_reference` convention.
 *
 * Specifically pinned:
 *   - Direct POST without `return_reason_id` is rejected with a 422 and
 *     no row is written.
 *   - Direct POST against an order that already has a return is rejected
 *     by the friendly-error closure on `OrderReturnRequest`, and the
 *     original return is preserved unmodified.
 *   - Direct POST success redirects to `/returns/{new_id}`.
 *   - `GET /returns/create?order_id=X` for an already-returned order
 *     surfaces the existing-return id so the page can link to it.
 *   - The direct POST path does NOT create a `Refund` or a
 *     `cashbox_transactions` row (mirror of the change-status pinning).
 *   - `display_reference` is exposed on the OrderReturn model and on
 *     the show endpoint's `return` prop.
 */
class ReturnIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Customer $customer;
    private ReturnReason $reason;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
        $this->reason = ReturnReason::firstOrFail();
        $this->customer = Customer::create([
            'name' => 'Intake Test Customer',
            'primary_phone' => '01099995555',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Intake Street',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ────────────────────── 1. Reason required on direct POST ────────────────────── */

    public function test_direct_post_without_return_reason_is_rejected_and_writes_no_row(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post('/returns', [
                'order_id' => $order->id,
                // return_reason_id intentionally omitted
            ])
            ->assertSessionHasErrors(['return_reason_id']);

        $this->assertSame(0, OrderReturn::count(),
            'No return row may be written when validation fails on the direct intake path.'
        );
    }

    /* ────────────────────── 2. Duplicate-return guard on direct POST ────────────────────── */

    public function test_direct_post_for_already_returned_order_is_rejected_with_friendly_error(): void
    {
        $order = $this->makeOrder();
        $existing = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $originalNotes = $existing->notes;
        $originalStatus = $existing->return_status;

        $response = $this->actingAs($this->admin)
            ->post('/returns', [
                'order_id' => $order->id,
                'return_reason_id' => $this->reason->id,
                'notes' => 'attempt to overwrite',
            ]);

        $response->assertSessionHasErrors(['order_id']);
        $errors = session('errors');
        $this->assertNotNull($errors);
        $this->assertStringContainsString(
            'already has a return record',
            $errors->first('order_id'),
            'The friendly duplicate-return error message must be returned verbatim from OrderReturnRequest.'
        );

        // No second row was created.
        $this->assertSame(1, OrderReturn::where('order_id', $order->id)->count(),
            'A duplicate-return attempt must NOT create a second row.'
        );

        // The original return is untouched.
        $existing->refresh();
        $this->assertSame($originalNotes, $existing->notes);
        $this->assertSame($originalStatus, $existing->return_status);
    }

    /* ────────────────────── 3. Direct POST success redirects to /returns/{id} ────────────────────── */

    public function test_direct_post_success_redirects_to_the_new_return_show_page(): void
    {
        $order = $this->makeOrder();

        $response = $this->actingAs($this->admin)
            ->post('/returns', [
                'order_id' => $order->id,
                'return_reason_id' => $this->reason->id,
                'product_condition' => 'Unknown',
                'refund_amount' => 50,
            ]);

        $return = OrderReturn::firstOrFail();
        $response->assertRedirect('/returns/' . $return->id);
        $this->assertSame('Pending', $return->return_status);
        $this->assertSame($order->id, $return->order_id);
        $this->assertSame($this->admin->id, $return->created_by);
    }

    /* ────────────────────── 4. /returns/create surfaces the existing-return id for already-returned orders ────────────────────── */

    public function test_returns_create_page_exposes_existing_return_id_for_already_returned_order(): void
    {
        $order = $this->makeOrder();
        $existing = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/returns/create?order_id=' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Create')
                ->where('preselected_order', null)
                ->where('existing_return_id', $existing->id)
                ->where('already_returned_notice', fn ($v) => is_string($v) && str_contains($v, 'already has a return record'))
            );
    }

    public function test_returns_create_page_with_clean_order_does_not_set_existing_return_id(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->get('/returns/create?order_id=' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Create')
                ->where('existing_return_id', null)
                ->where('already_returned_notice', null)
                ->where('preselected_order.id', $order->id)
            );
    }

    /* ────────────────────── 5. Direct POST does not create a Refund or cashbox row ────────────────────── */

    public function test_direct_post_does_not_create_a_refund(): void
    {
        $order = $this->makeOrder();

        $this->actingAs($this->admin)
            ->post('/returns', [
                'order_id' => $order->id,
                'return_reason_id' => $this->reason->id,
                'refund_amount' => 200, // intent only
            ])
            ->assertRedirect();

        $this->assertSame(0, Refund::count(),
            'A return with a non-zero refund_amount must not auto-create a Refund row.'
        );
    }

    public function test_direct_post_does_not_create_a_cashbox_transaction(): void
    {
        $order = $this->makeOrder();
        $cashboxRowsBefore = CashboxTransaction::count();

        $this->actingAs($this->admin)
            ->post('/returns', [
                'order_id' => $order->id,
                'return_reason_id' => $this->reason->id,
                'refund_amount' => 300,
                'shipping_loss_amount' => 30,
            ])
            ->assertRedirect();

        $this->assertSame($cashboxRowsBefore, CashboxTransaction::count(),
            'Neither refund_amount nor shipping_loss_amount on intake may post to a cashbox.'
        );
        $this->assertSame(0, CashboxTransaction::where('source_type', 'refund')->count());
    }

    /* ────────────────────── 6. RMA display_reference convention ────────────────────── */

    public function test_display_reference_is_padded_six_digits_with_ret_prefix(): void
    {
        $order = $this->makeOrder();
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        // Accessor — RET- + 6-digit zero-padded id.
        $expected = 'RET-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT);
        $this->assertSame($expected, $return->display_reference);

        // Auto-appended on serialise (the `$appends` array).
        $serialised = $return->toArray();
        $this->assertArrayHasKey('display_reference', $serialised);
        $this->assertSame($expected, $serialised['display_reference']);
    }

    public function test_returns_show_endpoint_exposes_display_reference_in_props(): void
    {
        $order = $this->makeOrder();
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/returns/' . $return->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Returns/Show')
                ->where('return.display_reference', 'RET-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT))
            );
    }

    public function test_orders_show_existing_return_prop_exposes_display_reference(): void
    {
        $order = $this->makeOrder();
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'return_reason_id' => $this->reason->id,
            'return_status' => 'Pending',
            'product_condition' => 'Unknown',
            'refund_amount' => 0,
            'shipping_loss_amount' => 0,
            'restockable' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        // The Orders/Show controller serialises `existing_return` with
        // only ['id', 'return_status', 'product_condition'] columns —
        // but accessors in `$appends` are still appended by Eloquent
        // toArray, so display_reference must come through.
        $this->actingAs($this->admin)
            ->get('/orders/' . $order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('existing_return.id', $return->id)
                ->where('existing_return.display_reference', 'RET-' . str_pad((string) $return->id, 6, '0', STR_PAD_LEFT))
            );
    }

    /* ────────────────────── Helpers ────────────────────── */

    private function makeOrder(): Order
    {
        static $counter = 0;
        $counter++;
        return Order::create([
            'order_number' => 'INT-TEST-' . str_pad((string) $counter, 6, '0', STR_PAD_LEFT),
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
            'total_amount' => 200,
            'created_by' => $this->admin->id,
        ]);
    }
}
