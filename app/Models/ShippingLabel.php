<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingLabel extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'shipment_id', 'label_size',
        'tracking_number', 'barcode_value', 'qr_value', 'label_pdf_url',
        'printed_by', 'printed_at', 'created_at',
    ];

    protected $casts = [
        'printed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function printedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'printed_by');
    }
}
