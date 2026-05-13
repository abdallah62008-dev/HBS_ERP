<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Finance Phase 5F — Finance Period row.
 *
 * Lifecycle:
 *   open → closed (via FinancePeriodService::close)
 *   closed → open (via FinancePeriodService::reopen, restricted permission)
 *
 * Delete: blocked by the `deleting` hook. Finance audit history is
 * permanent. To remove a period created in error, close it (or leave it
 * open with an empty/notes-only state).
 *
 * @property int     $id
 * @property string  $name
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property string  $status        'open' | 'closed'
 * @property ?int    $closed_by
 * @property ?\Illuminate\Support\Carbon $closed_at
 * @property ?int    $reopened_by
 * @property ?\Illuminate\Support\Carbon $reopened_at
 * @property ?string $notes
 */
class FinancePeriod extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'status',
        'closed_by',
        'closed_at',
        'reopened_by',
        'reopened_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    /* ────────────────────── Immutability guard ────────────────────── */

    /**
     * Finance periods are never deleted — closed history must remain
     * available for audit + report drill-downs. The controller never
     * exposes a delete route; this hook is defence-in-depth against a
     * stray `FinancePeriod::destroy(...)` call.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $period) {
            throw new RuntimeException(
                "Finance period #{$period->id} ({$period->name}) cannot be deleted. "
                . 'Close it instead, or reopen if it was closed in error.'
            );
        });
    }

    /* ────────────────────── Lifecycle helpers ────────────────────── */

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function canBeEdited(): bool
    {
        return $this->isOpen();
    }

    public function canBeClosed(): bool
    {
        return $this->isOpen();
    }

    public function canBeReopened(): bool
    {
        return $this->isClosed();
    }

    /**
     * True when this period covers the given date (inclusive on both ends).
     */
    public function coversDate(Carbon|string $date): bool
    {
        $d = $date instanceof Carbon ? $date->toDateString() : Carbon::parse((string) $date)->toDateString();
        return $d >= $this->start_date->toDateString()
            && $d <= $this->end_date->toDateString();
    }

    /* ────────────────────── Relations ────────────────────── */

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
