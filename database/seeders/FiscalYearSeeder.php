<?php

namespace Database\Seeders;

use App\Models\FiscalYear;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the current calendar year as the active fiscal year if no
 * Open year exists. Subsequent years are created at year-end closing
 * (Phase 8) — not by re-running this seeder.
 */
class FiscalYearSeeder extends Seeder
{
    public function run(): void
    {
        if (FiscalYear::where('status', 'Open')->exists()) {
            return;
        }

        $year = (int) now()->format('Y');

        FiscalYear::firstOrCreate(
            ['name' => (string) $year],
            [
                'start_date' => Carbon::create($year, 1, 1)->startOfDay(),
                'end_date' => Carbon::create($year, 12, 31)->endOfDay(),
                'status' => 'Open',
            ],
        );
    }
}
