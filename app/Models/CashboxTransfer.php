<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Finance Phase 2 — Cashbox Transfers.
 *
 * One row per transfer. Each transfer is materialised as TWO rows in
 * `cashbox_transactions` (one negative on the source cashbox, one
 * positive on the destination) — both linked back via
 * `cashbox_transactions.transfer_id`.
 *
 * Phase 2 invariant (enforced in CashboxTransferService):
 *   - from_cashbox_id != to_cashbox_id
 *   - amount > 0
 *   - both cashboxes are active
 *   - service-layer write is atomic via DB::transaction
 *
 * @property int    $id
 * @property int    $from_cashbox_id
 * @property int    $to_cashbox_id
 * @property float  $amount
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property ?string $reason
 * @property ?int    $created_by
 */
class CashboxTransfer extends Model
{
    protected $fillable = [
        'from_cashbox_id',
        'to_cashbox_id',
        'amount',
        'occurred_at',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    /* ────────────────────── Relations ────────────────────── */

    public function fromCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'from_cashbox_id');
    }

    public function toCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'to_cashbox_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The two cashbox_transactions this transfer produced.
     * Always exactly two rows when the service ran successfully.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class, 'transfer_id');
    }
}
