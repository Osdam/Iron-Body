<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioLabVerdicts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * El veredicto de UNA PERSONA sobre una ejecución del laboratorio (contrato H4
 * §A6): `approved`, `rejected` o `needs_changes`, sobre la huella de
 * resultados que vio y la huella del contenido de la versión.
 *
 * Uno por ejecución (UNIQUE `run_id`) y de SOLO INSERCIÓN: actualizar o borrar
 * lanza aquí, y la base lo impide además (`uslv_forbid_*`). Solo lo inserta
 * {@see StudioLabVerdicts}, desde la ruta del veredicto: nada aprueba solo (ni
 * el runner, ni el job, ni el comando nombran esta clase; una prueba lo
 * vigila). El revisor es una instantánea sin FK.
 *
 * Relación con F: ninguna en este hito. No cambia la versión ni el motor.
 *
 * @property int $id
 * @property int $run_id
 * @property int $version_id
 * @property string $version_content_hash
 * @property string $results_hash
 * @property string $decision
 * @property string|null $note
 * @property list<string>|null $acknowledged_cases
 * @property int $reviewer_admin_id
 * @property string $reviewer_name
 * @property string|null $reviewer_role
 * @property Carbon|null $created_at
 */
class StudioLabVerdict extends Model
{
    public const UPDATED_AT = null;

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_NEEDS_CHANGES = 'needs_changes';

    public const DECISIONS = [self::DECISION_APPROVED, self::DECISION_REJECTED, self::DECISION_NEEDS_CHANGES];

    protected $table = 'ultron_studio_lab_verdicts';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'version_id' => 'integer',
            'acknowledged_cases' => 'array',
            'reviewer_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $verdict): void {
            if (! in_array($verdict->decision, self::DECISIONS, true)) {
                throw new LogicException('ultron_studio_lab: decisión de veredicto desconocida.');
            }
        });
        static::updating(fn () => throw new LogicException('ultron_studio_lab: los veredictos son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio_lab: los veredictos son de solo inserción.'));
    }

    /** @return BelongsTo<StudioLabRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(StudioLabRun::class, 'run_id');
    }
}
