<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property int $daily_calories
 * @property int $protein_g
 * @property int $carbs_g
 * @property int $fat_g
 * @property string|null $goal_type
 * @property bool $is_active
 */
class NutritionGoal extends Model
{
    protected $fillable = [
        'member_id', 'daily_calories', 'protein_g', 'carbs_g', 'fat_g',
        'goal_type', 'is_active',
    ];

    protected $casts = [
        'daily_calories' => 'integer',
        'protein_g' => 'integer',
        'carbs_g' => 'integer',
        'fat_g' => 'integer',
        'is_active' => 'boolean',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function toPublicArray(): array
    {
        return [
            'daily_calories' => $this->daily_calories,
            'protein_g' => $this->protein_g,
            'carbs_g' => $this->carbs_g,
            'fat_g' => $this->fat_g,
            'goal_type' => $this->goal_type,
        ];
    }
}
