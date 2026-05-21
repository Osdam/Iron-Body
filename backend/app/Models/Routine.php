<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
{
    protected $fillable = [
        'name',
        'objective',
        'level',
        'muscle_group',
        'estimated_minutes',
        'duration_minutes',
        'days_per_week',
        'trainer_name',
        'trainer_id',
        'assigned_member_name',
        'assigned_member_id',
        'is_assigned',
        'member_id',
        'created_by_admin',
        'status',
        'description',
        'notes',
        'exercises',
    ];

    protected $casts = [
        'exercises'         => 'array',
        'duration_minutes'  => 'integer',
        'estimated_minutes' => 'integer',
        'days_per_week'     => 'integer',
        'is_assigned'       => 'boolean',
        'created_by_admin'  => 'boolean',
    ];

    public function routineExercises(): HasMany
    {
        return $this->hasMany(RoutineExercise::class)->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(MemberRoutineAssignment::class);
    }
}
