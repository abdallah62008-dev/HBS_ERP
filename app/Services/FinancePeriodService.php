<?php

namespace App\Services;

use App\Models\FinancePeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Finance Phase 5F — Finance period lifecycle + closed-period guard.
 *
 * Two responsibilities:
 *
 *   1. Lifecycle: createPeriod / closePeriod / reopenPeriod.
 *   2. Guard: assertDateIsOpen() — called by every cash-impacting
 *      service before writing `cashbox_transactions` to block writes
 *      whose `occurred_at` falls inside a closed period.
 *
 * The guard normalizes the input date to a Y-m-d string so a refund
 * paid at "2026-05-15 14:32:08" is matched against periods whose
 * `start_date` and `end_date` are stored as `date` columns.
 *
 * Read-only services (FinanceReportsService, ReportsService, statement
 * pages) never call the guard — closed periods stay fully readable.
 */
class FinancePeriodService
{
    public const MODULE = 'finance.period';

    /* ────────────────────── Guard ────────────────────── */

    /**
     * Throw RuntimeException when the supplied date falls inside any
     * period whose status is `closed`. No-op when the date is in an
     * open period or in no period at all.
     *
     * Callers should pass the EXACT date that will be stamped into
     * `cashbox_transactions.occurred_at` so the guard mirrors the row.
     */
    public function assertDateIsOpen(Carbon|string $date): void
    {
        $closed = $this->findClosedPeriodForDate($date);
        if ($closed) {
            $d = $this->normalize($date);
            throw new RuntimeException(
                "Finance period \"{$closed->name}\" ({$closed->start_date->toDateString()} → "
                . "{$closed->end_date->toDateString()}) is closed. "
                . "Cannot write financial movements dated {$d}."
            );
        }
    }

    public function isDateClosed(Carbon|string $date): bool
    {
        return $this->findClosedPeriodForDate($date) !== null;
    }

    public function findClosedPeriodForDate(Carbon|string $date): ?FinancePeriod
    {
        $d = $this->normalize($date);
        return FinancePeriod::query()
            ->where('status', FinancePeriod::STATUS_CLOSED)
            ->whereDate('start_date', '<=', $d)
            ->whereDate('end_date', '>=', $d)
            ->first();
    }

    /* ────────────────────── Lifecycle ────────────────────── */

    /**
     * @param  array{name:string, start_date:string, end_date:string, notes?:?string}  $data
     */
    public function createPeriod(array $data, ?User $user = null): FinancePeriod
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Finance period name is required.');
        }

        $start = Carbon::parse((string) $data['start_date'])->startOfDay();
        $end = Carbon::parse((string) $data['end_date'])->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException(
                "Finance period end date ({$end->toDateString()}) must be on or after start date ({$start->toDateString()})."
            );
        }

        // Overlap guard — allowing overlaps would make assertDateIsOpen
        // ambiguous (which closed period wins?). Reject up-front.
        $this->assertNoOverlap($start, $end, excludeId: null);

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($name, $start, $end, $data, $actor) {
            $period = FinancePeriod::create([
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'status' => FinancePeriod::STATUS_OPEN,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            AuditLogService::log(
                action: 'finance_period_created',
                module: self::MODULE,
                recordType: FinancePeriod::class,
                recordId: $period->id,
                newValues: [
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                    'status' => $period->status,
                ],
            );

            return $period;
        });
    }

    /**
     * Update an open period's metadata (name / range / notes). Closed
     * periods are immutable.
     *
     * @param  array{name:string, start_date:string, end_date:string, notes?:?string}  $data
     */
    public function updatePeriod(FinancePeriod $period, array $data, ?User $user = null): FinancePeriod
    {
        if (! $period->canBeEdited()) {
            throw new RuntimeException(
                "Finance period #{$period->id} ({$period->name}) cannot be edited (status: {$period->status})."
            );
        }

        $name = trim((string) ($data['name'] ?? $period->name));
        if ($name === '') {
            throw new InvalidArgumentException('Finance period name is required.');
        }

        $start = Carbon::parse((string) $data['start_date'])->startOfDay();
        $end = Carbon::parse((string) $data['end_date'])->startOfDay();

        if ($end->lt($start)) {
            throw new InvalidArgumentException(
                "Finance period end date ({$end->toDateString()}) must be on or after start date ({$start->toDateString()})."
            );
        }

        $this->assertNoOverlap($start, $end, excludeId: $period->id);

        return DB::transaction(function () use ($period, $name, $start, $end, $data) {
            $old = [
                'name' => $period->name,
                'start_date' => $period->start_date->toDateString(),
                'end_date' => $period->end_date->toDateString(),
            ];

            $period->fill([
                'name' => $name,
                'start_date' => $start,
                'end_date' => $end,
                'notes' => $data['notes'] ?? $period->notes,
            ])->save();

            AuditLogService::log(
                action: 'finance_period_updated',
                module: self::MODULE,
                recordType: FinancePeriod::class,
                recordId: $period->id,
                oldValues: $old,
                newValues: [
                    'name' => $period->name,
                    'start_date' => $period->start_date->toDateString(),
                    'end_date' => $period->end_date->toDateString(),
                ],
            );

            return $period;
        });
    }

    public function closePeriod(FinancePeriod $period, ?User $user = null): FinancePeriod
    {
        if (! $period->canBeClosed()) {
            throw new RuntimeException(
                "Finance period #{$period->id} ({$period->name}) cannot be closed (status: {$period->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($period, $actor) {
            $locked = FinancePeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! $locked->canBeClosed()) {
                throw new RuntimeException(
                    "Finance period #{$locked->id} ({$locked->name}) cannot be closed (status: {$locked->status})."
                );
            }

            $locked->fill([
                'status' => FinancePeriod::STATUS_CLOSED,
                'closed_by' => $actor?->id,
                'closed_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'finance_period_closed',
                module: self::MODULE,
                recordType: FinancePeriod::class,
                recordId: $locked->id,
                oldValues: ['status' => FinancePeriod::STATUS_OPEN],
                newValues: [
                    'status' => FinancePeriod::STATUS_CLOSED,
                    'closed_by' => $actor?->id,
                    'closed_at' => $locked->closed_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    public function reopenPeriod(FinancePeriod $period, ?User $user = null): FinancePeriod
    {
        if (! $period->canBeReopened()) {
            throw new RuntimeException(
                "Finance period #{$period->id} ({$period->name}) cannot be reopened (status: {$period->status})."
            );
        }

        $actor = $user ?? Auth::user();

        return DB::transaction(function () use ($period, $actor) {
            $locked = FinancePeriod::query()->lockForUpdate()->findOrFail($period->id);

            if (! $locked->canBeReopened()) {
                throw new RuntimeException(
                    "Finance period #{$locked->id} ({$locked->name}) cannot be reopened (status: {$locked->status})."
                );
            }

            $locked->fill([
                'status' => FinancePeriod::STATUS_OPEN,
                'reopened_by' => $actor?->id,
                'reopened_at' => now(),
            ])->save();

            AuditLogService::log(
                action: 'finance_period_reopened',
                module: self::MODULE,
                recordType: FinancePeriod::class,
                recordId: $locked->id,
                oldValues: ['status' => FinancePeriod::STATUS_CLOSED],
                newValues: [
                    'status' => FinancePeriod::STATUS_OPEN,
                    'reopened_by' => $actor?->id,
                    'reopened_at' => $locked->reopened_at?->toDateTimeString(),
                ],
            );

            return $locked;
        });
    }

    /* ────────────────────── Internal ────────────────────── */

    private function assertNoOverlap(Carbon $start, Carbon $end, ?int $excludeId): void
    {
        $clash = FinancePeriod::query()
            ->when($excludeId, fn ($q, $id) => $q->where('id', '!=', $id))
            // Two ranges overlap iff start <= other.end AND end >= other.start.
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->first();

        if ($clash) {
            throw new InvalidArgumentException(
                "Finance period overlaps existing period \"{$clash->name}\" "
                . "({$clash->start_date->toDateString()} → {$clash->end_date->toDateString()})."
            );
        }
    }

    private function normalize(Carbon|string $date): string
    {
        return ($date instanceof Carbon ? $date : Carbon::parse((string) $date))->toDateString();
    }
}
