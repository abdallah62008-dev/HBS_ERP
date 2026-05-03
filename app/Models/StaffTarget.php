<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffTarget extends Model
{
    public const TYPES = [
        'Confirmed Orders', 'Delivered Orders', 'Sales Amount', 'Low Return Rate',
    ];

    protected $fillable = [
        'user_id', 'target_type', 'target_period', 'target_value',
        'achieved_value', 'start_date', 'end_date', 'status',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'achieved_value' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function progressPct(): float
    {
        if ((float) $this->target_value <= 0) return 0;
        return min(100, round(((float) $this->achieved_value / (float) $this->target_value) * 100, 1));
    }
}
