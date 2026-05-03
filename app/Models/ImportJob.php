<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    public $timestamps = false;

    public const STATUSES = [
        'Uploaded', 'Validating', 'Ready', 'Processing',
        'Completed', 'Failed', 'Undone',
    ];

    protected $fillable = [
        'import_type', 'original_file_name', 'file_url', 'status',
        'total_rows', 'successful_rows', 'failed_rows', 'duplicate_rows',
        'error_report_url', 'can_undo',
        'created_by', 'created_at', 'completed_at',
        'undone_at', 'undone_by',
    ];

    protected $casts = [
        'can_undo' => 'boolean',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'undone_at' => 'datetime',
        'total_rows' => 'integer',
        'successful_rows' => 'integer',
        'failed_rows' => 'integer',
        'duplicate_rows' => 'integer',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ImportJobRow::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function undoneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'undone_by');
    }

    public function isReady(): bool
    {
        return $this->status === 'Ready';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'Completed';
    }
}
