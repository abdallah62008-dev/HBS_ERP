<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finance Phase 5A — Refund.
 *
 * Lifecycle states in this phase:
 *   requested   ⟶ approved
 *   requested   ⟶ rejected
 *
 * Phase 5B adds the `paid` terminal state and the cashbox OUT
 * transaction it spawns. The `paid_*` and cashbox columns exist in
 * the schema today (future-reserved nullable) but are never written
 * by Phase 5A code.
 *
 * Delete rules (enforced by the booted() hook below):
 *   - Only `requested` refunds may be deleted.
 *   - Approved / rejected / paid refunds throw on delete.
 *
 * @property int     $id
 * @property ?int    $order_id
 * @property ?int    $collection_id
 * @property ?int    $order_return_id
 * @property ?int    $customer_id
 * @property float   $amount
 * @property ?string $reason
 * @property string  $status     'requested' | 'approved' | 'rejected' | 'paid' (reserved)
 * @property ?int    $requested_by
 * @property ?int    $approved_by
 * @property ?\Illuminate\Support\Carbon $approved_at
 * @property ?int    $rejected_by
 * @property ?\Illuminate\Support\Carbon $rejected_at
 * @property ?int    $cashbox_id              (Phase 5B)
 * @property ?int    $payment_method_id       (Phase 5B)
 * @property ?int    $cashbox_transaction_id  (Phase 5B)
 * @property ?int    $paid_by                 (Phase 5B)
 * @property ?\Illuminate\Support\Carbon $paid_at  (Phase 5B)
 */
class Refund extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    /** Phase 5B reserved — never written by Phase 5A code. */
    public const STATUS_PAID = 'paid';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PAID,
    ];

    /** Statuses that count toward the over-refund guard (Phase 5A). */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_PAID,
    ];

    protected $fillable = [
        'order_id',
        'collection_id',
        'order_return_id',
        'customer_id',
        'amount',
        'reason',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        // Future-reserved (Phase 5B). Listed so service-layer writes
        // are simple, but no Phase 5A code paths assign these.
        'cashbox_id',
        'payment_method_id',
        'cashbox_transaction_id',
        'paid_by',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /* ────────────────────── Delete guard ────────────────────── */

    /**
     * Only `requested` refunds may be deleted. Approved / rejected /
     * paid refunds are an audit record and must remain.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $refund) {
            if (! $refund->canBeDeleted()) {
                throw new \RuntimeException(
                    "Refund #{$refund->id} (status: {$refund->status}) cannot be deleted. "
                    . 'Only requested refunds may be deleted; approved / rejected / paid records are kept for audit.'
                );
            }
        });
    }

    /* ────────────────────── Relations ────────────────────── */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    /* ────────────────────── Phase 5B future relations ────────────────────── */

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

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /* ────────────────────── State helpers ────────────────────── */

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    /** Phase 5A: requested refunds are the only mutable state. */
    public function canBeEdited(): bool
    {
        return $this->isRequested();
    }

    public function canBeApproved(): bool
    {
        return $this->isRequested();
    }

    public function canBeRejected(): bool
    {
        return $this->isRequested();
    }

    public function canBeDeleted(): bool
    {
        return $this->isRequested();
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }
}
