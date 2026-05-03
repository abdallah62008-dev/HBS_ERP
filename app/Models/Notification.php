<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    public $timestamps = false;

    public const TYPES = [
        'Low Stock', 'Delayed Shipment', 'High Risk Customer',
        'Unprofitable Campaign', 'Pending Collection', 'Approval Needed',
        'Backup Failed', 'New Order', 'Profit Guard Block',
    ];

    protected $fillable = [
        'user_id', 'role_id', 'title', 'message', 'type',
        'action_url', 'read_at', 'created_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Notifications visible to a user: addressed to them directly OR
     * broadcast to their role.
     */
    public function scopeForUser(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('role_id', $user->role_id);
        });
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
