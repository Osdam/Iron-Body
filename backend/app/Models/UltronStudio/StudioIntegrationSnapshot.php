<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioIntegrationConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Una INSTANTÁNEA de integración (contrato H6 §I5.3): la configuración de un
 * proveedor (`kind=config`), reconocida por una persona o anotada por el
 * comando de cada despliegue, o el resultado de una comprobación de solo
 * lectura (`kind=probe`: la sonda de Twilio o la verificación de voz).
 *
 * `facts` NUNCA lleva un secreto ni el teléfono de una persona: de los secretos,
 * solo si están y una huella corta; de los números del gimnasio, sus cuatro
 * últimos dígitos ({@see StudioIntegrationConfig}). `fingerprint` es el sha256
 * del JSON canónico de `facts`.
 *
 * SOLO INSERCIÓN, en tres capas: solo la escriben los servicios del Studio,
 * este modelo lanza al actualizar o borrar, y la base lo impide además con los
 * disparadores `usis_forbid_*` (también contra TRUNCATE en PostgreSQL).
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $client_request_id
 * @property string $provider
 * @property string $kind
 * @property string $fingerprint
 * @property array<string, mixed> $facts
 * @property string|null $outcome
 * @property string $actor_type
 * @property int|null $actor_admin_id
 * @property string $actor_name
 * @property string|null $actor_role
 * @property string|null $note
 * @property Carbon|null $created_at
 */
class StudioIntegrationSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const PROVIDER_META_ADS = 'meta_ads';

    public const PROVIDER_TWILIO = 'twilio';

    public const PROVIDER_VOICE = 'voice';

    /** En el orden de `GET integrations/config`. */
    public const PROVIDERS = [self::PROVIDER_META_ADS, self::PROVIDER_TWILIO, self::PROVIDER_VOICE];

    public const KIND_CONFIG = 'config';

    public const KIND_PROBE = 'probe';

    public const KINDS = [self::KIND_CONFIG, self::KIND_PROBE];

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_TYPES = [self::ACTOR_ADMIN, self::ACTOR_SYSTEM];

    /** `outcome` de una sonda (§I2). */
    public const OUTCOMES = ['ok', 'degraded', 'auth_failed', 'unreachable', 'error'];

    protected $table = 'ultron_studio_integration_snapshots';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'actor_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $instantanea): void {
            $instantanea->uuid ??= (string) Str::uuid();
        });

        static::updating(fn () => throw new LogicException('ultron_studio: las instantáneas de integración son de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: las instantáneas de integración son de solo inserción.'));
    }
}
