<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Valoración profesional creada por un entrenador. Ver la migración para el ciclo
 * de vida (draft→submitted→amended→voided). Una valoración `submitted` es
 * INMUTABLE: solo `amend` (nueva versión) o `void` (anulación) la modifican.
 */
class ProfessionalAssessment extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_AMENDED = 'amended';

    public const STATUS_VOIDED = 'voided';

    /** Campos de medidas editables por el entrenador mientras es borrador. */
    public const MEASUREMENT_FIELDS = [
        'weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct',
        'waist_cm', 'hip_cm', 'chest_cm', 'arm_cm', 'leg_cm',
    ];

    protected $fillable = [
        'uuid',
        'member_id',
        'trainer_id',
        'parent_id',
        'trainer_type',
        'location',
        'status',
        'version',
        'weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct',
        'waist_cm', 'hip_cm', 'chest_cm', 'arm_cm', 'leg_cm',
        'observations',
        'recommendations',
        'amendment_reason',
        'void_reason',
        'submitted_at',
        'acknowledged_at',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'weight_kg' => 'decimal:2',
            'height_cm' => 'decimal:2',
            'body_fat_pct' => 'decimal:2',
            'muscle_mass_pct' => 'decimal:2',
            'waist_cm' => 'decimal:2',
            'hip_cm' => 'decimal:2',
            'chest_cm' => 'decimal:2',
            'arm_cm' => 'decimal:2',
            'leg_cm' => 'decimal:2',
            'submitted_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ProfessionalAssessment $assessment): void {
            $assessment->uuid ??= (string) Str::uuid();
        });
    }

    /** Enrutado por uuid (no exponer ids autoincrementales). */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Una valoración enviada es inmutable: solo se enmienda o anula. */
    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_SUBMITTED, self::STATUS_AMENDED], true);
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    /** Valoraciones visibles para el miembro: enviadas o enmendadas (no borradores ni anuladas). */
    public function scopeVisibleToMember(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_AMENDED]);
    }
}
