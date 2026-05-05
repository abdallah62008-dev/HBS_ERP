<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Marketer extends Model
{
    protected $fillable = [
        'user_id', 'code', 'price_group_id', 'marketer_price_tier_id',
        'phone', 'status',
        'shipping_deducted', 'tax_deducted', 'commission_after_delivery_only',
        'settlement_cycle', 'notes',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'shipping_deducted' => 'boolean',
        'tax_deducted' => 'boolean',
        'commission_after_delivery_only' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(MarketerPriceGroup::class, 'price_group_id');
    }

    public function priceTier(): BelongsTo
    {
        return $this->belongsTo(MarketerPriceGroup::class, 'marketer_price_tier_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MarketerTransaction::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(MarketerWallet::class);
    }
}
