<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseInvoice extends Model
{
    use SoftDeletes;

    /** Statuses that mean the invoice has actually been received (and inventory was added). */
    public const RECEIVED_STATUSES = ['Received', 'Partially Received', 'Paid', 'Partially Paid', 'Unpaid'];

    /** Statuses that lock the invoice from edits without an approval. */
    public const LOCKED_STATUSES = ['Received', 'Partially Received', 'Paid', 'Partially Paid', 'Cancelled'];

    protected $fillable = [
        'invoice_number', 'supplier_id', 'warehouse_id', 'invoice_date', 'status',
        'subtotal', 'discount_amount', 'tax_amount', 'shipping_cost', 'total_amount',
        'paid_amount', 'remaining_amount',
        'attachment_url', 'notes',
        'created_by', 'updated_by', 'approved_by', 'approved_at', 'deleted_by',
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SupplierPayment::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'Draft';
    }

    public function isReceived(): bool
    {
        return in_array($this->status, self::RECEIVED_STATUSES, true);
    }
}
