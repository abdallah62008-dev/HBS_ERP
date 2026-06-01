<?php

namespace Tests\Feature\Public;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * R3 — Public, signed-URL order tracking.
 *
 * Pins the three security guarantees and the data contract:
 *   - Signed URL → 200, no auth required
 *   - Unsigned / tampered → 403
 *   - Unknown order_number → 404
 *   - Response includes ONLY customer-safe fields (no profit / cost /
 *     marketer / phone / address / internal_notes / item pricing)
 *   - Timeline reflects status history in chronological order
 *   - Timeline rows do not expose operator notes
 */
class PublicTrackingTest extends TestCase
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
            'sku' => 'R3-PUB-001',
            'name' => 'R3 Public Tracking Product',
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
            'name' => 'R3 Public Customer',
            'primary_phone' => '01088886666',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Public Street',
            'created_by' => $this->admin->id,
        ]);

        app(InventoryService::class)->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'R3 fixture opening balance',
        );

        $this->actingAs($this->admin);
    }

    public function test_signed_url_returns_200_without_auth(): void
    {
        $order = $this->placeOrder();
        $url = URL::signedRoute('public.track', ['orderNumber' => $order->order_number]);

        // Log out so the request reaches the public route unauthenticated.
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('PublicTracking')
                ->where('order.order_number', $order->order_number)
                ->where('order.customer_name', $this->customer->name)
                ->has('order.status')
                ->has('timeline')
            );
    }

    public function test_unsigned_url_is_rejected(): void
    {
        $order = $this->placeOrder();
        auth()->logout();

        $this->get('/track/' . $order->order_number)->assertForbidden();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $order = $this->placeOrder();
        $url = URL::signedRoute('public.track', ['orderNumber' => $order->order_number]);
        $tampered = preg_replace('/signature=[^&]+/', 'signature=BAD_SIGNATURE', $url);
        auth()->logout();

        $this->get($tampered)->assertForbidden();
    }

    public function test_unknown_order_number_returns_404_under_valid_signature(): void
    {
        $url = URL::signedRoute('public.track', ['orderNumber' => 'ORD-NONEXISTENT-999']);
        auth()->logout();

        $this->get($url)->assertNotFound();
    }

    public function test_page_does_not_leak_sensitive_fields(): void
    {
        $order = $this->placeOrder();
        $url = URL::signedRoute('public.track', ['orderNumber' => $order->order_number]);
        auth()->logout();

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('order.product_cost_total')
                ->missing('order.gross_profit')
                ->missing('order.net_profit')
                ->missing('order.marketer_id')
                ->missing('order.marketer_profit')
                ->missing('order.internal_notes')
                ->missing('order.customer_phone')
                ->missing('order.customer_address')
                ->missing('order.notes')
            );
    }

    public function test_timeline_includes_status_changes_in_chronological_order(): void
    {
        $order = $this->placeOrder();
        // New -> Confirmed -> On Hold are all legal per R-11 DAG and need
        // no shipping checklist; keeps the test focused on the timeline.
        $this->orderService->changeStatus($order, 'Confirmed');
        $this->orderService->changeStatus($order->fresh(), 'On Hold');

        $url = URL::signedRoute('public.track', ['orderNumber' => $order->order_number]);
        auth()->logout();

        $response = $this->get($url)->assertOk();
        $timeline = $response->viewData('page')['props']['timeline'];

        $this->assertCount(3, $timeline);
        $this->assertSame('New', $timeline[0]['new_status']);
        $this->assertSame('Confirmed', $timeline[1]['new_status']);
        $this->assertSame('On Hold', $timeline[2]['new_status']);
    }

    public function test_timeline_rows_do_not_expose_operator_notes(): void
    {
        $order = $this->placeOrder();
        $this->orderService->changeStatus($order, 'Confirmed', 'private operator note');

        $url = URL::signedRoute('public.track', ['orderNumber' => $order->order_number]);
        auth()->logout();

        $response = $this->get($url)->assertOk();
        $timeline = $response->viewData('page')['props']['timeline'];

        foreach ($timeline as $row) {
            $this->assertArrayNotHasKey('notes', $row,
                'Timeline rows must NOT expose operator notes — may contain internal context.');
        }
    }

    /* ───────────────────── helper ───────────────────── */

    private function placeOrder(): Order
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
                'quantity' => 1,
                'unit_price' => 200,
                'discount_amount' => 0,
            ]],
            'discount_amount' => 0,
            'shipping_amount' => 30,
            'extra_fees' => 0,
        ]);
    }
}
