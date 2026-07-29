<?php

namespace App\Models;

use App\Services\Billing\PricingMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        // Facturación electrónica (aditivo).
        'tax_rate_id',
        'price_includes_tax',
        'unspsc_code',
        // Pricing V2.
        'pricing_mode',
        'billing_enabled',
    ];

    protected $casts = [
        'price' => 'float',
        'original_price' => 'float',
        'is_recommended' => 'boolean',
        'access_classes' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
        'features' => 'array',
        'price_includes_tax' => 'boolean',
        'billing_enabled' => 'boolean',
    ];

    /** Segmentos comerciales disponibles para un plan. */
    public const TIERS = ['lite', 'pro', 'premium'];

    /** Tarifa de IVA del plan (facturación electrónica). */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * ¿Este plan genera un comprobante fiscal?
     *
     * Un plan gratuito (precio 0) o marcado explícitamente como no facturable
     * (billing_enabled=false) NO exige tratamiento tributario: es un plan de
     * acceso, no una venta. Es el caso del plan Demo App Review, que debe seguir
     * funcionando sin recibir una clasificación tributaria inventada y sin
     * bloquear el diagnóstico de Factus.
     *
     * `null` cuenta como facturable: es el default de la columna, y un registro
     * recién creado (o anterior a la migración) no debe escaparse del control
     * tributario por no tener el valor materializado todavía.
     */
    public function isBillable(): bool
    {
        return ($this->billing_enabled ?? true) && (float) $this->price > 0;
    }

    /** Semántica del precio configurado (ver App\Services\Billing\PricingMode). */
    public function pricingMode(): PricingMode
    {
        return PricingMode::fromValue($this->pricing_mode);
    }

    public static function defaultFeatures(): array
    {
        return [
            'iron_ia' => false,
            'workouts' => true,
            'custom_routines' => false,
            'ranking' => false,
            'classes' => false,
            'progress' => true,
            'nutrition' => false,
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
