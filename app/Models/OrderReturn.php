<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maps the `returns` table.
 *
 * Class is named `OrderReturn` because PHP's reserved word `return`
 * prevents a class literally named `Return`. The DB table keeps the
 * spec's name (`returns`).
 */
class OrderReturn extends Model
{
    protected $table = 'returns';

    public const STATUSES = ['Pending', 'Received', 'Inspected', 'Restocked', 'Damaged', 'Closed'];
    public const CONDITIONS = ['Good', 'Damaged', 'Missing Parts', 'Unknown'];

    /**
     * Finance Phase 5C — return statuses eligible to spawn a refund.
     *
     * Pre-inspection statuses ('Pending', 'Received') are deliberately
     * excluded because the `refund_amount` field hasn't been finalised
     * yet — inspection is the moment the decision is locked in.
     */
    public const REFUND_ELIGIBLE_STATUSES = [
        'Inspected', 'Restocked', 'Damaged', 'Closed',
    ];

    protected $fillable = [
        'order_id', 'customer_id', 'shipping_company_id', 'return_reason_id',
        'product_condition', 'return_status',
        'refund_amount', 'shipping_loss_amount', 'restockable',
        'notes', 'inspected_by', 'inspected_at',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'shipping_loss_amount' => 'decimal:2',
        'restockable' => 'boolean',
        'inspected_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function returnReason(): BelongsTo
    {
        return $this->belongsTo(ReturnReason::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    /**
     * Finance Phase 5C — refunds attached to this return.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'order_return_id');
    }

    /* ────────────────────── Phase 5C eligibility helpers ────────────────────── */

    /**
     * True when this return is in a state where a refund may legitimately
     * be requested. Eligibility requires:
     *   - the return has been inspected (status is post-inspection), AND
     *   - a positive `refund_amount` was recorded by the inspector.
     */
    public function canRequestRefund(): bool
    {
        return (float) $this->refund_amount > 0
            && in_array($this->return_status, self::REFUND_ELIGIBLE_STATUSES, true);
    }
}
