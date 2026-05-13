<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finance Phase 1 — append-only ledger row.
 *
 * Mutation rules (enforced in the service layer, not via DB constraints):
 *   - Rows are NEVER deleted (no soft delete, no DELETE endpoint).
 *   - Rows are NEVER edited after insert.
 *   - Corrections are made by inserting an opposite-signed row whose
 *     `notes` references the original via id, and whose `source_type`
 *     is `adjustment`.
 *
 * `source_type` is a free-form string deliberately — later phases add
 * values without altering the schema.
 *
 * @property int    $id
 * @property int    $cashbox_id
 * @property string $direction  'in' | 'out'
 * @property float  $amount     signed: positive for in, negative for out
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property ?string $source_type
 * @property ?int    $source_id
 * @property ?string $notes
 * @property ?int    $created_by
 *
 * Append-only contract:
 *   - INSERT is allowed (the ledger grows).
 *   - UPDATE is blocked by the booted() hook below.
 *   - DELETE is blocked by the booted() hook below.
 *   - Corrections are made by writing a new opposite-signed row whose
 *     `notes` references the original. Never by mutation.
 *
 * Phase 0 finance docs ($docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md)
 * treat this guarantee as structural. The guard below makes it a real
 * runtime constraint, not just a comment.
 */
class CashboxTransaction extends Model
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public const SOURCE_OPENING_BALANCE = 'opening_balance';
    public const SOURCE_ADJUSTMENT = 'adjustment';
    public const SOURCE_TRANSFER = 'transfer';
    public const SOURCE_COLLECTION = 'collection';
    public const SOURCE_EXPENSE = 'expense';

    /** Phase 1 source_type whitelist. Later phases extend this list. */
    public const PHASE_1_SOURCE_TYPES = [
        self::SOURCE_OPENING_BALANCE,
        self::SOURCE_ADJUSTMENT,
    ];

    /** Phase 2 adds the `transfer` source_type. */
    public const PHASE_2_SOURCE_TYPES = [
        self::SOURCE_OPENING_BALANCE,
        self::SOURCE_ADJUSTMENT,
        self::SOURCE_TRANSFER,
    ];

    /** Phase 3 adds the `collection` source_type. */
    public const PHASE_3_SOURCE_TYPES = [
        self::SOURCE_OPENING_BALANCE,
        self::SOURCE_ADJUSTMENT,
        self::SOURCE_TRANSFER,
        self::SOURCE_COLLECTION,
    ];

    /** Phase 4 adds the `expense` source_type. */
    public const PHASE_4_SOURCE_TYPES = [
        self::SOURCE_OPENING_BALANCE,
        self::SOURCE_ADJUSTMENT,
        self::SOURCE_TRANSFER,
        self::SOURCE_COLLECTION,
        self::SOURCE_EXPENSE,
    ];

    protected $fillable = [
        'cashbox_id',
        'direction',
        'amount',
        'occurred_at',
        'source_type',
        'source_id',
        'transfer_id',
        'payment_method_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /* ────────────────────── Append-only guard ────────────────────── */

    /**
     * Block every UPDATE and DELETE on an existing transaction row at
     * the model layer. INSERT still flows through normally. See the
     * class docblock for rationale.
     */
    protected static function booted(): void
    {
        static::updating(function (self $tx) {
            throw new \RuntimeException(
                'cashbox_transactions are append-only — row #' . $tx->id
                . ' cannot be updated. Write a reversal entry instead.'
            );
        });

        static::deleting(function (self $tx) {
            throw new \RuntimeException(
                'cashbox_transactions are append-only — row #' . $tx->id
                . ' cannot be deleted. Write a reversal entry instead.'
            );
        });
    }

    /* ────────────────────── Relations ────────────────────── */

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ────────────────────── Scopes ────────────────────── */

    public function scopeBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('occurred_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('occurred_at', '<=', $to);
        }
        return $query;
    }

    public function scopeDirection(Builder $query, ?string $direction): Builder
    {
        if ($direction && in_array($direction, [self::DIRECTION_IN, self::DIRECTION_OUT], true)) {
            $query->where('direction', $direction);
        }
        return $query;
    }

    public function scopeOfSourceType(Builder $query, ?string $sourceType): Builder
    {
        if ($sourceType) {
            $query->where('source_type', $sourceType);
        }
        return $query;
    }
}
