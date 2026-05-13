<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Finance Phase 5D — Marketer Payout workflow row.
 *
 * Lifecycle:
 *   requested ⟶ approved ⟶ paid
 *   requested ⟶ rejected
 *
 * Forbidden transitions are enforced by the service layer (status guards)
 * plus the model `deleting` hook (only `requested` payouts may be deleted).
 *
 * Wallet effect: a paid payout is mirrored as a `MarketerTransaction`
 * (`type=Payout, status=Paid`) so the existing wallet recompute keeps
 * `total_paid` correct. The reverse link is `marketer_transaction_id`.
 *
 * Cashbox effect: a paid payout writes one `CashboxTransaction` with
 * `source_type='marketer_payout'` and negative signed amount. The reverse
 * link is `cashbox_transaction_id`.
 *
 * @property int     $id
 * @property int     $marketer_id
 * @property ?int    $cashbox_id
 * @property ?int    $payment_method_id
 * @property ?int    $cashbox_transaction_id
 * @property ?int    $marketer_transaction_id
 * @property float   $amount
 * @property string  $status
 * @property ?string $notes
 * @property ?int    $requested_by
 * @property ?int    $approved_by
 * @property ?int    $rejected_by
 * @property ?int    $paid_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?\Illuminate\Support\Carbon $rejected_at
 * @property ?\Illuminate\Support\Carbon $paid_at
 */
class MarketerPayout extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
    ];

    /** Statuses that count toward the marketer's outstanding-payout total. */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
    ];

    protected $fillable = [
        'marketer_id',
        'cashbox_id',
        'payment_method_id',
        'cashbox_transaction_id',
        'marketer_transaction_id',
        'amount',
        'status',
        'notes',
        'requested_by',
        'approved_by',
        'rejected_by',
        'paid_by',
        'approved_at',
        'rejected_at',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /* ────────────────────── Immutability guard ────────────────────── */

    /**
     * Only `requested` payouts may be deleted. Defence-in-depth: the
     * controller already enforces this, and the service layer never
     * deletes, so the only way this hook fires is a stray
     * `MarketerPayout::destroy(...)` from a job or console.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $payout) {
            if ($payout->status !== self::STATUS_REQUESTED) {
                throw new RuntimeException(
                    "Marketer payout #{$payout->id} cannot be deleted (status: {$payout->status})."
                );
            }
        });
    }

    /* ────────────────────── Lifecycle helpers ────────────────────── */

    public function canBeEdited(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function canBeDeleted(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function canBeApproved(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function canBeRejected(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function canBePaid(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && empty($this->cashbox_transaction_id)
            && empty($this->paid_at);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /* ────────────────────── Relations ────────────────────── */

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function cashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function cashboxTransaction(): BelongsTo
    {
        return $this->belongsTo(CashboxTransaction::class);
    }

    public function marketerTransaction(): BelongsTo
    {
        return $this->belongsTo(MarketerTransaction::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /* ────────────────────── Scopes ────────────────────── */

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if ($status && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }
        return $query;
    }

    public function scopeRequested(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REQUESTED);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }
}
