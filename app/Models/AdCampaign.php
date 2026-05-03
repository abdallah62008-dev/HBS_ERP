<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdCampaign extends Model
{
    public const PLATFORMS = [
        'TikTok', 'Snapchat', 'Facebook', 'Instagram', 'Google',
        'WhatsApp', 'Website', 'Noon', 'Amazon', 'Manual',
    ];

    protected $fillable = [
        'name', 'platform', 'product_id',
        'start_date', 'end_date',
        'budget', 'spend',
        'orders_count', 'delivered_orders_count', 'returned_orders_count',
        'revenue', 'gross_profit', 'net_profit', 'cost_per_order', 'roas',
        'status', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'budget' => 'decimal:2',
        'spend' => 'decimal:2',
        'revenue' => 'decimal:2',
        'gross_profit' => 'decimal:2',
        'net_profit' => 'decimal:2',
        'cost_per_order' => 'decimal:2',
        'roas' => 'decimal:2',
        'orders_count' => 'integer',
        'delivered_orders_count' => 'integer',
        'returned_orders_count' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'related_campaign_id');
    }
}
