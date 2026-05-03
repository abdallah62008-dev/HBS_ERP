<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public const STATUSES = [
        'New', 'Pending Confirmation', 'Confirmed', 'Ready to Pack',
        'Packed', 'Ready to Ship', 'Shipped', 'Out for Delivery',
        'Delivered', 'Returned', 'Cancelled', 'On Hold', 'Need Review',
    ];

    /**
     * Statuses considered "open" — i.e. the order is still moving toward
     * fulfilment. Used by reports and the dashboard.
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = [
        'New', 'Pending Confirmation', 'Confirmed', 'Ready to Pack',
        'Packed', 'Ready to Ship', 'Shipped', 'Out for Delivery',
        'On Hold', 'Need Review',
    ];

    protected $fillable = [
        'order_number', 'fiscal_year_id', 'customer_id', 'marketer_id',
        'source', 'status', 'collection_status', 'shipping_status',
        'customer_name', 'customer_phone', 'customer_address',
        'city', 'governorate', 'country',
        'currency_code',
        'subtotal', 'discount_amount', 'shipping_amount', 'tax_amount', 'extra_fees',
        'total_amount', 'cod_amount', 'product_cost_total', 'marketer_trade_total',
        'gross_profit', 'net_profit',
        'customer_risk_score', 'customer_risk_level', 'duplicate_score',
        'notes', 'internal_notes',
        'confirmed_by', 'confirmed_at',
        'packed_by', 'packed_at',
        'shipped_by', 'shipped_at',
        'delivered_at', 'returned_at',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'extra_fees' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cod_amount' => 'decimal:2',
        'product_cost_total' => 'decimal:2',
        'marketer_trade_total' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'customer_risk_score' => 'integer',
        'duplicate_score' => 'integer',
        'confirmed_at' => 'datetime',
        'packed_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    /* Relationships */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->orderBy('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(OrderNote::class);
    }

    /* ── Phase 4 relationships ── */

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class)->latest('id');
    }

    public function activeShipment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Shipment::class)
            ->whereIn('shipping_status', Shipment::ACTIVE_STATUSES)
            ->latestOfMany();
    }

    public function collection(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Collection::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function attachments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Attachment::class, 'related');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function packedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    public function shippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipped_by');
    }

    /* Scopes */

    /**
     * Marketer ownership scope. When the current user is a Marketer, only
     * return orders where marketer_id equals their marketer record's id.
     * Admin / non-marketer roles see everything.
     *
     * If a marketer-role user doesn't yet have a marketers row, the scope
     * matches nothing — fail closed.
     */
    public function scopeForCurrentMarketer(Builder $query): Builder
    {
        $user = auth()->user();
        if (! $user || ! method_exists($user, 'isMarketer') || ! $user->isMarketer()) {
            return $query;
        }

        return $query->where('marketer_id', $user->marketer?->id);
    }

    /**
     * BelongsTo accessor for the marketer record (Phase 5 uses this in
     * controllers and the OrderService).
     */
    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /* Helpers */

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isHighRisk(): bool
    {
        return $this->customer_risk_level === 'High';
    }
}
