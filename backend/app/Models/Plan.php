<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'tier',
        'price',
        'original_price',
        'duration_days',
        'benefits',
        'is_recommended',
        'badge',
        'sort_order',
        'access_classes',
        'reservations_limit',
        'access_locations',
        'restrictions',
        'active',
        'features',
    ];

    protected $casts = [
        'price' => 'float',
        'original_price' => 'float',
        'is_recommended' => 'boolean',
        'access_classes' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
    ];

    /** Segmentos comerciales disponibles para un plan. */
    public const TIERS = ['lite', 'pro', 'premium'];

    public static function defaultFeatures(): array
    {
        return [
            'iron_ia'         => false,
            'workouts'        => true,
            'custom_routines' => false,
            'ranking'         => false,
            'classes'         => false,
            'progress'        => true,
            'nutrition'       => false,
        ];
    }

    public function resolvedFeatures(): array
    {
        $stored = is_array($this->features) ? $this->features : [];
        return array_merge(self::defaultFeatures(), $stored);
    }

    public function getMonthsAttribute(): int
    {
        return max(1, (int) round(((int) $this->duration_days) / 30));
    }

    public function getPeriodAttribute(): string
    {
        $months = $this->months;

        return $months === 1 ? '1 mes' : "{$months} meses";
    }

    public function benefitsArray(): array
    {
        $benefits = $this->benefits;

        if (is_array($benefits)) {
            return $this->cleanBenefits($benefits);
        }

        if (! is_string($benefits) || trim($benefits) === '') {
            return [];
        }

        $decoded = json_decode($benefits, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->cleanBenefits($decoded);
        }

        return $this->cleanBenefits(preg_split('/\r\n|\r|\n|,/', $benefits) ?: []);
    }

    private function cleanBenefits(array $benefits): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $benefit): string => trim((string) $benefit),
            $benefits
        )));
    }
}
