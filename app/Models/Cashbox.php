<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Finance Phase 1 — a named place where money lives.
 *
 * Cashboxes never get hard-deleted. Retirement = `is_active = false`.
 * Balance is always computed from cashbox_transactions; there is no
 * `current_balance` column by design. See:
 *   docs/finance/PHASE_0_FINANCE_ARCHITECTURE_OVERVIEW.md
 *   docs/finance/PHASE_0_FINANCIAL_BUSINESS_RULES.md
 */
class Cashbox extends Model
{
    public const TYPES = [
        'cash',
        'bank',
        'digital_wallet',
        'marketplace',
        'courier_cod',
    ];

    protected $fillable = [
        'name',
        'type',
        'currency_code',
        'opening_balance',
        'allow_negative_balance',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'allow_negative_balance' => 'boolean',
        'is_active' => 'boolean',
    ];

    /* ────────────────────── Immutable-fields guard ────────────────────── */

    /**
     * Once the cashbox has any transactions, `currency_code` and
     * `opening_balance` are immutable. The service strips these from
     * the update payload, but this hook is defence-in-depth — any
     * direct `Cashbox::update([...])` call from a job, console
     * command, or future controller surfaces an exception instead of
     * silently drifting the books.
     */
    protected static function booted(): void
    {
        static::updating(function (self $cashbox) {
            if (! $cashbox->hasTransactions()) {
                return;
            }
            if ($cashbox->isDirty('currency_code')) {
                throw new \RuntimeException(
                    "Cashbox #{$cashbox->id} currency_code is immutable after "
                    . 'the first transaction.'
                );
            }
            if ($cashbox->isDirty('opening_balance')) {
                throw new \RuntimeException(
                    "Cashbox #{$cashbox->id} opening_balance is immutable after "
                    . 'the first transaction.'
                );
            }
        });
    }

    /* ────────────────────── Relations ────────────────────── */

    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class)->latest('occurred_at')->latest('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ────────────────────── Scopes ────────────────────── */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        if ($type && in_array($type, self::TYPES, true)) {
            $query->where('type', $type);
        }
        return $query;
    }

    /* ────────────────────── Helpers ────────────────────── */

    /**
     * Compute the current balance from the ledger. Not cached — callers
     * that need the value repeatedly within one request should hold the
     * result themselves.
     */
    public function balance(): float
    {
        return (float) $this->transactions()->sum('amount');
    }

    /**
     * True if any transaction exists for this cashbox. Used to gate
     * mutation of fields that are immutable after the first transaction
     * (opening_balance, currency_code).
     */
    public function hasTransactions(): bool
    {
        return $this->transactions()->exists();
    }
}
