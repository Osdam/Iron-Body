<?php

namespace App\Models\UltronStudio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una SIMULACIÓN de una estrategia (contrato H9, D9-12, §6.1): de SOLO
 * INSERCIÓN. Guarda la huella de la entrada, la del resultado, los recuentos y
 * los hallazgos (≤ 16 KB), NUNCA trazas por persona ni datos reales: la
 * respuesta de la API lleva las trazas de ejemplo y no se guardan.
 *
 * `version_id` null = simulación del BORRADOR (iterativa); la que habilita
 * aprobar es la de la versión, sobre la misma `content_hash`.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $client_request_id
 * @property int $strategy_id
 * @property int|null $version_id
 * @property string $content_hash
 * @property string $kind
 * @property string $input_hash
 * @property int $sample_size
 * @property array<string, mixed> $result_json
 * @property string $result_hash
 * @property int $blocking_findings
 * @property string $catalog_version
 * @property string $evaluator_version
 * @property int $created_by_admin_id
 * @property int|null $created_by_person_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 */
class StudioStrategySimulation extends Model
{
    public const UPDATED_AT = null;

    public const KIND_SYNTHETIC = 'synthetic';

    public const KIND_SAMPLE = 'sample';

    public const KINDS = [self::KIND_SYNTHETIC, self::KIND_SAMPLE];

    protected $table = 'ultron_studio_strategy_simulations';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'strategy_id' => 'integer',
            'version_id' => 'integer',
            'sample_size' => 'integer',
            'result_json' => 'array',
            'blocking_findings' => 'integer',
            'created_by_admin_id' => 'integer',
            'created_by_person_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: una simulación es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: una simulación es de solo inserción.'));
    }
}
