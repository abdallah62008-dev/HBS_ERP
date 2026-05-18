<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'primary_phone', 'secondary_phone', 'primary_phone_whatsapp', 'email',
        // O-2: phone normalization triple. The legacy `primary_phone` +
        // `secondary_phone` columns stay as display/back-compat fields.
        'country_code', 'local_phone', 'normalized_phone',
        'secondary_country_code', 'secondary_local_phone', 'secondary_normalized_phone',
        'city', 'governorate', 'country', 'default_address',
        'risk_score', 'risk_level', 'customer_type', 'notes',
        'created_by', 'updated_by', 'deleted_by',
        // C-5B: duplicate-merge tombstone fields.
        'merged_into_customer_id', 'merged_at', 'merged_by',
    ];

    protected $casts = [
        'risk_score' => 'integer',
        'primary_phone_whatsapp' => 'boolean',
        'merged_at' => 'datetime',
    ];

    /**
     * WhatsApp click-to-chat URL based on the normalized primary phone.
     * Returns null when no normalized number is available or when the
     * customer has explicitly opted out via `primary_phone_whatsapp`.
     */
    public function whatsappUrl(): ?string
    {
        if (! $this->primary_phone_whatsapp) return null;
        $waNumber = \App\Services\PhoneNormalizationService::toWhatsappFormat($this->normalized_phone);
        return $waNumber ? "https://wa.me/{$waNumber}" : null;
    }

    /* Relationships */

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(CustomerTag::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * C-5B: pointer to the surviving customer when this row was merged
     * away. Null on every "live" customer.
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'merged_into_customer_id');
    }

    public function mergedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }

    /**
     * Merges where this customer was the SOURCE (got merged away).
     * Usually zero or one row.
     */
    public function sourceMerges(): HasMany
    {
        return $this->hasMany(CustomerMerge::class, 'source_customer_id');
    }

    /**
     * Merges where this customer was the TARGET (survivor). Can be
     * many — a "magnet" customer can absorb several duplicates.
     */
    public function targetMerges(): HasMany
    {
        return $this->hasMany(CustomerMerge::class, 'target_customer_id');
    }

    /**
     * Convenience boolean — true when this customer was merged into
     * another and is now a tombstone.
     */
    public function isMerged(): bool
    {
        return $this->merged_into_customer_id !== null;
    }

    /**
     * C-4A: structured customer notes (separate from the legacy
     * `customers.notes` text column).
     *
     * IMPORTANT — the relation is NOT named `notes()` because that
     * collides with the `notes` text column on the customers table.
     * Eloquent would silently shadow the attribute and the relation
     * would compete with `$customer->notes` writes. `customerNotes()`
     * keeps both surfaces accessible.
     */
    public function customerNotes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* Helpers */

    public function isBlacklisted(): bool
    {
        return $this->customer_type === 'Blacklist';
    }

    public function isHighRisk(): bool
    {
        return $this->risk_level === 'High' || $this->isBlacklisted();
    }
}
