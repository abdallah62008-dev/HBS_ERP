<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Attachment extends Model
{
    public $timestamps = false;

    /** Common attachment_type values used across the app */
    public const TYPE_PRE_SHIPPING_PHOTO = 'Pre Shipping Photo';
    public const TYPE_RETURN_PHOTO = 'Return Photo';
    public const TYPE_PURCHASE_INVOICE = 'Purchase Invoice';
    public const TYPE_SHIPPING_LABEL = 'Shipping Label';
    public const TYPE_PAYMENT_PROOF = 'Payment Proof';
    public const TYPE_COMPLAINT = 'Complaint Attachment';
    public const TYPE_IMPORT_FILE = 'Imported Excel';
    public const TYPE_PDF_REPORT = 'PDF Report';

    protected $fillable = [
        'related_type', 'related_id',
        'file_name', 'file_url', 'file_type', 'file_size_bytes',
        'attachment_type',
        'uploaded_by', 'created_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
