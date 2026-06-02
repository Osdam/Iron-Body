<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $member_id
 * @property string $name
 * @property string|null $brand
 * @property float $calories
 * @property float $protein_g
 * @property float $carbs_g
 * @property float $fat_g
 * @property string|null $serving_label
 * @property string|null $source
 */
class NutritionFoodItem extends Model
{
    protected $fillable = [
        'member_id', 'name', 'brand', 'calories', 'protein_g', 'carbs_g', 'fat_g',
        'serving_label', 'source',
    ];

    protected $casts = [
        'calories' => 'float',
        'protein_g' => 'float',
        'carbs_g' => 'float',
        'fat_g' => 'float',
    ];

    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'brand' => $this->brand,
            'calories' => $this->calories,
            'protein_g' => $this->protein_g,
            'carbs_g' => $this->carbs_g,
            'fat_g' => $this->fat_g,
            'serving_label' => $this->serving_label,
            'source' => $this->source,
            'is_custom' => $this->member_id !== null,
        ];
    }
}
