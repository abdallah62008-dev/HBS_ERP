<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer C-5B — duplicate merge audit row.
 *
 * One row per executed merge. Immutable history (no `updated_at`).
 * The `payload` carries snapshots and lists of affected ids that the
 * future rollback command will rely on.
 */
class CustomerMerge extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'source_customer_id', 'target_customer_id', 'merged_by',
        'reason',
        'affected_orders_count', 'affected_returns_count', 'affected_refunds_count',
        'affected_notes_count', 'affected_addresses_count', 'affected_tags_count',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function sourceCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'source_customer_id');
    }

    public function targetCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'target_customer_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
