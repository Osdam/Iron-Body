<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVoicePublications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Una entrada de la CADENA DE VOZ (contrato H8, D8-8, E1-4, E2-7 y E3-2):
 * publicar, promover, revertir o apagar UNA parte (`scope`: canary o rest) con
 * UN agente (o ninguno, al apagar). Lo vigente de cada parte es su entrada de
 * mayor `seq`; la cabeza, la de mayor `seq` de todas.
 *
 * SOLO INSERCIÓN, en tres capas: solo la escribe {@see StudioVoicePublications},
 * este modelo lanza al actualizar o borrar, y la base lo impide además con los
 * disparadores `usvpub_forbid_*` (también contra TRUNCATE en PostgreSQL) y el
 * REVOKE de UPDATE y DELETE del rol de la aplicación.
 *
 * `effective_json` son los BYTES EXACTOS del mensaje firmado (E1-4: el JSON
 * canónico de las 10 claves, sin prefijo, E2-7): se guarda y se lee como texto,
 * nunca como estructura (sin cast). `effective_hash` es su sha256 (lo comprueba
 * PostgreSQL) y es el `prev_hash` de la entrada siguiente.
 *
 * @property int $id
 * @property string $uuid
 * @property int $seq
 * @property string $client_request_id
 * @property string $action
 * @property string $scope
 * @property int|null $previous_publication_id
 * @property string $prev_hash
 * @property int|null $restores_publication_id
 * @property int|null $agent_id
 * @property string $effective_json
 * @property string $effective_hash
 * @property string $signature
 * @property array<string, mixed> $lab_gate
 * @property array<string, mixed>|null $evidence
 * @property string|null $reason
 * @property int $actor_admin_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property Carbon|null $created_at
 * @property-read StudioVoiceAgent|null $agent
 */
class StudioVoicePublication extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_PUBLISH = 'publish';

    public const ACTION_PROMOTE = 'promote';

    public const ACTION_ROLLBACK = 'rollback';

    public const ACTION_OFF = 'off';

    public const ACTIONS = [self::ACTION_PUBLISH, self::ACTION_PROMOTE, self::ACTION_ROLLBACK, self::ACTION_OFF];

    public const SCOPE_CANARY = 'canary';

    public const SCOPE_REST = 'rest';

    /** Las partes de la cadena, en su orden. */
    public const SCOPES = [self::SCOPE_CANARY, self::SCOPE_REST];

    protected $table = 'ultron_studio_voice_publications';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'previous_publication_id' => 'integer',
            'restores_publication_id' => 'integer',
            'agent_id' => 'integer',
            'lab_gate' => 'array',
            'evidence' => 'array',
            'actor_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: la cadena de voz es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: la cadena de voz es de solo inserción.'));
    }

    /** @return BelongsTo<StudioVoiceAgent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(StudioVoiceAgent::class, 'agent_id');
    }
}
