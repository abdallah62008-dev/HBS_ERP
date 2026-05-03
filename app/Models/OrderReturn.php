<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
