<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupLog extends Model
{
    public $timestamps = false;

    public const TYPES = ['Database', 'Files', 'Full Backup'];

    protected $fillable = [
        'backup_type', 'file_url', 'status', 'size', 'notes',
        'created_by', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isSuccess(): bool
    {
        return $this->status === 'Success';
    }
}
