<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'setting_group',
        'setting_key',
        'setting_value',
        'value_type',
    ];
}
