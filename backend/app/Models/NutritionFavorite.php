<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NutritionFavorite extends Model
{
    protected $fillable = ['member_id', 'food_id'];

    public function food()
    {
        return $this->belongsTo(NutritionFood::class, 'food_id');
    }
}
