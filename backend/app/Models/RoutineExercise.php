<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineExercise extends Model
{
    protected $fillable = [
        'routine_id',
        'exercise_id',
        'sets',
        'reps',
        'weight',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'sets'       => 'integer',
        'sort_order' => 'integer',
    ];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }
}
