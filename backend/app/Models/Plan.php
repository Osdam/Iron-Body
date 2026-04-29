<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'price', 'duration_days', 'benefits', 'access_classes', 'reservations_limit', 'access_locations', 'restrictions', 'active'
    ];

    protected $casts = [
        'price' => 'float',
        'access_classes' => 'boolean',
        'active' => 'boolean',
    ];
}
