<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ImportJobRow extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'import_job_id', 'row_number', 'raw_data_json',
        'status', 'error_message',
        'created_record_type', 'created_record_id',
        'created_at',
    ];

    protected $casts = [
        'raw_data_json' => 'array',
        'created_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class, 'import_job_id');
    }

    public function createdRecord(): MorphTo
    {
        return $this->morphTo('createdRecord', 'created_record_type', 'created_record_id');
    }
}
