<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\PhoneNormalizationService;
use Illuminate\Console\Command;

/**
 * Orders & Products O-2 — backfill the phone triple on existing
 * customers and orders.
 *
 * Idempotent: only acts on customers whose `normalized_phone` is null.
 * Safe to run any number of times. Failures are logged (returned in the
 * summary) and leave the customer's `normalized_phone` null so the
 * operator can review.
 *
 * Usage:
 *
 *     php artisan customers:backfill-phones                        # default country: +20
 *     php artisan customers:backfill-phones --country=+966         # treat ambiguous raw phones as Saudi
 *     php artisan customers:backfill-phones --dry-run              # parse only, no writes
 *     php artisan customers:backfill-phones --limit=100            # safety cap (default: unlimited)
 *
 * Order snapshots are populated as a side effect — every order whose
 * customer just gained a normalized number copies that value to
 * `customer_phone_normalized`. Existing snapshot values are NOT
 * overwritten so a re-run doesn't disturb historical order data that
 * was set correctly the first time.
 */
class BackfillCustomerPhones extends Command
{
    protected $signature = 'customers:backfill-phones
        {--country= : Country code hint for ambiguous raw phones (defaults to +20)}
        {--dry-run : Parse and report without writing}
        {--limit=0 : Maximum customers to process (0 = unlimited)}';

    protected $description = 'Backfill normalized_phone / local_phone / country_code on existing customers (O-2).';

    public function handle(PhoneNormalizationService $svc): int
    {
        $countryHint = $this->option('country') ?: PhoneNormalizationService::DEFAULT_COUNTRY;
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) ($this->option('limit') ?? 0);

        if (! array_key_exists($countryHint, PhoneNormalizationService::COUNTRY_RULES)) {
            $this->error("Unsupported country hint: {$countryHint}");
            return self::INVALID;
        }

        $this->info("Backfilling phone triple — country hint: {$countryHint}, dry-run: " . ($dryRun ? 'yes' : 'no'));

        $query = Customer::query()->whereNull('normalized_phone');
        $total = $query->count();
        if ($total === 0) {
            $this->info('No customers to backfill — all rows already carry normalized_phone.');
            return self::SUCCESS;
        }

        $this->info("Found {$total} customer(s) needing backfill.");

        $processed = 0;
        $primaryOk = 0;
        $primaryFail = 0;
        $secondaryOk = 0;
        $secondaryFail = 0;
        $orderSnapshotsWritten = 0;
        $failures = [];

        $iter = $limit > 0 ? $query->limit($limit)->cursor() : $query->cursor();
        foreach ($iter as $customer) {
            $processed++;
            $patch = [];

            // Primary phone
            if ($customer->primary_phone) {
                $res = $svc->normalize($customer->primary_phone, $countryHint);
                if ($res['valid']) {
                    $patch['country_code'] = $res['country_code'];
                    $patch['local_phone'] = $res['local_phone'];
                    $patch['normalized_phone'] = $res['normalized_phone'];
                    $primaryOk++;
                } else {
                    $primaryFail++;
                    $failures[] = "Customer #{$customer->id} primary: {$res['error']}";
                }
            }

            // Secondary phone — optional, doesn't fail the whole row.
            if ($customer->secondary_phone) {
                $res = $svc->normalize($customer->secondary_phone, $countryHint);
                if ($res['valid']) {
                    $patch['secondary_country_code'] = $res['country_code'];
                    $patch['secondary_local_phone'] = $res['local_phone'];
                    $patch['secondary_normalized_phone'] = $res['normalized_phone'];
                    $secondaryOk++;
                } else {
                    $secondaryFail++;
                    $failures[] = "Customer #{$customer->id} secondary: {$res['error']}";
                }
            }

            if (! empty($patch) && ! $dryRun) {
                $customer->fill($patch)->save();

                // Side effect: backfill `orders.customer_phone_normalized`
                // for this customer's orders that don't yet carry one.
                // Update IS scoped to NULL targets so a previously-set
                // snapshot is never overwritten.
                if (! empty($patch['normalized_phone'])) {
                    $orderSnapshotsWritten += $customer->orders()
                        ->whereNull('customer_phone_normalized')
                        ->update(['customer_phone_normalized' => $patch['normalized_phone']]);
                }
            }
        }

        $this->info("Processed: {$processed}");
        $this->info("Primary phone — normalized: {$primaryOk}, failed: {$primaryFail}");
        $this->info("Secondary phone — normalized: {$secondaryOk}, failed: {$secondaryFail}");
        if (! $dryRun) {
            $this->info("Order snapshots written: {$orderSnapshotsWritten}");
        }

        if (! empty($failures)) {
            $this->warn('Failures (review and fix manually):');
            foreach (array_slice($failures, 0, 20) as $line) {
                $this->line('  - ' . $line);
            }
            if (count($failures) > 20) {
                $remaining = count($failures) - 20;
                $this->line("  …and {$remaining} more.");
            }
        }

        return self::SUCCESS;
    }
}
