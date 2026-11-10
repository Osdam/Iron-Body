<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioStrategyPublications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una entrada de la CADENA DE ESTRATEGIAS (contrato H9, D9-11.3, §6.1): una
 * acción sobre UNA estrategia (`publish`, `promote`, `pause`, `resume`,
 * `rollback`, `retire`). Lo vigente de una estrategia es su entrada de mayor
 * `seq`; la cabeza, la de mayor `seq` de todas (global).
 *
 * SOLO INSERCIÓN, en tres capas: solo la escribe
 * {@see StudioStrategyPublications::insertEntry()}, este modelo lanza al
 * actualizar o borrar, y la base lo impide con sus disparadores y el REVOKE
 * (DB9). `effective_json` son los BYTES EXACTOS del mensaje firmado (JSON
 * canónico, `Outreach\Core\StrategyChainSignature`): texto, sin cast.
 *
 * @property int $id
 * @property string $uuid
 * @property int $seq
 * @property int|null $previous_publication_id
 * @property string $prev_hash
 * @property string $client_request_id
 * @property string $action
 * @property int $strategy_id
 * @property int|null $version_id
 * @property string $effective_json
 * @property string $effective_hash
 * @property string $signature
 * @property string|null $simulation_gate
 * @property array<string, mixed>|null $evidence
 * @property string|null $reason
 * @property int $actor_admin_id
 * @property int|null $acted_by_person_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property Carbon|null $created_at
 * @property-read StudioStrategy|null $strategy
 * @property-read StudioStrategyVersion|null $version
 */
class StudioStrategyPublication extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_PROMOTE = 'promote';

    public const ACTION_PAUSE = 'pause';

    public const ACTION_RESUME = 'resume';

    public const ACTION_ROLLBACK = 'rollback';

    public const ACTION_RETIRE = 'retire';

    public const ACTIONS = [self::ACTION_PUBLISH, self::ACTION_PROMOTE, self::ACTION_PAUSE, self::ACTION_RESUME, self::ACTION_ROLLBACK, self::ACTION_RETIRE];

    /** Las que exigen motivo (CHECK de §6.1). */
    public const ACTIONS_WITH_REASON = [self::ACTION_PAUSE, self::ACTION_ROLLBACK];

    public const PART_CANARY = 'canary';

    public const PART_REST = 'rest';

    public const PARTS = [self::PART_CANARY, self::PART_REST];

    protected $table = 'ultron_studio_strategy_publications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'strategy_id' => 'integer',
            'version_id' => 'integer',
            'evidence' => 'array',
            'actor_admin_id' => 'integer',
            'acted_by_person_id' => 'integer',
            'previous_publication_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: la cadena de estrategias es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: la cadena de estrategias es de solo inserción.'));
    }

    /** @return BelongsTo<StudioStrategy, $this> */
    public function strategy(): BelongsTo
    {
        return $this->belongsTo(StudioStrategy::class, 'strategy_id');
    }

    /** @return BelongsTo<StudioStrategyVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StudioStrategyVersion::class, 'version_id');
    }
}
