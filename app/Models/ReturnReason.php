<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnReason extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
    ];
}
