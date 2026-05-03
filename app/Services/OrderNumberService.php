<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Generates unique order numbers in the format defined by 02_DATABASE_SCHEMA.md:
 *
 *   {prefix}-{fiscal_year}-{6-digit-sequence}
 *   e.g. ORD-2026-000001
 *
 * The prefix and "fiscal_year_enabled" flag come from the settings table
 * via SettingsService. Sequence is per-fiscal-year (resets each year).
 *
 * Concurrency: the sequence is computed inside a row-locked transaction so
 * two simultaneous order creations cannot collide. The unique constraint
 * on orders.order_number is the second line of defense.
 */
class OrderNumberService
{
    public static function generate(?FiscalYear $fiscalYear = null): string
    {
        $prefix = SettingsService::get('order_prefix', 'ORD');
        $fiscalYearEnabled = (bool) SettingsService::get('fiscal_year_enabled', true);

        $fiscalYear ??= FiscalYear::where('status', 'Open')->latest('start_date')->first();

        $yearPart = $fiscalYearEnabled && $fiscalYear
            ? $fiscalYear->name
            : (string) now()->year;

        return DB::transaction(function () use ($prefix, $yearPart, $fiscalYear) {
            // Lock the highest existing order_number for this prefix/year so
            // two concurrent inserts don't compute the same sequence.
            $like = "{$prefix}-{$yearPart}-%";

            $latest = Order::query()
                ->where('order_number', 'like', $like)
                ->when($fiscalYear, fn ($q) => $q->where('fiscal_year_id', $fiscalYear->id))
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('order_number');

            $nextSeq = 1;
            if ($latest) {
                $parts = explode('-', $latest);
                $nextSeq = ((int) end($parts)) + 1;
            }

            return sprintf('%s-%s-%06d', $prefix, $yearPart, $nextSeq);
        });
    }
}
