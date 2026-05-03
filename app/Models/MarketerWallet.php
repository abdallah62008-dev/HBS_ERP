<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketerWallet extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'marketer_id',
        'total_expected', 'total_pending', 'total_earned', 'total_paid', 'balance',
        'updated_at',
    ];

    protected $casts = [
        'total_expected' => 'decimal:2',
        'total_pending' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'balance' => 'decimal:2',
        'updated_at' => 'datetime',
    ];

    public function marketer(): BelongsTo
    {
        return $this->belongsTo(Marketer::class);
    }
}
