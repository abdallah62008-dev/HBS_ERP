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
        'name', 'primary_phone', 'secondary_phone', 'email',
        'city', 'governorate', 'country', 'default_address',
        'risk_score', 'risk_level', 'customer_type', 'notes',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'risk_score' => 'integer',
    ];

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
