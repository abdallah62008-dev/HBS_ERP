<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Console\Command;

/**
 * Customer C-4B — backfill the address book from legacy customer fields.
 *
 * Idempotent. Only acts on customers that have:
 *  - no rows in `customer_addresses`, AND
 *  - a non-empty `default_address` column.
 *
 * A new `customer_addresses` row is created with `is_default = true` and
 * the customer's existing `city` / `governorate` / `country` fields.
 * Audit-tracking columns (`created_by`, `updated_by`) are left NULL —
 * this is a system-driven import, not an operator action.
 *
 * Usage:
 *
 *     php artisan customers:backfill-addresses
 *     php artisan customers:backfill-addresses --dry-run
 *     php artisan customers:backfill-addresses --limit=100
 *
 * Mirrors the {@see BackfillCustomerPhones} pattern intentionally so
 * both commands look the same to operators.
 */
class BackfillCustomerAddresses extends Command
{
    protected $signature = 'customers:backfill-addresses
        {--dry-run : Parse and report without writing}
        {--limit=0 : Maximum customers to process (0 = unlimited)}';

    protected $description = 'Backfill the customer_addresses table from legacy customers.default_address (C-4B).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?? 0);

        $this->info('Backfilling customer address book — dry-run: ' . ($dryRun ? 'yes' : 'no'));

        // Eligibility: customer must have a non-empty default_address
        // AND zero rows in customer_addresses already. The doesntHave
        // check uses the existing `customer_id` index on the addresses
        // table so the scan stays cheap.
        $query = Customer::query()
            ->whereNotNull('default_address')
            ->where('default_address', '!=', '')
            ->doesntHave('addresses');

        $total = $query->count();
        if ($total === 0) {
            $this->info('No customers to backfill — all eligible rows already have an address book entry.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} customer(s) needing backfill.");

        $processed = 0;
        $created = 0;
        $skipped = 0;

        $iter = $limit > 0 ? $query->limit($limit)->cursor() : $query->cursor();
        foreach ($iter as $customer) {
            $processed++;

            // Defence-in-depth: re-check on each row in case a parallel
            // operator added an address between the count and the loop.
            if ($customer->addresses()->exists()) {
                $skipped++;
                continue;
            }

            if (! $dryRun) {
                CustomerAddress::create([
                    'customer_id' => $customer->id,
                    'address' => $customer->default_address,
                    'city' => $customer->city,
                    'governorate' => $customer->governorate,
                    'country' => $customer->country,
                    'is_default' => true,
                    // No actor — this is a one-time system import.
                    'created_by' => null,
                    'updated_by' => null,
                ]);
            }
            $created++;
        }

        $this->info("Processed: {$processed}");
        $this->info($dryRun ? "Would create: {$created}" : "Created: {$created}");
        if ($skipped > 0) {
            $this->warn("Skipped (race / pre-existing): {$skipped}");
        }

        return self::SUCCESS;
    }
}
