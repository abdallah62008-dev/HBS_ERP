<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\AuditLogService;
use App\Services\InventoryService;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * R10 — Release stock reservations on orders stuck in `Confirmed`
 * status beyond a configurable TTL.
 *
 * When an order is confirmed, OrderService reserves stock for every line
 * item. If that order then sits unshipped for an operationally
 * unreasonable window (e.g. 14 days), the reserved stock is silently
 * starving newer orders. This command sweeps such "stale" reservations
 * and releases them. The order itself stays in `Confirmed` — the
 * operator can still decide to ship or cancel it; we just stop holding
 * stock for it.
 *
 * Idempotent: a second run on the same data writes nothing new, because
 * `InventoryService::reservationFor($order)` returns 0 after the first
 * release.
 *
 * Defaults:
 *   - TTL: SettingsService('reservation_ttl_days', 14)
 *   - Schedule: nightly (see routes/console.php)
 *
 * Usage:
 *   php artisan inventory:release-stale-reservations
 *   php artisan inventory:release-stale-reservations --days=21
 *   php artisan inventory:release-stale-reservations --dry-run
 */
class ReleaseStaleReservations extends Command
{
    protected $signature = 'inventory:release-stale-reservations
        {--days= : Override TTL in days (otherwise reads reservation_ttl_days setting, default 14)}
        {--dry-run : Report what would be released without writing}';

    protected $description = 'R10 — release stale stock reservations on Confirmed orders past TTL.';

    public function handle(InventoryService $inventory): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $ttlDays = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) SettingsService::get('reservation_ttl_days', 14);

        if ($ttlDays <= 0) {
            $this->error("TTL must be > 0 days (got {$ttlDays}).");
            return self::FAILURE;
        }

        $cutoff = now()->subDays($ttlDays);
        $this->info(sprintf(
            'R10 reservation TTL sweep — TTL: %d days · cutoff: %s · dry-run: %s',
            $ttlDays,
            $cutoff->toDateTimeString(),
            $dryRun ? 'yes' : 'no',
        ));

        $warehouse = $inventory->defaultWarehouse();
        if (! $warehouse) {
            $this->warn('No default warehouse configured — nothing to release.');
            return self::SUCCESS;
        }

        $stale = Order::query()
            ->where('status', 'Confirmed')
            ->whereNotNull('confirmed_at')
            ->where('confirmed_at', '<', $cutoff)
            ->with('items:id,order_id,product_id,product_variant_id,quantity')
            ->get();

        $this->info("Found {$stale->count()} stale Confirmed order(s) to scan.");

        $ordersReleased = 0;
        $movementsWritten = 0;
        $totalQty = 0;

        foreach ($stale as $order) {
            $orderReleasesQty = 0;
            $orderReleasesCount = 0;

            foreach ($order->items as $item) {
                $reserved = $inventory->reservationFor(
                    productId: (int) $item->product_id,
                    variantId: $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                    warehouseId: $warehouse->id,
                    reference: $order,
                );
                if ($reserved <= 0) {
                    continue;
                }

                $orderReleasesQty += $reserved;
                $orderReleasesCount++;

                if (! $dryRun) {
                    $inventory->releaseReservation(
                        productId: (int) $item->product_id,
                        variantId: $item->product_variant_id !== null ? (int) $item->product_variant_id : null,
                        warehouseId: $warehouse->id,
                        quantity: $reserved,
                        reference: $order,
                        notes: "Order {$order->order_number} — stale reservation auto-released after {$ttlDays} days",
                    );
                }
            }

            if ($orderReleasesCount > 0) {
                $ordersReleased++;
                $movementsWritten += $orderReleasesCount;
                $totalQty += $orderReleasesQty;

                if (! $dryRun) {
                    AuditLogService::log(
                        action: 'reservation_released_stale',
                        module: 'inventory',
                        recordType: Order::class,
                        recordId: $order->id,
                        oldValues: null,
                        newValues: [
                            'ttl_days' => $ttlDays,
                            'items_released' => $orderReleasesCount,
                            'total_qty_released' => $orderReleasesQty,
                        ],
                    );
                }
            }
        }

        $this->info($dryRun
            ? "Would release {$movementsWritten} movement(s) across {$ordersReleased} order(s) (total qty {$totalQty})."
            : "Released {$movementsWritten} movement(s) across {$ordersReleased} order(s) (total qty {$totalQty})."
        );

        return self::SUCCESS;
    }
}
