<?php

namespace Tests\Feature\Orders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R11 — Orders/Show must expose the legal forward transitions for the
 * order's current status so the Change Status modal can render only those
 * options (illegal jumps never appear in the dropdown).
 *
 * This is the UX layer of R11. The enforcement layer — the DAG gate in
 * OrderService::changeStatus — is covered by OrderTransitionDagTest. The
 * `allowed_transitions` prop is convenience only; the gate is the
 * backstop.
 */
class OrderShowAllowedTransitionsTest extends TestCase
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

        $this->product = Product::create([
            'sku' => 'R11-SHOW-001',
            'name' => 'R11 Show Test SKU',
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
            'name' => 'R11 Show Test Customer',
            'primary_phone' => '01088887777',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Show Street',
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin);
    }

    public function test_show_exposes_allowed_transitions_for_a_new_order(): void
    {
        $order = $this->orderAt('New');

        $this->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Orders/Show')
                ->where('allowed_transitions', Order::ALLOWED_TRANSITIONS['New'])
            );
    }

    public function test_show_exposes_allowed_transitions_for_a_shipped_order(): void
    {
        $order = $this->orderAt('Shipped');

        $this->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allowed_transitions', Order::ALLOWED_TRANSITIONS['Shipped'])
            );
    }

    public function test_show_exposes_empty_allowed_transitions_for_a_terminal_order(): void
    {
        // Returned is terminal — the dropdown will show only the current
        // status (the frontend prepends it as the no-op baseline).
        $order = $this->orderAt('Returned');

        $this->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('allowed_transitions', [])
            );
    }

    public function test_allowed_transitions_prop_never_contains_an_illegal_jump(): void
    {
        foreach (['New', 'Confirmed', 'Packed', 'Shipped', 'Delivered', 'On Hold'] as $status) {
            $order = $this->orderAt($status);

            $response = $this->get('/orders/'.$order->id)->assertOk();
            $targets = $response->viewData('page')['props']['allowed_transitions'];

            foreach ($targets as $target) {
                $this->assertTrue(
                    Order::isLegalTransition($status, $target),
                    "allowed_transitions for '{$status}' contained illegal target '{$target}'."
                );
            }
        }
    }

    public function test_allowed_transitions_prop_excludes_the_current_status(): void
    {
        // The backend sends pure DAG targets; the current status is added
        // by the frontend, never by the controller. A status is never in
        // its own outgoing-edge list.
        $order = $this->orderAt('Confirmed');

        $response = $this->get('/orders/'.$order->id)->assertOk();
        $targets = $response->viewData('page')['props']['allowed_transitions'];

        $this->assertNotContains('Confirmed', $targets);
    }

    /* ───────────────────────── helper ───────────────────────── */

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
