<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una VERSIÓN INMUTABLE de una estrategia (contrato H9, D9-11.0, D9-11.1, §6.1).
 *
 * `content_json` son los BYTES EXACTOS del JSON canónico `strategy-content/1`
 * (texto, nunca `json`/`jsonb`) y `content_hash` su sha256: se leen como texto
 * y se vuelven a comprobar con `StrategyContent::fromStored()` (D9-3: lo que
 * el catálogo vigente ya no admite se niega, no se reinterpreta). Las columnas
 * de contenido, la autoría y la referencia del Studio no cambian nunca (este
 * modelo lanza; la base lo impide con sus disparadores, DB9).
 *
 * LOS ESTADOS son los de {@see StudioVersionStatus} (9 alcanzables, sin
 * valores nuevos, D9-11.1), en su propia tabla y con su propio CHECK. Como
 * mucho una CANARY y una PUBLISHED por estrategia (índices únicos parciales).
 *
 * CUATRO OJOS POR PERSONA (P9-4): `approved_by_person_id` ≠
 * `created_by_person_id` (y por cuenta), en el servicio, en el CHECK y en el libro.
 *
 * @property int $id
 * @property string $uuid
 * @property string $client_request_id
 * @property int $strategy_id
 * @property int $number
 * @property string $content_json
 * @property string $content_hash
 * @property string $catalog_version
 * @property string $evaluator_version
 * @property string $studio_ref_kind
 * @property string|null $studio_version_uuid
 * @property string $studio_content_hash
 * @property string $status
 * @property array<string, mixed>|null $validation_json
 * @property string|null $simulation_uuid
 * @property string|null $simulation_hash
 * @property int $created_by_admin_id
 * @property int $created_by_person_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property int|null $approved_by_admin_id
 * @property string|null $approved_by_name
 * @property string|null $approved_by_role
 * @property int|null $approved_by_person_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudioStrategy|null $strategy
 * @property-read StudioStrategyReview|null $review
 */
class StudioStrategyVersion extends Model
{
    /** Lo único que cambia de una versión: su estado, la aprobación (una vez) y la simulación que la habilitó. */
    public const MUTABLE = [
        'status', 'simulation_uuid', 'simulation_hash', 'approved_by_admin_id', 'approved_by_name', 'approved_by_role',
        'approved_by_person_id', 'approved_at', 'status_changed_at', 'updated_at',
    ];

    public const STUDIO_REF_VERSION = 'version';

    public const STUDIO_REF_BASELINE = 'baseline';

    protected $table = 'ultron_studio_strategy_versions';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'number' => 'integer',
            'validation_json' => 'array',
            'created_by_admin_id' => 'integer',
            'created_by_person_id' => 'integer',
            'approved_by_admin_id' => 'integer',
            'approved_by_person_id' => 'integer',
            'approved_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $v): void {
            if (array_diff(array_keys($v->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('ultron_studio: el contenido de una versión de estrategia no cambia nunca.');
            }
            if ($v->getOriginal('approved_at') !== null && $v->isDirty('approved_at')) {
                throw new LogicException('ultron_studio: la aprobación de una versión se escribe una sola vez.');
            }
            if ($v->isDirty('status') && $v->status === StudioVersionStatus::Validating->value) {
                throw new LogicException('ultron_studio: VALIDATING no se guarda.');
            }
        });
        static::deleting(fn () => throw new LogicException('ultron_studio: una versión de estrategia no se borra.'));
    }

    /** @return BelongsTo<StudioStrategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(StudioStrategy::class, 'strategy_id');
    }

    /** @return HasOne<StudioStrategyReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(StudioStrategyReview::class, 'version_id');
    }

    public function statusEnum(): StudioVersionStatus
    {
        return StudioVersionStatus::from($this->status);
    }
}
