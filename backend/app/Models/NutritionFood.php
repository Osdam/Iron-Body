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
        'country', 'stores', 'normalized_brand', 'normalized_store',
        'imported_region', 'imported_priority_score',
        'calories_per_100g', 'protein_per_100g', 'carbs_per_100g', 'fat_per_100g',
        'sugar_per_100g', 'fiber_per_100g', 'sodium_per_100g', 'saturated_fat_per_100g',
        'calories_per_serving', 'protein_per_serving', 'carbs_per_serving', 'fat_per_serving',
        'sugar_per_serving', 'fiber_per_serving', 'sodium_per_serving',
        'verified', 'confidence_score', 'created_by_member_id', 'is_public',
        'raw_payload', 'last_synced_at',
        'visibility', 'verification_status', 'verified_by_admin_id', 'verified_at',
        'community_confirmations_count', 'community_votes_count', 'reports_count',
        'canonical_food_id', 'version',
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
        'imported_priority_score' => 'integer',
        'community_confirmations_count' => 'integer',
        'community_votes_count' => 'integer',
        'reports_count' => 'integer',
        'version' => 'integer',
        'verified_at' => 'datetime',
        'raw_payload' => 'array', 'last_synced_at' => 'datetime',
    ];

    // Visibilidad de un alimento creado por el usuario.
    public const VIS_PRIVATE = 'private';     // solo su creador
    public const VIS_COMMUNITY = 'community'; // aporta a la base de todos
    public const VIS_VERIFIED = 'verified';   // revisado por staff

    // Estado de verificación (calidad de datos).
    public const VS_PRIVATE = 'private';
    public const VS_PENDING = 'pending';
    public const VS_COMMUNITY = 'community';
    public const VS_VERIFIED = 'verified';
    public const VS_REJECTED = 'rejected';

    /** Umbral de reportes para ocultar un alimento no verificado de búsquedas. */
    public static function reportsHideThreshold(): int
    {
        return (int) config('nutrition.community.reports_hide_threshold', 3);
    }

    /**
     * Alimentos visibles en búsquedas generales: excluye rechazados y los muy
     * reportados que aún no están verificados. NO oculta los del propio creador.
     */
    public function scopeVisibleInSearch($query, Member $member)
    {
        $threshold = self::reportsHideThreshold();
        return $query->where(function ($q) use ($member, $threshold) {
            $q->where('created_by_member_id', $member->id) // lo propio SIEMPRE visible
                ->orWhere(function ($pub) use ($threshold) {
                    $pub->where('is_public', true)
                        ->where('verification_status', '!=', self::VS_REJECTED)
                        ->whereNull('canonical_food_id') // los fusionados no aparecen
                        ->where(function ($rep) use ($threshold) {
                            $rep->where('reports_count', '<', $threshold)
                                ->orWhere('verification_status', self::VS_VERIFIED);
                        });
                });
        });
    }

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

    /** Macros base requeridos para considerar un alimento "completo". */
    public const CORE_MACROS = ['calories', 'protein', 'carbs', 'fat'];

    /** Lista de macros base ausentes (null o todos en 0 → faltan datos). */
    public function missingMacros(): array
    {
        $vals = [
            'calories' => $this->calories_per_100g,
            'protein'  => $this->protein_per_100g,
            'carbs'    => $this->carbs_per_100g,
            'fat'      => $this->fat_per_100g,
        ];
        // Si los 4 base son null o 0, se considera que faltan TODOS (no es válido
        // mostrar 0 como real para un alimento normal).
        $allZeroOrNull = true;
        foreach ($vals as $v) {
            if ($v !== null && (float) $v > 0) {
                $allZeroOrNull = false;
                break;
            }
        }
        if ($allZeroOrNull) {
            return self::CORE_MACROS;
        }
        $missing = [];
        foreach ($vals as $k => $v) {
            if ($v === null) {
                $missing[] = $k;
            }
        }
        return $missing;
    }

    /** ¿Tiene los 4 macros base con datos reales (calorías > 0)? */
    public function isMacroComplete(): bool
    {
        return $this->missingMacros() === [];
    }

    /** Alias semántico. */
    public function hasCompleteMacros(): bool
    {
        return $this->isMacroComplete();
    }

    /** Etiqueta de calidad para la app (no certifica si no está verificado). */
    public function communityLabel(): ?string
    {
        return match ($this->verification_status) {
            self::VS_VERIFIED  => 'Verificado Iron Body',
            self::VS_COMMUNITY => 'Aportado por la comunidad',
            self::VS_REJECTED  => null,
            default => $this->source === 'user' ? 'Creado por ti' : null,
        };
    }

    /** Formato unificado de alimento para la app (sin raw_payload ni internos). */
    public function toApiArray(): array
    {
        $round = fn ($v) => $v === null ? null : round((float) $v, 1);
        $missing = $this->missingMacros();
        $isComplete = $missing === [];
        $warnings = [];
        if (! $isComplete) {
            $warnings[] = 'Faltan datos nutricionales de este producto.';
        }
        // Badges de cadenas colombianas detectadas (D1/Éxito/Olímpica/Ara…).
        $retailers = $this->stores
            ? app(\App\Services\Nutrition\NutritionColombiaClassifier::class)->matchedRetailers($this->stores)
            : [];
        $isColombia = $this->country === 'colombia'
            || $retailers !== []
            || (int) ($this->imported_priority_score ?? 0) > 0;

        return [
            'is_complete'      => $isComplete,
            'missing_macros'   => $missing,
            'warnings'         => $warnings,
            'uuid'             => $this->uuid,
            'source'           => $this->source,
            'barcode'          => $this->barcode,
            'name'             => $this->name,
            'brand'            => $this->brand,
            'category'         => $this->category,
            'country'          => $this->country,
            'is_colombia'      => $isColombia,
            'retailers'        => $retailers,
            // Base comunitaria: estado de calidad para los badges de la app.
            'visibility'           => $this->visibility,
            'verification_status'  => $this->verification_status,
            'is_verified_iron_body' => $this->verification_status === self::VS_VERIFIED,
            'is_community'         => $this->visibility === self::VIS_COMMUNITY
                || $this->verification_status === self::VS_COMMUNITY,
            'community_label'      => $this->communityLabel(),
            'community_confirmations' => (int) ($this->community_confirmations_count ?? 0),
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
