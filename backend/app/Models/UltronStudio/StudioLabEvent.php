<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioLabEvents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * El libro de SOLO INSERCIÓN del laboratorio (contrato H4 §A8): quién creó,
 * editó o archivó un escenario, lanzó, canceló o cerró una ejecución, o emitió
 * (o intentó emitir) un veredicto. Ver {@see StudioLabEvents}.
 *
 * Es un libro propio, aparte del de F/G: el laboratorio se puede retirar sin
 * tocar `ultron_studio_audit_events`. SIN columnas de IP ni de user agent.
 * Actualizar o borrar lanza aquí, y la base lo impide además
 * (`usle_forbid_*`, también contra TRUNCATE en PostgreSQL). El actor es una
 * instantánea sin FK.
 *
 * @property int $id
 * @property string $event
 * @property string $subject_type
 * @property string|null $subject_uuid
 * @property string $actor_type
 * @property int|null $actor_admin_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property string|null $from_status
 * @property string|null $to_status
 * @property string $summary
 * @property array<string, mixed>|null $diff
 * @property Carbon|null $created_at
 */
class StudioLabEvent extends Model
{
    public const UPDATED_AT = null;

    public const SUBJECT_SCENARIO = 'scenario';

    public const SUBJECT_RUN = 'run';

    public const SUBJECT_SYSTEM = 'system';

    public const SUBJECT_TYPES = [self::SUBJECT_SCENARIO, self::SUBJECT_RUN, self::SUBJECT_SYSTEM];

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    protected $table = 'ultron_studio_lab_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'actor_admin_id' => 'integer',
            'diff' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio_lab: el libro del laboratorio es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio_lab: el libro del laboratorio es de solo inserción.'));
    }
}
