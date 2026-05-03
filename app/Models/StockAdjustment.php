<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAdjustment extends Model
{
    protected $fillable = [
        'warehouse_id', 'product_id', 'product_variant_id',
        'old_quantity', 'new_quantity', 'difference', 'reason',
        'status', 'approved_by', 'approved_at', 'rejection_reason',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'old_quantity' => 'integer',
        'new_quantity' => 'integer',
        'difference' => 'integer',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }
}
