<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Guía nutricional escrita por un entrenador para un socio.
 *
 * Sigue el ciclo de vida de {@see ProfessionalAssessment}, que ya resolvió este
 * problema: borrador editable, publicación inmutable y correcciones que crean
 * una versión nueva enlazada en vez de sobrescribir. Un plan de alimentación es
 * un documento con fecha: reescribir el de agosto porque en septiembre cambió
 * el criterio deja al socio sin saber qué siguió realmente.
 *
 * Las medidas viven en columnas propias, copiadas al publicar. No se leen de la
 * valoración: si el entrenador la corrige mañana, esta guía debe seguir
 * diciendo con qué números se escribió.
 *
 * @property int $id
 * @property string $uuid
 * @property int $member_id
 * @property int $trainer_id
 * @property int|null $parent_id
 * @property int|null $source_assessment_id
 * @property string $status
 * @property int $version
 * @property array|null $meals
 */
class NutritionGuide extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_AMENDED = 'amended';

    /**
     * Retirada SIN reemplazo.
     *
     * No es lo mismo que `amended`: una corrección deja al socio con una pauta
     * nueva y válida, mientras que anular lo deja sin ninguna. Hoy ambas exigen
     * `nutrition_guides.amend` porque quien puede reemplazar ya puede hacer
     * desaparecer la anterior; si algún día retirar sin reemplazo debe ser
     * potestad de un supervisor y no del entrenador que pautó, ahí es donde un
     * permiso propio se gana su sitio.
     */
    public const STATUS_VOIDED = 'voided';

    /**
     * Medidas que se copian de la valoración al preparar una guía.
     *
     * Las cuatro primeras coinciden con {@see ProfessionalAssessment}; el resto
     * son propias de la guía porque el entrenador las toma con la báscula de
     * composición corporal y no forman parte de la valoración.
     */
    public const ASSESSMENT_MEASUREMENTS = [
        'weight_kg', 'height_cm', 'body_fat_pct', 'muscle_mass_pct',
    ];

    public const OWN_MEASUREMENTS = ['visceral_fat', 'basal_kcal', 'age_years'];

    /** Todo lo que un entrenador diligencia mientras la guía es borrador. */
    public const CONTENT_FIELDS = [
        ...self::ASSESSMENT_MEASUREMENTS,
        ...self::OWN_MEASUREMENTS,
        'objective',
        'objective_description',
        'training_stage',
        'meals',
        'recommendations',
        'restrictions',
        'supplements',
        'notes',
    ];

    protected $fillable = [
        'uuid',
        'member_id',
        'trainer_id',
        'parent_id',
        'source_assessment_id',
        'trainer_type',
        'status',
        'version',
        ...self::CONTENT_FIELDS,
        'amendment_reason',
        'void_reason',
        'published_at',
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
            'visceral_fat' => 'decimal:2',
            'basal_kcal' => 'integer',
            'age_years' => 'integer',
            'meals' => 'array',
            'published_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NutritionGuide $guide): void {
            $guide->uuid ??= (string) Str::uuid();
        });
    }

    /** Enrutado por uuid: los ids autoincrementales no salen a la API. */
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

    public function sourceAssessment(): BelongsTo
    {
        return $this->belongsTo(ProfessionalAssessment::class, 'source_assessment_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /** Una guía publicada no se toca: se corrige creando una versión nueva. */
    public function isImmutable(): bool
    {
        return in_array($this->status, [self::STATUS_PUBLISHED, self::STATUS_AMENDED], true);
    }

    public function scopeForMember(Builder $query, int $memberId): Builder
    {
        return $query->where('member_id', $memberId);
    }

    /**
     * Lo que el socio puede ver: publicadas y corregidas.
     *
     * Un borrador es trabajo en curso del entrenador y una anulada dejó de ser
     * válida. Ninguno de los dos llega al socio, y tampoco a Iron IA.
     */
    public function scopeVisibleToMember(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PUBLISHED, self::STATUS_AMENDED]);
    }

    /**
     * La guía VIGENTE de un socio: la última publicada.
     *
     * `published` y no `amended`: cuando una corrección entra, la anterior pasa
     * a `amended` y deja de ser la vigente aunque siga consultable.
     */
    public static function latestPublishedFor(int $memberId): ?self
    {
        return self::query()
            ->where('member_id', $memberId)
            ->where('status', self::STATUS_PUBLISHED)
            ->orderByDesc('published_at')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * El plan de comidas, normalizado y en orden.
     *
     * Se saneia al leer porque el JSON pudo escribirse por una versión anterior
     * de la app: una comida sin etiqueta o con `order` ausente no debe romper la
     * pantalla del socio.
     *
     * @return list<array{label: string, time: string|null, description: string, order: int}>
     */
    public function orderedMeals(): array
    {
        $meals = is_array($this->meals) ? $this->meals : [];

        $limpias = [];
        foreach ($meals as $i => $meal) {
            if (! is_array($meal)) {
                continue;
            }
            $label = trim((string) ($meal['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $limpias[] = [
                'label' => $label,
                'time' => isset($meal['time']) && trim((string) $meal['time']) !== ''
                    ? trim((string) $meal['time'])
                    : null,
                'description' => trim((string) ($meal['description'] ?? '')),
                'order' => isset($meal['order']) ? (int) $meal['order'] : $i,
            ];
        }

        usort($limpias, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return array_values($limpias);
    }
}
