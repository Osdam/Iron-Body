<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $meal_log_id
 * @property int|null $food_item_id
 * @property string|null $custom_name
 * @property float $quantity
 * @property string|null $serving_label
 * @property float $calories
 * @property float $protein_g
 * @property float $carbs_g
 * @property float $fat_g
 */
class NutritionMealItem extends Model
{
    protected $fillable = [
        'meal_log_id', 'food_item_id', 'custom_name', 'quantity', 'serving_label',
        'calories', 'protein_g', 'carbs_g', 'fat_g',
    ];

    protected $casts = [
        'quantity' => 'float',
        'calories' => 'float',
        'protein_g' => 'float',
        'carbs_g' => 'float',
        'fat_g' => 'float',
    ];

    public function mealLog(): BelongsTo
    {
        return $this->belongsTo(NutritionMealLog::class, 'meal_log_id');
    }

    public function foodItem(): BelongsTo
    {
        return $this->belongsTo(NutritionFoodItem::class, 'food_item_id');
    }

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'food_item_id' => $this->food_item_id,
            'name' => $this->custom_name ?? $this->foodItem?->name ?? 'Alimento',
            'quantity' => $this->quantity,
            'serving_label' => $this->serving_label,
            'calories' => $this->calories,
            'protein_g' => $this->protein_g,
            'carbs_g' => $this->carbs_g,
            'fat_g' => $this->fat_g,
        ];
    }
}
