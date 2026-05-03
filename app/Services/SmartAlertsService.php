<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Collection;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Role;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Generates notifications based on data conditions. Per-condition: if a
 * matching Notification already exists in the last 24h, skip — keeps the
 * inbox tidy.
 *
 * Run on demand via the bell-icon "Refresh alerts" button or a future
 * scheduled job (Laravel scheduler invokes this at the operator's pace).
 *
 * Each method returns the number of NEW notifications created.
 */
class SmartAlertsService
{
    private const DEDUP_WINDOW_HOURS = 24;

    /**
     * Run all alert checks; returns total new notifications created.
     */
    public function runAll(): int
    {
        return $this->lowStock()
            + $this->delayedShipments()
            + $this->highRiskCustomers()
            + $this->unprofitableCampaigns()
            + $this->pendingCollections();
    }

    /**
     * Products at or below reorder_level → notify Warehouse + Manager roles.
     */
    public function lowStock(): int
    {
        $rows = DB::select('
            SELECT p.id, p.sku, p.name, p.reorder_level,
                COALESCE(SUM(CASE WHEN im.movement_type IN ("Purchase","Return To Stock","Opening Balance","Transfer In","Adjustment","Stock Count Correction","Ship","Return Damaged","Transfer Out") THEN im.quantity ELSE 0 END), 0)
                  - COALESCE(SUM(CASE WHEN im.movement_type = "Reserve" THEN im.quantity ELSE 0 END), 0)
                  + COALESCE(SUM(CASE WHEN im.movement_type = "Release Reservation" THEN im.quantity ELSE 0 END), 0) AS available
            FROM products p
            LEFT JOIN inventory_movements im ON im.product_id = p.id
            WHERE p.deleted_at IS NULL AND p.status = "Active"
            GROUP BY p.id, p.sku, p.name, p.reorder_level
            HAVING available <= p.reorder_level AND p.reorder_level > 0
        ');

        $count = 0;
        foreach ($rows as $r) {
            $count += $this->createForRoles(
                roleSlugs: ['warehouse-agent', 'manager', 'admin'],
                type: 'Low Stock',
                title: "Low stock: {$r->name}",
                message: "{$r->sku} has {$r->available} available — at or below reorder level {$r->reorder_level}.",
                actionUrl: '/inventory/low-stock',
                dedupKey: "low-stock-{$r->id}",
            );
        }
        return $count;
    }

    /**
     * Active shipments past their estimated days that haven't delivered.
     */
    public function delayedShipments(): int
    {
        $thresholdDays = (int) SettingsService::get('delay_threshold_days', 7);
        $cutoff = now()->subDays($thresholdDays);

        $shipments = Shipment::query()
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->where('shipping_status', '!=', 'Delayed')
            ->where('assigned_at', '<', $cutoff)
            ->with('order:id,order_number')
            ->limit(50)
            ->get();

        $count = 0;
        foreach ($shipments as $s) {
            $count += $this->createForRoles(
                roleSlugs: ['shipping-agent', 'manager', 'admin'],
                type: 'Delayed Shipment',
                title: "Delayed: {$s->order?->order_number}",
                message: "Tracking {$s->tracking_number} assigned " . $s->assigned_at?->diffForHumans() . ' and not yet delivered.',
                actionUrl: "/shipping/shipments/{$s->id}",
                dedupKey: "delayed-shipment-{$s->id}",
            );
        }
        return $count;
    }

    public function highRiskCustomers(): int
    {
        $customers = Customer::query()
            ->where('risk_level', 'High')
            ->whereHas('orders', function ($q) {
                $q->whereIn('status', Order::OPEN_STATUSES);
            })
            ->limit(20)
            ->get();

        $count = 0;
        foreach ($customers as $c) {
            $count += $this->createForRoles(
                roleSlugs: ['manager', 'admin'],
                type: 'High Risk Customer',
                title: "High-risk customer with open order",
                message: "{$c->name} (risk score {$c->risk_score}) has an open order.",
                actionUrl: "/customers/{$c->id}",
                dedupKey: "high-risk-{$c->id}",
            );
        }
        return $count;
    }

    public function unprofitableCampaigns(): int
    {
        $campaigns = AdCampaign::query()
            ->where('status', 'Active')
            ->where('spend', '>', 0)
            ->whereRaw('net_profit < 0')
            ->limit(20)
            ->get();

        $count = 0;
        foreach ($campaigns as $c) {
            $count += $this->createForRoles(
                roleSlugs: ['manager', 'admin'],
                type: 'Unprofitable Campaign',
                title: "Campaign losing money: {$c->name}",
                message: "Net loss " . number_format((float) $c->net_profit, 2) . " — ROAS {$c->roas}×.",
                actionUrl: "/ads/{$c->id}",
                dedupKey: "campaign-loss-{$c->id}",
            );
        }
        return $count;
    }

    public function pendingCollections(): int
    {
        $stale = Collection::query()
            ->whereIn('collection_status', ['Not Collected', 'Pending Settlement', 'Partially Collected'])
            ->where('created_at', '<', now()->subDays(14))
            ->limit(50)
            ->get();

        if ($stale->isEmpty()) return 0;

        $totalDue = $stale->sum(fn ($c) => (float) $c->amount_due - (float) $c->amount_collected);

        return $this->createForRoles(
            roleSlugs: ['accountant', 'manager', 'admin'],
            type: 'Pending Collection',
            title: "{$stale->count()} stale collections",
            message: "Outstanding > 14 days totalling " . number_format($totalDue, 2) . '.',
            actionUrl: '/collections',
            dedupKey: 'pending-collections-rollup',
        );
    }

    /**
     * Record an Approval Needed notification — called by other services
     * (e.g. ProfitGuardService when action=approve, StockAdjustmentsController
     * when a request is submitted). Returns the notification.
     */
    public function notifyApprovalNeeded(string $title, string $message, ?string $actionUrl = null): Notification
    {
        return Notification::create([
            'user_id' => null,
            'role_id' => Role::where('slug', 'manager')->value('id'),
            'title' => $title,
            'message' => $message,
            'type' => 'Approval Needed',
            'action_url' => $actionUrl,
            'created_at' => now(),
        ]);
    }

    /**
     * Insert one notification per role. Returns count actually created
     * (0 when deduped within DEDUP_WINDOW_HOURS).
     *
     * @param  array<int,string>  $roleSlugs
     */
    private function createForRoles(
        array $roleSlugs,
        string $type,
        string $title,
        string $message,
        ?string $actionUrl,
        string $dedupKey,
    ): int {
        $cutoff = now()->subHours(self::DEDUP_WINDOW_HOURS);

        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id');

        $created = 0;
        foreach ($roleIds as $roleId) {
            $exists = Notification::query()
                ->where('role_id', $roleId)
                ->where('type', $type)
                ->where('title', $title)
                ->where('created_at', '>=', $cutoff)
                ->exists();

            if ($exists) continue;

            Notification::create([
                'user_id' => null,
                'role_id' => $roleId,
                'title' => $title,
                'message' => $message,
                'type' => $type,
                'action_url' => $actionUrl,
                'created_at' => now(),
            ]);
            $created++;
        }

        return $created;
    }
}
