<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceHistory extends Model
{
    public $timestamps = false;

    protected $table = 'product_price_history';

    protected $fillable = [
        'product_id', 'product_variant_id',
        'old_cost_price', 'new_cost_price',
        'old_selling_price', 'new_selling_price',
        'old_marketer_trade_price', 'new_marketer_trade_price',
        'reason', 'changed_by', 'created_at',
    ];

    protected $casts = [
        'old_cost_price' => 'decimal:2',
        'new_cost_price' => 'decimal:2',
        'old_selling_price' => 'decimal:2',
        'new_selling_price' => 'decimal:2',
        'old_marketer_trade_price' => 'decimal:2',
        'new_marketer_trade_price' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
