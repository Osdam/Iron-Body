<?php

namespace App\Models\UltronStudio;

use App\Services\UltronStudio\StudioAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Libro de SOLO INSERCIÓN del Studio: quién guardó, creó, envió, aprobó,
 * rechazó o intentó publicar qué, y cuándo. Ver {@see StudioAudit}.
 *
 * Actualizar o borrar lanza aquí; la base lo impide además con disparadores
 * (`usae_forbid_*`), también contra TRUNCATE en PostgreSQL. El actor es una
 * instantánea sin FK. `ip_address` y `user_agent` son de la persona del CRM,
 * nunca de prospectos, y la API no los devuelve.
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
 * @property string|null $content_hash
 * @property string $summary
 * @property array<string, mixed>|null $diff
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class StudioAuditEvent extends Model
{
    public const UPDATED_AT = null;

    public const SUBJECT_DRAFT = 'draft';

    public const SUBJECT_VERSION = 'version';

    public const SUBJECT_RULE = 'rule';

    public const SUBJECT_SYSTEM = 'system';

    public const SUBJECT_TYPES = [self::SUBJECT_DRAFT, self::SUBJECT_VERSION, self::SUBJECT_RULE, self::SUBJECT_SYSTEM];

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_SYSTEM = 'system';

    protected $table = 'ultron_studio_audit_events';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'actor_admin_id' => 'integer',
            'diff' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ultron_studio: la auditoría es de solo inserción.'));
        static::deleting(fn () => throw new LogicException('ultron_studio: la auditoría es de solo inserción.'));
    }
}
