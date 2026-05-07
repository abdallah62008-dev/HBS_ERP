<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 7 — internal-support / customer-issue ticketing.
 *
 * Schema lives in 2026_05_07_300001_create_tickets_table. Status is a
 * MySQL enum so the model exposes the same values as constants for
 * type-safe references in controllers and tests.
 *
 * Ownership: a ticket belongs to its creator (`user_id`). Marketers can
 * only see their own tickets — `scopeOwnedBy()` enforces this in the
 * controller's index/show paths.
 */
class Ticket extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_CLOSED,
    ];

    protected $fillable = [
        'user_id',
        'subject',
        'message',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ── Query scopes ── */

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function ($w) use ($term) {
            $w->where('subject', 'like', "%{$term}%")
                ->orWhere('message', 'like', "%{$term}%")
                ->orWhereHas('user', fn ($u) => $u
                    ->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
        });
    }

    /**
     * Restricts the query to tickets owned by the given user. Used by
     * TicketsController for marketer scoping. Mirrors the pattern used
     * by Order::scopeForCurrentMarketer (fail-closed if no user_id).
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
