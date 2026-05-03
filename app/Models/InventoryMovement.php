<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'product_variant_id', 'warehouse_id',
        'movement_type', 'quantity', 'unit_cost',
        'reference_type', 'reference_id',
        'notes', 'created_by', 'created_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /** @var array<int,string> Stock-IN movement types (positive quantity) */
    public const STOCK_IN_TYPES = [
        'Purchase', 'Return To Stock', 'Opening Balance', 'Transfer In',
    ];

    /** @var array<int,string> Stock-OUT movement types (stored as negative) */
    public const STOCK_OUT_TYPES = [
        'Ship', 'Return Damaged', 'Transfer Out',
    ];

    /** @var array<int,string> Reservation movement types (don't affect on-hand) */
    public const RESERVATION_TYPES = [
        'Reserve', 'Release Reservation',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
