<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shipment extends Model
{
    public const STATUSES = [
        'Not Shipped', 'Assigned', 'Picked Up', 'In Transit',
        'Out for Delivery', 'Delivered', 'Returned', 'Delayed', 'Lost',
    ];

    public const ACTIVE_STATUSES = [
        'Assigned', 'Picked Up', 'In Transit', 'Out for Delivery', 'Delayed',
    ];

    protected $fillable = [
        'order_id', 'shipping_company_id', 'tracking_number',
        'shipping_status',
        'assigned_at', 'picked_up_at', 'delivered_at', 'returned_at',
        'delayed_reason', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'picked_up_at' => 'datetime',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ShippingLabel::class);
    }

    public function latestLabel(): HasOne
    {
        return $this->hasOne(ShippingLabel::class)->latestOfMany();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('shipping_status', self::ACTIVE_STATUSES);
    }
}
