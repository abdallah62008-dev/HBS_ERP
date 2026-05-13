<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collection extends Model
{
    public const STATUSES = [
        'Not Collected', 'Collected', 'Partially Collected',
        'Pending Settlement', 'Settlement Received', 'Rejected', 'Refunded',
    ];

    /**
     * Statuses where the money is considered "in our hands" — eligible
     * for posting to a cashbox. Used by CollectionCashboxService.
     */
    public const POSTABLE_STATUSES = [
        'Collected', 'Partially Collected', 'Settlement Received',
    ];

    protected $fillable = [
        'order_id', 'shipping_company_id', 'amount_due', 'amount_collected',
        'collection_status', 'settlement_reference', 'settlement_date',
        'notes', 'created_by', 'updated_by',
        // Finance Phase 3.
        'cashbox_id', 'payment_method_id', 'cashbox_transaction_id', 'cashbox_posted_at',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_collected' => 'decimal:2',
        'settlement_date' => 'date',
        'cashbox_posted_at' => 'datetime',
    ];

    /* ────────────────────── Relations ────────────────────── */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    /* Phase 3 relations. */

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

    /* ────────────────────── Helpers / scopes ────────────────────── */

    public function isPosted(): bool
    {
        return $this->cashbox_transaction_id !== null;
    }

    public function isPostable(): bool
    {
        return in_array($this->collection_status, self::POSTABLE_STATUSES, true)
            && (float) $this->amount_collected > 0
            && ! $this->isPosted();
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->whereNotNull('cashbox_transaction_id');
    }

    public function scopeUnposted(Builder $query): Builder
    {
        return $query->whereNull('cashbox_transaction_id');
    }
}
