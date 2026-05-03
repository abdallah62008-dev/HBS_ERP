<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Detects whether an in-progress order looks like a duplicate of one
 * already in the system. Implements rules from 04_BUSINESS_WORKFLOWS.md §4.
 *
 * Returns a score from 0 to 100 plus a list of human-readable reasons
 * the controller / UI can show in a warning. Higher score = more likely
 * to be a duplicate.
 *
 * Scoring weights are deliberate, additive, and capped at 100. Tweak in
 * one place if the business decides a rule is over- or under-weighted.
 */
class DuplicateDetectionService
{
    /** Same primary phone in last N days */
    private const SAME_PHONE_DAYS = 7;
    private const W_SAME_PHONE_RECENT = 60;

    /** Same secondary phone OR same primary-as-secondary swap */
    private const W_SECONDARY_PHONE_MATCH = 30;

    /** Same name + city in last N days */
    private const SAME_NAME_CITY_DAYS = 14;
    private const W_SAME_NAME_CITY = 25;

    /** Same address (case-insensitive trim equality) */
    private const W_SAME_ADDRESS = 20;

    /** Same product within short window */
    private const SAME_PRODUCT_DAYS = 3;
    private const W_SAME_PRODUCT_RECENT = 25;

    /** Same customer already has an open order */
    private const W_OPEN_ORDER_EXISTS = 20;

    /**
     * @param  array{
     *   primary_phone?: string|null,
     *   secondary_phone?: string|null,
     *   customer_name?: string|null,
     *   city?: string|null,
     *   customer_address?: string|null,
     *   product_ids?: array<int,int>,
     *   customer_id?: int|null,
     * }  $payload
     * @return array{score:int, reasons:array<int,string>, related_order_ids:array<int,int>, related_customer_ids:array<int,int>}
     */
    public function evaluate(array $payload): array
    {
        $score = 0;
        $reasons = [];
        $relatedOrderIds = collect();
        $relatedCustomerIds = collect();

        $phone = self::normalisePhone($payload['primary_phone'] ?? null);
        $secondaryPhone = self::normalisePhone($payload['secondary_phone'] ?? null);
        $name = trim((string) ($payload['customer_name'] ?? ''));
        $city = trim((string) ($payload['city'] ?? ''));
        $address = self::normaliseAddress($payload['customer_address'] ?? null);
        $productIds = collect($payload['product_ids'] ?? [])->filter()->unique();

        // ── Rule 1: same primary phone, recent ──────────────────────────
        if ($phone) {
            $recent = Order::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') = ?", [$phone])
                ->where('created_at', '>=', Carbon::now()->subDays(self::SAME_PHONE_DAYS))
                ->limit(20)
                ->pluck('id');

            if ($recent->isNotEmpty()) {
                $score += self::W_SAME_PHONE_RECENT;
                $reasons[] = "Same phone used in another order within the last "
                    . self::SAME_PHONE_DAYS . ' days';
                $relatedOrderIds = $relatedOrderIds->merge($recent);
            }
        }

        // ── Rule 2: secondary-phone overlap or swap ─────────────────────
        if ($phone || $secondaryPhone) {
            $candidates = collect();
            if ($secondaryPhone) {
                $candidates = $candidates->merge(
                    Customer::query()
                        ->whereRaw("REPLACE(REPLACE(REPLACE(primary_phone, ' ', ''), '-', ''), '+', '') = ?", [$secondaryPhone])
                        ->orWhereRaw("REPLACE(REPLACE(REPLACE(secondary_phone, ' ', ''), '-', ''), '+', '') = ?", [$secondaryPhone])
                        ->pluck('id')
                );
            }
            if ($phone) {
                $candidates = $candidates->merge(
                    Customer::query()
                        ->whereRaw("REPLACE(REPLACE(REPLACE(secondary_phone, ' ', ''), '-', ''), '+', '') = ?", [$phone])
                        ->pluck('id')
                );
            }
            $candidates = $candidates->unique();

            if ($candidates->isNotEmpty()) {
                $score += self::W_SECONDARY_PHONE_MATCH;
                $reasons[] = 'Phone matches a secondary phone on an existing customer';
                $relatedCustomerIds = $relatedCustomerIds->merge($candidates);
            }
        }

        // ── Rule 3: same name + city recent ─────────────────────────────
        if ($name !== '' && $city !== '') {
            $similar = Order::query()
                ->where('customer_name', $name)
                ->where('city', $city)
                ->where('created_at', '>=', Carbon::now()->subDays(self::SAME_NAME_CITY_DAYS))
                ->limit(20)
                ->pluck('id');

            if ($similar->isNotEmpty()) {
                $score += self::W_SAME_NAME_CITY;
                $reasons[] = 'Same name + city seen in a recent order';
                $relatedOrderIds = $relatedOrderIds->merge($similar);
            }
        }

        // ── Rule 4: same address ────────────────────────────────────────
        if ($address !== '') {
            $sameAddr = Order::query()
                ->whereRaw('LOWER(TRIM(customer_address)) = ?', [$address])
                ->where('created_at', '>=', Carbon::now()->subDays(30))
                ->limit(20)
                ->pluck('id');

            if ($sameAddr->isNotEmpty()) {
                $score += self::W_SAME_ADDRESS;
                $reasons[] = 'Same delivery address used in another recent order';
                $relatedOrderIds = $relatedOrderIds->merge($sameAddr);
            }
        }

        // ── Rule 5: same product to same phone, very recent ─────────────
        if ($phone && $productIds->isNotEmpty()) {
            $sameProduct = Order::query()
                ->whereRaw("REPLACE(REPLACE(REPLACE(customer_phone, ' ', ''), '-', ''), '+', '') = ?", [$phone])
                ->where('created_at', '>=', Carbon::now()->subDays(self::SAME_PRODUCT_DAYS))
                ->whereHas('items', fn ($q) => $q->whereIn('product_id', $productIds))
                ->limit(10)
                ->pluck('id');

            if ($sameProduct->isNotEmpty()) {
                $score += self::W_SAME_PRODUCT_RECENT;
                $reasons[] = 'Same phone bought the same product in the last '
                    . self::SAME_PRODUCT_DAYS . ' days';
                $relatedOrderIds = $relatedOrderIds->merge($sameProduct);
            }
        }

        // ── Rule 6: customer already has an open order ──────────────────
        if (! empty($payload['customer_id'])) {
            $hasOpen = Order::query()
                ->where('customer_id', $payload['customer_id'])
                ->whereIn('status', Order::OPEN_STATUSES)
                ->exists();

            if ($hasOpen) {
                $score += self::W_OPEN_ORDER_EXISTS;
                $reasons[] = 'This customer already has an open order';
            }
        }

        return [
            'score' => min(100, $score),
            'reasons' => array_values(array_unique($reasons)),
            'related_order_ids' => $relatedOrderIds->unique()->values()->all(),
            'related_customer_ids' => $relatedCustomerIds->unique()->values()->all(),
        ];
    }

    /**
     * Strip spaces, dashes, and a leading `+` so phone comparisons are robust.
     */
    public static function normalisePhone(?string $phone): string
    {
        if ($phone === null || $phone === '') {
            return '';
        }

        return preg_replace('/[\s\-+]/', '', $phone) ?? '';
    }

    public static function normaliseAddress(?string $address): string
    {
        if ($address === null) {
            return '';
        }

        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $address) ?? ''));
    }
}
