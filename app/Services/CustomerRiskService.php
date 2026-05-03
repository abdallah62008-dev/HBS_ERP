<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;

/**
 * Computes a customer risk score 0–100 from order history per
 * 04_BUSINESS_WORKFLOWS.md §5.
 *
 * Levels:
 *   0–30  Low
 *   31–70 Medium
 *   71–100 High
 *
 * Inputs taken into account in Phase 2:
 *   + delivered orders   (positive — pulls score down)
 *   + returns            (negative)
 *   + cancellations      (negative)
 *   + failed deliveries  (negative — proxy: returned without delivered_at)
 *   + repeated addresses with different names (mild negative)
 *   + blacklist flag     (forces 100)
 *
 * Complaints, high-risk-city, and "high order value" knobs land in later
 * phases as those modules ship; the scoring here leaves room for them
 * (the score is built additively and capped, so adding more rules later
 * doesn't break anything).
 *
 * After calculate(), call refreshFor() to persist the new score and
 * derived risk_level on the customer row.
 */
class CustomerRiskService
{
    public const LEVEL_LOW = 'Low';
    public const LEVEL_MEDIUM = 'Medium';
    public const LEVEL_HIGH = 'High';

    // Per-event weights. Tweak these in one place.
    private const PENALTY_RETURN = 12;
    private const PENALTY_CANCEL = 6;
    private const PENALTY_FAILED_DELIVERY = 10;
    private const REWARD_DELIVERED = 4;       // subtracted from score
    private const PENALTY_REPEATED_ADDRESS = 8;

    /**
     * @return array{score:int, level:string, breakdown:array<string,int>}
     */
    public function calculate(Customer $customer): array
    {
        if ($customer->isBlacklisted()) {
            return [
                'score' => 100,
                'level' => self::LEVEL_HIGH,
                'breakdown' => ['blacklist' => 100],
            ];
        }

        // Single aggregate query — cheaper than 4 separate count() calls.
        $stats = Order::query()
            ->where('customer_id', $customer->id)
            ->selectRaw("
                SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN status = 'Returned' THEN 1 ELSE 0 END) AS returned,
                SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN status = 'Returned' AND delivered_at IS NULL THEN 1 ELSE 0 END) AS failed_delivery
            ")
            ->first();

        $delivered = (int) ($stats->delivered ?? 0);
        $returned = (int) ($stats->returned ?? 0);
        $cancelled = (int) ($stats->cancelled ?? 0);
        $failedDelivery = (int) ($stats->failed_delivery ?? 0);

        // Repeated address with different names — same delivery address served
        // multiple distinct customer_name values (could indicate fraud rings
        // or address re-use).
        $addressClashes = Order::query()
            ->whereRaw('LOWER(TRIM(customer_address)) = LOWER(TRIM(?))', [$customer->default_address])
            ->whereNotNull('customer_address')
            ->where('customer_address', '!=', '')
            ->where('customer_id', '!=', $customer->id)
            ->distinct()
            ->count('customer_name');

        $score = 0;
        $breakdown = [];

        if ($returned > 0) {
            $delta = $returned * self::PENALTY_RETURN;
            $score += $delta;
            $breakdown['returns'] = $delta;
        }

        if ($cancelled > 0) {
            $delta = $cancelled * self::PENALTY_CANCEL;
            $score += $delta;
            $breakdown['cancellations'] = $delta;
        }

        if ($failedDelivery > 0) {
            $delta = $failedDelivery * self::PENALTY_FAILED_DELIVERY;
            $score += $delta;
            $breakdown['failed_deliveries'] = $delta;
        }

        if ($addressClashes > 0) {
            $delta = min($addressClashes, 3) * self::PENALTY_REPEATED_ADDRESS;
            $score += $delta;
            $breakdown['repeated_address'] = $delta;
        }

        if ($delivered > 0) {
            $delta = min($delivered, 10) * self::REWARD_DELIVERED;
            $score = max(0, $score - $delta);
            $breakdown['delivered_history'] = -$delta;
        }

        $score = max(0, min(100, $score));

        return [
            'score' => $score,
            'level' => self::levelFor($score),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Compute and persist on the customer row. Returns the updated arrays.
     */
    public function refreshFor(Customer $customer): array
    {
        $result = $this->calculate($customer);

        $customer->forceFill([
            'risk_score' => $result['score'],
            'risk_level' => $result['level'],
        ])->saveQuietly();

        return $result;
    }

    public static function levelFor(int $score): string
    {
        return match (true) {
            $score >= 71 => self::LEVEL_HIGH,
            $score >= 31 => self::LEVEL_MEDIUM,
            default => self::LEVEL_LOW,
        };
    }
}
