<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Expense;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Rolls up a campaign's running totals — orders, revenue, profit, ROAS —
 * from the orders linked through ad_source = campaign and the expenses
 * tagged related_campaign_id = campaign.id.
 *
 * Phase 5 keeps the linkage simple: an order belongs to a campaign when
 * its `source` matches the campaign's name. The full attribution model
 * (UTM, conversion windows) is out of scope until Phase 6+.
 */
class AdCampaignService
{
    /**
     * Recompute and persist the rollup fields on a campaign.
     */
    public function rollup(AdCampaign $campaign): AdCampaign
    {
        return DB::transaction(function () use ($campaign) {
            $start = $campaign->start_date;
            $end = $campaign->end_date ?? now()->endOfDay();

            $ordersStats = Order::query()
                ->where('source', $campaign->name)
                ->when($campaign->product_id, function ($q) use ($campaign) {
                    $q->whereHas('items', fn ($w) => $w->where('product_id', $campaign->product_id));
                })
                ->whereBetween('created_at', [$start, $end])
                ->selectRaw("
                    COUNT(*) AS orders_count,
                    SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) AS delivered_count,
                    SUM(CASE WHEN status = 'Returned' THEN 1 ELSE 0 END) AS returned_count,
                    COALESCE(SUM(CASE WHEN status = 'Delivered' THEN total_amount ELSE 0 END), 0) AS revenue,
                    COALESCE(SUM(CASE WHEN status = 'Delivered' THEN gross_profit ELSE 0 END), 0) AS gross,
                    COALESCE(SUM(CASE WHEN status = 'Delivered' THEN net_profit ELSE 0 END), 0) AS net
                ")
                ->first();

            // Spend: prefer manually-entered campaign.spend if non-zero,
            // otherwise sum expenses tagged to this campaign.
            $expenseSpend = (float) Expense::query()
                ->where('related_campaign_id', $campaign->id)
                ->sum('amount');

            $effectiveSpend = (float) $campaign->spend > 0 ? (float) $campaign->spend : $expenseSpend;

            $orders = (int) ($ordersStats->orders_count ?? 0);
            $revenue = (float) ($ordersStats->revenue ?? 0);
            $costPerOrder = $orders > 0 ? round($effectiveSpend / $orders, 2) : 0;
            $roas = $effectiveSpend > 0 ? round($revenue / $effectiveSpend, 2) : 0;

            $campaign->forceFill([
                'orders_count' => $orders,
                'delivered_orders_count' => (int) ($ordersStats->delivered_count ?? 0),
                'returned_orders_count' => (int) ($ordersStats->returned_count ?? 0),
                'revenue' => round($revenue, 2),
                'gross_profit' => round((float) ($ordersStats->gross ?? 0), 2),
                // Net profit minus campaign spend = real campaign net.
                'net_profit' => round((float) ($ordersStats->net ?? 0) - $effectiveSpend, 2),
                'spend' => round($effectiveSpend, 2),
                'cost_per_order' => $costPerOrder,
                'roas' => $roas,
            ])->save();

            return $campaign->refresh();
        });
    }
}
