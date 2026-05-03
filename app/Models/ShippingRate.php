<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    protected $fillable = [
        'shipping_company_id', 'country', 'governorate', 'city',
        'base_cost', 'cod_fee', 'return_fee', 'estimated_days', 'status',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'base_cost' => 'decimal:2',
        'cod_fee' => 'decimal:2',
        'return_fee' => 'decimal:2',
        'estimated_days' => 'integer',
    ];

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class);
    }
}
