<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Routine extends Model
{
    protected $fillable = [
        'name',
        'objective',
        'level',
        'duration_minutes',
        'days_per_week',
        'trainer_name',
        'trainer_id',
        'assigned_member_name',
        'assigned_member_id',
        'status',
        'description',
        'notes',
        'exercises',
    ];

    protected $casts = [
        'exercises' => 'array',
        'duration_minutes' => 'integer',
        'days_per_week' => 'integer',
    ];
}
