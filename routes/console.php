<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// R10 — release stale stock reservations on orders stuck in Confirmed
// past the configured TTL (default 14 days, override via the
// `reservation_ttl_days` setting). Idempotent; re-running writes nothing
// new because reservationFor(order) returns 0 after the first release.
Schedule::command('inventory:release-stale-reservations')
    ->daily()
    ->name('release-stale-reservations')
    ->withoutOverlapping();
