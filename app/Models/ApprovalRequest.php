<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    public const STATUSES = ['Pending', 'Approved', 'Rejected'];

    /** Approval types we currently dispatch handlers for. Add to ApprovalService::HANDLERS when extending. */
    public const TYPES = [
        'Delete Order',
        'Edit Confirmed Order Price',
        'Manual Inventory Adjustment',
        'Delete Expense',
        'Approve Return',
        'Pay Marketer',
        'Edit Approved Purchase Invoice',
        'Edit Closed Fiscal Year Record',
    ];

    protected $fillable = [
        'requested_by', 'approval_type',
        'related_type', 'related_id',
        'old_values_json', 'new_values_json',
        'reason', 'status',
        'reviewed_by', 'reviewed_at', 'review_notes',
    ];

    protected $casts = [
        'old_values_json' => 'array',
        'new_values_json' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'Pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'Pending';
    }
}
