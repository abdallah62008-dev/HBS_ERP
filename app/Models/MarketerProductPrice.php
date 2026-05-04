<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerProductPrice extends Model
{
    protected $fillable = [
        'marketer_price_group_id', 'product_id', 'product_variant_id',
        'trade_price', 'minimum_selling_price',
        'shipping_cost', 'vat_percent',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'trade_price' => 'decimal:2',
        'minimum_selling_price' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'vat_percent' => 'decimal:2',
    ];

    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(MarketerPriceGroup::class, 'marketer_price_group_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
