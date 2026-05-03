<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerTransaction extends Model
{
    public const TYPE_EXPECTED = 'Expected Profit';
    public const TYPE_PENDING = 'Pending Profit';
    public const TYPE_EARNED = 'Earned Profit';
    public const TYPE_CANCELLED = 'Cancelled Profit';
    public const TYPE_PAYOUT = 'Payout';
    public const TYPE_ADJUSTMENT = 'Adjustment';

    public const STATUSES = ['Expected', 'Pending', 'Approved', 'Paid', 'Cancelled'];

    protected $fillable = [
        'marketer_id', 'order_id', 'transaction_type',
        'selling_price', 'trade_product_price', 'shipping_amount', 'tax_amount', 'extra_fees',
        'net_profit', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'selling_price' => 'decimal:2',
        'trade_product_price' => 'decimal:2',
        'shipping_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'extra_fees' => 'decimal:2',
        'net_profit' => 'decimal:2',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
