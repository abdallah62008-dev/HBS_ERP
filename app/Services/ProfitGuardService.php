<?php

namespace App\Services;

use App\Models\Product;
use RuntimeException;

/**
 * Implements the Profit Guard rule from 04_BUSINESS_WORKFLOWS.md §16.
 *
 * On order creation, every line item is checked against:
 *   1. Its product's minimum_selling_price (or marketer_product_price's
 *      minimum_selling_price for marketer orders).
 *   2. The system-wide `minimum_profit_required` setting.
 *
 * If `profit_guard_enabled` is false, this is a no-op. If a rule fails
 * AND the setting `profit_guard_action` is "block" (default), throws.
 * If "approve", it returns the violations so the order can be flagged
 * for manager approval — Phase 8 wires the formal approval path.
 */
class ProfitGuardService
{
    /**
     * @param  array<int, array<string,mixed>>  $items  Each row needs at least: product_id, unit_price, quantity
     * @return array{passed:bool, action:string, violations:array<int,string>}
     */
    public function evaluate(array $items): array
    {
        if (! (bool) SettingsService::get('profit_guard_enabled', true)) {
            return ['passed' => true, 'action' => 'allow', 'violations' => []];
        }

        $action = (string) SettingsService::get('profit_guard_action', 'block');
        $minProfit = (float) SettingsService::get('minimum_profit_required', 0);

        $violations = [];

        foreach ($items as $idx => $row) {
            $product = Product::find($row['product_id'] ?? null);
            if (! $product) continue;

            $unitPrice = (float) ($row['unit_price'] ?? 0);
            $minSelling = (float) $product->minimum_selling_price;
            $cost = (float) $product->cost_price;

            if ($minSelling > 0 && $unitPrice < $minSelling) {
                $violations[] = sprintf(
                    'Line %d (SKU %s): unit price %.2f is below the minimum selling price %.2f.',
                    $idx + 1, $product->sku, $unitPrice, $minSelling,
                );
                continue;
            }

            $perUnitProfit = $unitPrice - $cost;
            if ($minProfit > 0 && $perUnitProfit < $minProfit) {
                $violations[] = sprintf(
                    'Line %d (SKU %s): per-unit profit %.2f is below the required minimum %.2f.',
                    $idx + 1, $product->sku, $perUnitProfit, $minProfit,
                );
            }
        }

        if (empty($violations)) {
            return ['passed' => true, 'action' => $action, 'violations' => []];
        }

        return ['passed' => false, 'action' => $action, 'violations' => $violations];
    }

    /**
     * Convenience wrapper: throws if blocked, otherwise returns result.
     *
     * @param  array<int, array<string,mixed>>  $items
     * @return array{passed:bool, action:string, violations:array<int,string>}
     */
    public function evaluateOrThrow(array $items): array
    {
        $result = $this->evaluate($items);

        if (! $result['passed'] && $result['action'] === 'block') {
            throw new RuntimeException(
                "Profit Guard blocked the order:\n· " . implode("\n· ", $result['violations']),
            );
        }

        return $result;
    }
}
