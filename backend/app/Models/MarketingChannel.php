<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingChannel extends Model
{
    protected $fillable = ['name', 'type', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
