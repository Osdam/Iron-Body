<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioVoicePublishing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Un AGENTE DE VOZ compilado y desplegado (contrato H8, D8-7): el artefacto
 * inmutable de una publicación (`purpose = publication`) o del laboratorio
 * hablado (`purpose = lab`). Guarda las HUELLAS de la compilación (`spec_hash`,
 * `payload_hash`, `projection_hash`), nunca la carga: la carga lleva en claro
 * el prompt y los destinos de transferencia (D8-14) y se reconstruye en
 * memoria al desplegar.
 *
 * Nace `pending` y solo avanza a `deployed` (la proyección observada es la
 * compilada), `rejected` (no lo es: nunca se publica) o `failed` (el proveedor
 * no lo creó o no se pudo leer). El id del proveedor y la observación se
 * escriben UNA vez (`usva_bindings_once`). «Retirado» no es un estado (D-6 de
 * DB8): es un agente desplegado que no atiende ninguna parte; revertir puede
 * volver a él.
 *
 * `serves_canary` y `serves_rest` dicen qué agente atiende cada parte de la
 * cadena de voz: a lo sumo uno por parte (`usva_one_canary`, `usva_one_rest`).
 * Los mueve {@see StudioVoicePublishing} en la misma transacción que la entrada
 * de la cadena, primero soltando y después tomando.
 *
 * LAS FILAS SOLO CAMBIAN CON UPDATE CONDICIONADOS ({@see transition()} y
 * {@see release()}/{@see take()}), nunca con `save()`.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $client_request_id
 * @property string $purpose
 * @property int $studio_version_id
 * @property string $studio_content_hash
 * @property int $voice_profile_id
 * @property string $voice_profile_hash
 * @property string $compiler_version
 * @property string $spec_hash
 * @property string $payload_hash
 * @property string $projection_hash
 * @property string $provider
 * @property string|null $provider_agent_id
 * @property string|null $observed_hash
 * @property Carbon|null $observed_at
 * @property string $status
 * @property string|null $failure_code
 * @property bool $serves_canary
 * @property bool $serves_rest
 * @property int $created_by_admin_id
 * @property string $created_by_name
 * @property string|null $created_by_role
 * @property Carbon|null $created_at
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $updated_at
 * @property-read StudioVersion|null $version
 * @property-read StudioVoiceProfile|null $profile
 */
class StudioVoiceAgent extends Model
{
    public const PURPOSE_PUBLICATION = 'publication';

    public const PURPOSE_LAB = 'lab';

    public const PROVIDER_ELEVENLABS = 'elevenlabs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DEPLOYED = 'deployed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    /** Lo que mueve el despliegue (`AGENT_MUTABLE_COLUMNS` de la migración de DB8). */
    public const MUTABLE = [
        'provider_agent_id', 'observed_hash', 'observed_at', 'status', 'failure_code',
        'serves_canary', 'serves_rest', 'status_changed_at', 'updated_at',
    ];

    /** La columna de cada parte de la cadena. */
    public const SERVES = ['canary' => 'serves_canary', 'rest' => 'serves_rest'];

    protected $table = 'ultron_studio_voice_agents';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'studio_version_id' => 'integer',
            'voice_profile_id' => 'integer',
            'serves_canary' => 'boolean',
            'serves_rest' => 'boolean',
            'created_by_admin_id' => 'integer',
            'observed_at' => 'datetime',
            'created_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: un agente de voz solo cambia con UPDATE condicionados.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: los agentes de voz no se borran.'));
    }

    /** @return BelongsTo<StudioVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(StudioVersion::class, 'studio_version_id');
    }

    /** @return BelongsTo<StudioVoiceProfile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(StudioVoiceProfile::class, 'voice_profile_id');
    }

    /**
     * UPDATE condicionado del despliegue: solo si sigue en uno de `$from` y
     * solo columnas de {@see MUTABLE}.
     *
     * @param  list<string>  $from
     * @param  array<string, mixed>  $values
     */
    public static function transition(int $id, array $from, array $values): bool
    {
        $ajenas = array_values(array_diff(array_keys($values), self::MUTABLE));
        if ($ajenas !== []) {
            throw new LogicException('ultron_studio: un agente de voz solo cambia '.implode(', ', self::MUTABLE).'; no '.implode(', ', $ajenas).'.');
        }

        return static::query()->whereKey($id)->whereIn('status', $from)->toBase()->update($values + ['updated_at' => now()]) === 1;
    }

    /** Suelta la parte (`canary` o `rest`) del agente que la atienda, si lo hay. Primero se suelta, después se toma. */
    public static function release(string $part): void
    {
        $columna = self::SERVES[$part] ?? throw new LogicException('ultron_studio: parte de la cadena de voz desconocida.');
        static::query()->where($columna, true)->toBase()->update([$columna => false, 'updated_at' => now()]);
    }

    /** El agente (de publicación y desplegado) pasa a atender la parte. false si no cumple. */
    public static function take(int $id, string $part): bool
    {
        $columna = self::SERVES[$part] ?? throw new LogicException('ultron_studio: parte de la cadena de voz desconocida.');

        return static::query()->whereKey($id)
            ->where('purpose', self::PURPOSE_PUBLICATION)
            ->where('status', self::STATUS_DEPLOYED)
            ->toBase()
            ->update([$columna => true, 'updated_at' => now()]) === 1;
    }
}
