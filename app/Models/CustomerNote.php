<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer C-4A — internal customer note.
 *
 * Mirrors {@see OrderNote} intentionally — same shape, same defaults.
 * Keeps the pattern uniform across the codebase so future tooling
 * (audit, search, exports) can treat both note types the same way.
 *
 * Cascade-on-delete via the FK: deleting a customer wipes the notes.
 * No soft-delete in this phase — operators can flip `is_internal` to
 * archive without removing history (future enhancement).
 */
class CustomerNote extends Model
{
    protected $fillable = [
        'customer_id', 'note', 'is_internal', 'created_by',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
