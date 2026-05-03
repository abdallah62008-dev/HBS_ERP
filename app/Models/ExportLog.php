<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'export_type', 'filters_json', 'file_url',
        'rows_count', 'exported_by', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'filters_json' => 'array',
        'rows_count' => 'integer',
        'created_at' => 'datetime',
    ];

    public function exportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
