<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $member_id
 * @property int|null $trainer_id
 * @property float|null $weight_kg
 * @property float|null $height_cm
 * @property float|null $body_fat_pct
 * @property float|null $muscle_mass_pct
 * @property float|null $waist_cm
 * @property float|null $hip_cm
 * @property float|null $chest_cm
 * @property float|null $arm_cm
 * @property float|null $leg_cm
 * @property string|null $injuries
 * @property string|null $trainer_notes
 */
class PhysicalEvaluation extends Model
{
    protected $fillable = [
        'member_id', 'trainer_id',
        'weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct',
        'waist_cm', 'hip_cm', 'chest_cm', 'arm_cm', 'leg_cm',
        'injuries', 'trainer_notes',
    ];

    protected $casts = [
        'weight_kg' => 'float',
        'height_cm' => 'float',
        'body_fat_pct' => 'float',
        'muscle_mass_pct' => 'float',
        'waist_cm' => 'float',
        'hip_cm' => 'float',
        'chest_cm' => 'float',
        'arm_cm' => 'float',
        'leg_cm' => 'float',
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    /** Rangos válidos para adultos (deben coincidir con la validación del request). */
    public const WEIGHT_MIN = 25.0;
    public const WEIGHT_MAX = 300.0;
    public const HEIGHT_MIN = 100.0;
    public const HEIGHT_MAX = 230.0;

    /**
     * Estado del IMC para que el cliente sepa por qué no hay valor:
     *  - 'valid':        se pudo calcular.
     *  - 'missing_data': falta peso o estatura.
     *  - 'invalid_data': peso/estatura fuera de rango razonable (p. ej. 70 cm).
     */
    public function bmiStatus(): string
    {
        $w = $this->weight_kg;
        $h = $this->height_cm;
        if ($w === null || $h === null) {
            return 'missing_data';
        }
        if ($w < self::WEIGHT_MIN || $w > self::WEIGHT_MAX
            || $h < self::HEIGHT_MIN || $h > self::HEIGHT_MAX) {
            return 'invalid_data';
        }
        return 'valid';
    }

    /**
     * IMC calculado. Devuelve null salvo que peso Y estatura estén en rangos
     * adulto razonables — así NUNCA se devuelve un IMC absurdo (p. ej. 91.8 por
     * interpretar 70 cm) ni NaN/Infinity.
     *
     * IMC = pesoKg / ((estaturaCm/100)^2)
     */
    public function bmi(): ?float
    {
        if ($this->bmiStatus() !== 'valid') {
            return null;
        }
        $m = $this->height_cm / 100;
        $bmi = $this->weight_kg / ($m * $m);
        if (!is_finite($bmi)) {
            return null;
        }
        return round($bmi, 1);
    }

    /** Etiqueta del IMC, o null si no se puede calcular. */
    public function bmiLabel(): ?string
    {
        $bmi = $this->bmi();
        if ($bmi === null) {
            return null;
        }
        if ($bmi < 18.5) return 'Bajo peso';
        if ($bmi < 25) return 'Peso saludable';
        if ($bmi < 30) return 'Sobrepeso';
        return 'Obesidad';
    }

    /** Serialización pública consistente para la app y el CRM. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'member_id' => $this->member_id,
            'trainer_id' => $this->trainer_id,
            'weight_kg' => $this->weight_kg,
            'height_cm' => $this->height_cm,
            'body_fat_pct' => $this->body_fat_pct,
            'muscle_mass_pct' => $this->muscle_mass_pct,
            'waist_cm' => $this->waist_cm,
            'hip_cm' => $this->hip_cm,
            'chest_cm' => $this->chest_cm,
            'arm_cm' => $this->arm_cm,
            'leg_cm' => $this->leg_cm,
            'injuries' => $this->injuries,
            'trainer_notes' => $this->trainer_notes,
            'bmi' => $this->bmi(),
            'bmi_label' => $this->bmiLabel(),
            'bmi_status' => $this->bmiStatus(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
