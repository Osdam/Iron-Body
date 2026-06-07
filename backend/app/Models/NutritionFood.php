<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Alimento del módulo nutricional premium. Macros normalizados por 100g y por
 * porción. `source` indica el origen (iron_body|open_food_facts|usda|nutritionix
 * |user|ocr). Caché de proveedores externos vive aquí (anti rate-limit).
 */
class NutritionFood extends Model
{
    use SoftDeletes;

    // "food" es incontable para el inflector de Laravel → fijamos la tabla.
    protected $table = 'nutrition_foods';

    protected $fillable = [
        'uuid', 'source', 'external_id', 'barcode', 'name', 'normalized_name',
        'brand', 'category', 'image_url', 'serving_size', 'serving_unit',
        'package_quantity', 'package_unit',
        'calories_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g',
        'sugar_per_100g', 'fiber_per_100g', 'sodium_per_100g', 'saturated_fat_per_100g',
        'calories_per_serving', 'protein_per_serving', 'carbs_per_serving', 'fat_per_serving',
        'sugar_per_serving', 'fiber_per_serving', 'sodium_per_serving',
        'verified', 'confidence_score', 'created_by_member_id', 'is_public',
        'raw_payload', 'last_synced_at',
    ];

    protected $casts = [
        'serving_size' => 'float', 'package_quantity' => 'float',
        'calories_per_100g' => 'float', 'protein_per_100g' => 'float',
        'carbs_per_100g' => 'float', 'fat_per_100g' => 'float',
        'sugar_per_100g' => 'float', 'fiber_per_100g' => 'float',
        'sodium_per_100g' => 'float', 'saturated_fat_per_100g' => 'float',
        'calories_per_serving' => 'float', 'protein_per_serving' => 'float',
        'carbs_per_serving' => 'float', 'fat_per_serving' => 'float',
        'sugar_per_serving' => 'float', 'fiber_per_serving' => 'float',
        'sodium_per_serving' => 'float',
        'verified' => 'boolean', 'is_public' => 'boolean',
        'confidence_score' => 'float',
        'raw_payload' => 'array', 'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (NutritionFood $f) {
            $f->uuid ??= (string) Str::uuid();
            if ($f->name && ! $f->normalized_name) {
                $f->normalized_name = static::normalize($f->name);
            }
        });
    }

    public static function normalize(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        return preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $n)) ?: $n;
    }

    public function creator()
    {
        return $this->belongsTo(Member::class, 'created_by_member_id');
    }

    /** Formato unificado de alimento para la app (sin raw_payload ni internos). */
    public function toApiArray(): array
    {
        $round = fn ($v) => $v === null ? null : round((float) $v, 1);
        return [
            'uuid'             => $this->uuid,
            'source'           => $this->source,
            'barcode'          => $this->barcode,
            'name'             => $this->name,
            'brand'            => $this->brand,
            'category'         => $this->category,
            'image_url'        => $this->image_url,
            'serving_size'     => $this->serving_size,
            'serving_unit'     => $this->serving_unit ?: 'g',
            'verified'         => (bool) $this->verified,
            'confidence_score' => $this->confidence_score,
            'is_custom'        => $this->source === 'user',
            'per_100g'         => [
                'calories' => $round($this->calories_per_100g),
                'protein'  => $round($this->protein_per_100g),
                'carbs'    => $round($this->carbs_per_100g),
                'fat'      => $round($this->fat_per_100g),
                'sugar'    => $round($this->sugar_per_100g),
                'fiber'    => $round($this->fiber_per_100g),
                'sodium'   => $round($this->sodium_per_100g),
            ],
            'per_serving'      => [
                'calories' => $round($this->calories_per_serving),
                'protein'  => $round($this->protein_per_serving),
                'carbs'    => $round($this->carbs_per_serving),
                'fat'      => $round($this->fat_per_serving),
                'sugar'    => $round($this->sugar_per_serving),
                'fiber'    => $round($this->fiber_per_serving),
                'sodium'   => $round($this->sodium_per_serving),
            ],
        ];
    }
}
