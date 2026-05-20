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

    /**
     * R11 — Order status Transition DAG.
     *
     * Every key is a current status; the array value is the exhaustive
     * set of statuses that status may transition to. Anything not listed
     * is rejected by OrderService::changeStatus with an
     * IllegalOrderTransitionException.
     *
     * Design:
     *   - Pre-confirmation (New, Pending Confirmation): confirm, cancel,
     *     or pause.
     *   - Pre-ship phase (Confirmed, Ready to Pack, Packed, Ready to Ship):
     *     the warehouse sub-states are OPTIONAL refinements — an operator
     *     may fast-forward straight to Shipped or work through them.
     *     Cancellable until the package leaves the warehouse, and may be
     *     marked Returned directly (customer refuses / aborts a live
     *     order — inventory no-op for a pre-ship origin).
     *   - Post-ship (Shipped, Out for Delivery, Delivered): forward-only
     *     toward Delivered, lateral to Returned. No cancellation.
     *   - Returned, Cancelled: terminal.
     *   - On Hold / Need Review: pause overlays — resume to any pre-ship
     *     open state or terminate; cannot jump straight to a post-ship
     *     state (resume first, then advance normally).
     *
     * Consistent with the inventory matrix in
     * OrderService::applyInventoryForTransition (reserve on Confirmed,
     * release on Cancelled, ship on Shipped, no-op on Returned) and with
     * the live transitions performed by ShippingController.
     *
     * (inferred) — the source Order_P0_Doc enumerates the 13 statuses but
     * not the edges; this matrix was reconciled against ShippingController
     * and the existing Returns test fixtures so it rejects no transition
     * the system already performs.
     *
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'New' => [
            'Pending Confirmation', 'Confirmed',
            'Cancelled', 'On Hold', 'Need Review',
        ],
        'Pending Confirmation' => [
            'Confirmed',
            'Cancelled', 'On Hold', 'Need Review',
        ],
        'Confirmed' => [
            'Ready to Pack', 'Packed', 'Ready to Ship', 'Shipped',
            'Returned', 'Cancelled', 'On Hold', 'Need Review',
        ],
        'Ready to Pack' => [
            'Packed', 'Ready to Ship', 'Shipped',
            'Returned', 'Cancelled', 'On Hold', 'Need Review',
        ],
        'Packed' => [
            'Ready to Ship', 'Shipped',
            'Returned', 'Cancelled', 'On Hold', 'Need Review',
        ],
        'Ready to Ship' => [
            'Shipped',
            'Returned', 'Cancelled', 'On Hold', 'Need Review',
        ],
        'Shipped' => [
            'Out for Delivery', 'Delivered', 'Returned',
        ],
        'Out for Delivery' => [
            'Delivered', 'Returned',
        ],
        'Delivered' => [
            'Returned',
        ],
        'Returned'  => [], // terminal
        'Cancelled' => [], // terminal
        'On Hold' => [
            'New', 'Pending Confirmation', 'Confirmed',
            'Ready to Pack', 'Packed', 'Ready to Ship',
            'Cancelled', 'Need Review',
        ],
        'Need Review' => [
            'New', 'Pending Confirmation', 'Confirmed',
            'Ready to Pack', 'Packed', 'Ready to Ship',
            'Cancelled', 'On Hold',
        ],
    ];

    /**
     * R11 — true if $from → $to is a legal transition per the DAG.
     */
    public static function isLegalTransition(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    protected $fillable = [
        'order_number', 'fiscal_year_id', 'customer_id', 'marketer_id',
        'source', 'external_order_reference', 'entry_code',
        'status', 'collection_status', 'shipping_status',
        'customer_name', 'customer_phone', 'customer_phone_secondary',
        'customer_phone_whatsapp', 'customer_phone_normalized', 'customer_address',
        'city', 'governorate', 'country',
        'currency_code',
        'subtotal', 'discount_amount', 'shipping_amount', 'tax_amount', 'extra_fees',
        'total_amount', 'cod_amount', 'product_cost_total', 'marketer_trade_total',
        'gross_profit', 'net_profit', 'marketer_profit',
        'customer_risk_score', 'customer_risk_level', 'duplicate_score',
        'notes', 'internal_notes',
        'confirmed_by', 'confirmed_at',
        'packed_by', 'packed_at',
        'shipped_by', 'shipped_at',
        'delivered_at', 'returned_at',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $appends = ['display_order_number'];

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
        'marketer_profit' => 'decimal:2',
        'customer_phone_whatsapp' => 'boolean',
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

    /* Accessors */

    /**
     * Display order number = `order_number` + "-" + `entry_code` when an
     * entry code is present (e.g. "ORD-2026-000123-AH"). Falls back to
     * the bare `order_number` for orders created before the entry-code
     * feature shipped, or for orders whose creator had no entry_code and
     * no name to derive initials from.
     *
     * Computed at render time — no `display_order_number` column exists.
     * The original `order_number` is never modified.
     */
    public function getDisplayOrderNumberAttribute(): ?string
    {
        if (! $this->order_number) {
            return null;
        }
        return $this->entry_code
            ? "{$this->order_number}-{$this->entry_code}"
            : $this->order_number;
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
