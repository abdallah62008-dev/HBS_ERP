<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Orders & Products P-1 — Channel SKU mapping.
 *
 * Maps one product variant to one external marketplace SKU. See
 * docs/orders-products/CHANNEL_SKU_AND_MARKETPLACE_MAPPING.md.
 *
 * Channel is validated against {@see self::CHANNELS} at the request
 * layer (not a DB constraint) so adding a new marketplace later is a
 * code-only change.
 *
 * Soft retire only: rows are never hard-deleted from the form. To stop
 * using a mapping, ops flips `is_active = false`. This preserves the
 * audit trail and prevents the unique `(variant, channel)` index from
 * blocking a re-import after a mistaken delete.
 */
class ProductChannelSku extends Model
{
    /**
     * Permitted values for {@see self::channel}. Adding a marketplace
     * here is the entire change required — no DB migration, no
     * controller change. Order is the display order in the form's
     * dropdown.
     *
     * @var array<int, string>
     */
    public const CHANNELS = [
        'Internal',
        'Website',
        'Amazon',
        'Noon',
        'Jumia',
        'Supplier',
        'Other',
    ];

    protected $fillable = [
        'product_variant_id',
        'channel',
        'external_sku',
        'external_barcode',
        'external_url',
        'is_active',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
