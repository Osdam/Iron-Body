<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NutritionDailySummary extends Model
{
    protected $fillable = [
        'member_id', 'summary_date', 'calories', 'protein', 'carbs', 'fat',
        'sugar', 'fiber', 'sodium', 'entry_count',
    ];

    protected $casts = [
        // summary_date se guarda como 'Y-m-d' (string) para que updateOrCreate
        // case por fecha exacta sin componente horario (evita duplicados).
        'calories' => 'float', 'protein' => 'float', 'carbs' => 'float', 'fat' => 'float',
        'sugar' => 'float', 'fiber' => 'float', 'sodium' => 'float',
        'entry_count' => 'integer',
    ];
}
