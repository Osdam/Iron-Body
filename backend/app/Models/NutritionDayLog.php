<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NutritionDayLog extends Model
{
    protected $fillable = [
        'member_id',
        'log_date',
        'calories',
        'protein',
        'carbs',
        'fat',
        'goal_calories',
        'goal_protein',
        'goal_met',
        'source',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'log_date'     => 'date',
            'calories'     => 'float',
            'protein'      => 'float',
            'carbs'        => 'float',
            'fat'          => 'float',
            'goal_calories'=> 'float',
            'goal_protein' => 'float',
            'goal_met'     => 'boolean',
            'metadata'     => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
