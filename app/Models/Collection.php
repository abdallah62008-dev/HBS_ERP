<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Collection extends Model
{
    public const STATUSES = [
        'Not Collected', 'Collected', 'Partially Collected',
        'Pending Settlement', 'Settlement Received', 'Rejected', 'Refunded',
    ];

    protected $fillable = [
        'order_id', 'shipping_company_id', 'amount_due', 'amount_collected',
        'collection_status', 'settlement_reference', 'settlement_date',
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'amount_due' => 'decimal:2',
        'amount_collected' => 'decimal:2',
        'settlement_date' => 'date',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }
}
