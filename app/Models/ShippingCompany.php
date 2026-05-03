<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingCompany extends Model
{
    protected $fillable = [
        'name', 'contact_name', 'phone', 'email',
        'api_enabled', 'api_config_json', 'status',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'api_enabled' => 'boolean',
        'api_config_json' => 'array',
    ];

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }
}
