<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NutritionRecentFood extends Model
{
    protected $table = 'nutrition_recent_foods'; // "food" incontable en el inflector

    protected $fillable = ['member_id', 'food_id', 'last_used_at', 'use_count'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'use_count' => 'integer',
    ];

    public function food()
    {
        return $this->belongsTo(NutritionFood::class, 'food_id');
    }
}
