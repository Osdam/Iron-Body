<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class NutritionEntry extends Model
{
    protected $fillable = [
        'uuid', 'member_id', 'food_id', 'meal_type', 'entry_date',
        'quantity', 'unit', 'serving_multiplier',
        'calories', 'protein', 'carbs', 'fat',
        'sugar', 'fiber', 'sodium', 'saturated_fat', 'notes',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'quantity' => 'float', 'serving_multiplier' => 'float',
        'calories' => 'float', 'protein' => 'float', 'carbs' => 'float', 'fat' => 'float',
        'sugar' => 'float', 'fiber' => 'float', 'sodium' => 'float', 'saturated_fat' => 'float',
    ];

    public const MEAL_TYPES = ['breakfast', 'lunch', 'dinner', 'snack'];

    protected static function booted(): void
    {
        static::creating(fn (NutritionEntry $e) => $e->uuid ??= (string) Str::uuid());
    }

    public function food()
    {
        return $this->belongsTo(NutritionFood::class, 'food_id');
    }

    public function member()
    {
        return $this->belongsTo(Member::class);
    }
}
