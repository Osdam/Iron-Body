<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExerciseAlias extends Model
{
    protected $fillable = [
        'alias_name',
        'normalized_alias',
        'exercise_id',
        'source',
        'confidence',
        'is_verified',
        'notes',
    ];

    protected $casts = [
        'confidence'  => 'float',
        'is_verified' => 'boolean',
        'exercise_id' => 'integer',
    ];

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
