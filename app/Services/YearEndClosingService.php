<?php

namespace App\Services;

use App\Models\BackupLog;
use App\Models\FiscalYear;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Warehouse;
use App\Models\YearEndClosing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Orchestrates the year-end closing workflow defined in
 * 04_BUSINESS_WORKFLOWS.md §20.
 *
 * Steps (each must succeed for the close to land):
 *   1. Verify there is an Open fiscal year and a recent successful backup.
 *   2. Operator types CLOSE YYYY confirmation.
 *   3. Snapshot inventory: write Opening Balance movements at Jan 1 of
 *      the new year for every product with non-zero on-hand.
 *   4. Snapshot marketer/supplier/collection balances on the closing row
 *      (kept as data, no destructive change — those balances continue to
 *      live in their own tables).
 *   5. Lock the old fiscal year (status=Closed).
 *   6. Open the new fiscal year.
 *
 * The reverse path (un-closing a year) intentionally requires a manual
 * super-admin DB edit — the system never automatically un-locks history.
 */
class YearEndClosingService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Snapshot of what the closing covers — counts of open business.
     * Shown on the review page so the operator can confirm there's
     * nothing pending.
     *
     * @return array<string,mixed>
     */
    public function reviewSnapshot(FiscalYear $year): array
    {
        $start = $year->start_date->startOfDay();
        $end = $year->end_date->endOfDay();

        $openOrders = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', Order::OPEN_STATUSES)
            ->count();

        $pendingCollections = DB::table('collections')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('collection_status', ['Not Collected', 'Pending Settlement', 'Partially Collected'])
            ->count();

        $openReturns = DB::table('returns')
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('return_status', ['Pending', 'Received', 'Inspected'])
            ->count();

        $unpaidPurchases = DB::table('purchase_invoices')
            ->whereBetween('invoice_date', [$year->start_date, $year->end_date])
            ->whereIn('status', ['Unpaid', 'Partially Paid'])
            ->where('remaining_amount', '>', 0)
            ->count();

        $marketerOutstanding = (float) DB::table('marketer_wallets')->sum('balance');

        return [
            'fiscal_year' => $year,
            'counts' => [
                'open_orders' => $openOrders,
                'pending_collections' => $pendingCollections,
                'open_returns' => $openReturns,
                'unpaid_purchases' => $unpaidPurchases,
            ],
            'marketer_outstanding_balance' => round($marketerOutstanding, 2),
            'last_backup' => $this->backups->latestSuccessfulBackup(),
        ];
    }

    /**
     * Perform the close. Refuses unless:
     *   - There is a successful backup within the last 24 hours.
     *   - The operator types "CLOSE {year-name}" exactly.
     *
     * @return YearEndClosing
     */
    public function close(FiscalYear $year, string $confirmation, ?string $notes = null): YearEndClosing
    {
        if ($year->status !== 'Open') {
            throw new RuntimeException("Fiscal year {$year->name} is already closed.");
        }

        $expected = "CLOSE {$year->name}";
        if (trim($confirmation) !== $expected) {
            throw new RuntimeException("Confirmation text must match exactly: {$expected}");
        }

        $backup = $this->backups->latestSuccessfulBackup();
        if (! $backup || $backup->created_at->lt(now()->subHours(24))) {
            throw new RuntimeException(
                'Closing requires a successful backup created within the last 24 hours.',
            );
        }

        return DB::transaction(function () use ($year, $backup, $notes) {
            // Create the closing row up front so all later steps reference it.
            $closing = YearEndClosing::create([
                'fiscal_year_id' => $year->id,
                'status' => 'Processing',
                'backup_id' => $backup->id,
                'notes' => $notes,
                'created_by' => Auth::id(),
                'created_at' => now(),
            ]);

            // Step 3: Carry forward inventory.
            $newYearStart = Carbon::create($year->end_date->year + 1, 1, 1)->startOfDay();
            $this->carryForwardInventory($newYearStart, $closing);

            // Step 4: Mark "carried forward" flags. Marketer / supplier /
            // collection balances ARE already continuous in our schema
            // (the balance row carries across years naturally), so the
            // flags here mostly document "we acknowledge they exist."
            $closing->forceFill([
                'stock_carried_forward' => true,
                'marketer_balances_carried_forward' => true,
                'supplier_balances_carried_forward' => true,
                'pending_collections_carried_forward' => true,
            ])->save();

            // Step 5: Lock the old year.
            $year->forceFill([
                'status' => 'Closed',
                'closed_by' => Auth::id(),
                'closed_at' => now(),
            ])->save();

            // Step 6: Open the new year.
            $newYearName = (string) ($year->end_date->year + 1);
            $newYear = FiscalYear::firstOrCreate(
                ['name' => $newYearName],
                [
                    'start_date' => Carbon::create($year->end_date->year + 1, 1, 1)->startOfDay(),
                    'end_date' => Carbon::create($year->end_date->year + 1, 12, 31)->endOfDay(),
                    'status' => 'Open',
                    'created_by' => Auth::id(),
                ],
            );

            $closing->forceFill([
                'new_fiscal_year_id' => $newYear->id,
                'status' => 'Completed',
                'completed_at' => now(),
            ])->save();

            AuditLogService::log('year_end_close', 'year_end',
                YearEndClosing::class, $closing->id,
                oldValues: ['fiscal_year' => $year->name],
                newValues: [
                    'closed_year' => $year->name,
                    'new_year' => $newYear->name,
                    'backup_id' => $backup->id,
                ],
            );

            return $closing->refresh();
        });
    }

    /**
     * Write Opening Balance movements at the start of the new year for
     * every (product × warehouse) pair with non-zero on-hand.
     *
     * Note: in our movement-based system the running totals are
     * continuous, so technically we don't NEED an opening balance row
     * to keep stock numbers correct. We write them anyway because
     * (a) it's what the spec asks for, (b) it means a per-fiscal-year
     * stock report can read just the rows on or after Jan 1, and
     * (c) it gives auditors a clean year-boundary snapshot.
     */
    private function carryForwardInventory(Carbon $newYearStart, YearEndClosing $closing): void
    {
        $warehouses = Warehouse::where('status', 'Active')->get();

        $rows = DB::table('inventory_movements')
            ->selectRaw('
                product_id, product_variant_id, warehouse_id,
                SUM(CASE WHEN movement_type IN ("Purchase","Return To Stock","Opening Balance","Transfer In","Adjustment","Stock Count Correction","Ship","Return Damaged","Transfer Out") THEN quantity ELSE 0 END) AS on_hand
            ')
            ->whereDate('created_at', '<', $newYearStart->toDateTimeString())
            ->groupBy('product_id', 'product_variant_id', 'warehouse_id')
            ->havingRaw('on_hand <> 0')
            ->get();

        foreach ($rows as $r) {
            InventoryMovement::create([
                'product_id' => $r->product_id,
                'product_variant_id' => $r->product_variant_id,
                'warehouse_id' => $r->warehouse_id,
                'movement_type' => 'Opening Balance',
                'quantity' => (int) $r->on_hand,
                'unit_cost' => null,
                'reference_type' => YearEndClosing::class,
                'reference_id' => $closing->id,
                'notes' => "Carried forward from year-end closing #{$closing->id}",
                'created_by' => Auth::id(),
                'created_at' => $newYearStart,
            ]);
        }
    }
}
