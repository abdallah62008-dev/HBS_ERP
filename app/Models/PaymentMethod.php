<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Finance Phase 2 — Payment Methods lookup.
 *
 * Describes HOW money moves (Cash, Visa, Vodafone Cash, Bank Transfer,
 * Courier COD, Amazon Wallet, Noon Wallet). Phase 2 surfaces this in
 * UI; later phases (3+) attach payment_method_id to collections /
 * expenses / refunds / payouts.
 *
 * Never hard-deleted. Retirement is `is_active = false`.
 */
class PaymentMethod extends Model
{
    public const TYPES = [
        'cash',
        'card',
        'bank_transfer',
        'digital_wallet',
        'marketplace',
        'courier_cod',
        'other',
    ];

    protected $fillable = [
        'name',
        'code',
        'type',
        'default_cashbox_id',
        'is_active',
        'description',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /* ────────────────────── Relations ────────────────────── */

    public function defaultCashbox(): BelongsTo
    {
        return $this->belongsTo(Cashbox::class, 'default_cashbox_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /* ────────────────────── Scopes ────────────────────── */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, ?string $type): Builder
    {
        if ($type && in_array($type, self::TYPES, true)) {
            $query->where('type', $type);
        }
        return $query;
    }
}
