<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryService;
use App\Services\OrderService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R10 — Reservation TTL sweep.
 *
 * Pins the `inventory:release-stale-reservations` artisan command: it
 * releases reservations for Confirmed orders past the configured TTL,
 * leaves the order's status untouched, is idempotent, and respects both
 * the SettingsService default and the per-run --days override.
 */
class ReleaseStaleReservationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Warehouse $warehouse;
    private Product $product;
    private Customer $customer;
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
        $this->actingAs($this->admin);

        $this->product = Product::create([
            'sku' => 'R10-TTL-001',
            'name' => 'R10 TTL Test SKU',
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
            'name' => 'R10 TTL Customer',
            'primary_phone' => '01044443333',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 TTL Street',
            'created_by' => $this->admin->id,
        ]);

        // Opening balance so reservations always succeed.
        $this->inventory->record(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            movementType: 'Opening Balance',
            signedQuantity: 100,
            unitCost: 50,
            notes: 'R10 fixture opening balance',
        );
    }

    public function test_releases_reservations_on_stale_confirmed_orders(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 3);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame(0, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ), 'Stale reservation should be released to zero.');

        $this->assertSame(1, InventoryMovement::where('reference_id', $order->id)
            ->where('reference_type', Order::class)
            ->where('movement_type', 'Release Reservation')
            ->count());
    }

    public function test_keeps_reservations_within_ttl(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 7, qty: 2);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame(2, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ), '7-day-old reservation must survive a 14-day TTL.');
    }

    public function test_ignores_non_confirmed_orders(): void
    {
        // Reserve stock against an order that never reached `Confirmed`
        // (edge case — only Confirmed orders are in scope for the sweep).
        $order = $this->placeOrder(2);
        $this->inventory->reserve(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            quantity: 2,
            reference: $order,
            notes: 'Edge fixture: reserved without Confirmed',
        );
        $order->forceFill(['confirmed_at' => Carbon::now()->subDays(60)])->save();

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame(2, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ), 'Non-Confirmed order must be skipped even with an old confirmed_at.');
    }

    public function test_uses_settings_ttl_when_no_option_given(): void
    {
        SettingsService::set('reservation_ttl_days', 30, 'inventory', 'number');
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 1);

        $this->artisan('inventory:release-stale-reservations')->assertSuccessful();

        // 20 days < 30-day settings TTL → still reserved.
        $this->assertSame(1, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ));
    }

    public function test_command_option_overrides_settings_ttl(): void
    {
        SettingsService::set('reservation_ttl_days', 30, 'inventory', 'number');
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 15, qty: 1);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 10])
            ->assertSuccessful();

        // 15 days > 10-day override → released, even though setting is 30.
        $this->assertSame(0, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ));
    }

    public function test_order_status_remains_confirmed_after_release(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 2);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame(
            'Confirmed',
            $order->fresh()->status,
            'R10 releases stock only — the order keeps its Confirmed status.',
        );
    }

    public function test_dry_run_writes_no_movement_or_audit_row(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 4);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(4, $this->inventory->reservationFor(
            $this->product->id, null, $this->warehouse->id, $order,
        ));
        $this->assertSame(0, InventoryMovement::where('reference_id', $order->id)
            ->where('movement_type', 'Release Reservation')->count());
        $this->assertSame(0, AuditLog::where('module', 'inventory')
            ->where('action', 'reservation_released_stale')->count());
    }

    public function test_writes_audit_log_per_order_released(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 2);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame(1, AuditLog::where('module', 'inventory')
            ->where('action', 'reservation_released_stale')
            ->where('record_type', Order::class)
            ->where('record_id', $order->id)->count());
    }

    public function test_is_idempotent_second_run_writes_nothing(): void
    {
        $order = $this->makeConfirmedOrderWithReservation(daysAgo: 20, qty: 3);

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $movementsAfterFirst = InventoryMovement::where('reference_id', $order->id)->count();
        $auditAfterFirst = AuditLog::where('module', 'inventory')
            ->where('action', 'reservation_released_stale')->count();

        $this->artisan('inventory:release-stale-reservations', ['--days' => 14])
            ->assertSuccessful();

        $this->assertSame($movementsAfterFirst, InventoryMovement::where('reference_id', $order->id)->count(),
            'Second sweep on already-released orders must write no new inventory movement.');
        $this->assertSame($auditAfterFirst, AuditLog::where('module', 'inventory')
            ->where('action', 'reservation_released_stale')->count(),
            'Second sweep must not write a duplicate audit row when nothing was released.');
    }

    /* ───────────────────────── helpers ───────────────────────── */

    private function placeOrder(int $qty): Order
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

    /**
     * Build a Confirmed order with a real Reserve movement at the
     * default warehouse, then backdate `confirmed_at` so the sweep can
     * pick it up. Reserves directly via InventoryService so the test
     * never depends on changeStatus() side-effects.
     */
    private function makeConfirmedOrderWithReservation(int $daysAgo, int $qty): Order
    {
        $order = $this->placeOrder($qty);

        $this->inventory->reserve(
            productId: $this->product->id,
            variantId: null,
            warehouseId: $this->warehouse->id,
            quantity: $qty,
            reference: $order,
            notes: 'R10 fixture reservation',
        );

        $order->forceFill([
            'status' => 'Confirmed',
            'confirmed_at' => Carbon::now()->subDays($daysAgo),
        ])->save();

        return $order->fresh();
    }
}
