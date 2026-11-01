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
 * instantánea sin FK. `ip_address` y `user_agent` existen por si una fase
 * posterior fija una retención: hoy quedan siempre NULL y la API no los devuelve.
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

    /** Hito 6 (contrato H6 §I8): una asociación de campaña, un número canario y una instantánea de integración. */
    public const SUBJECT_CAMPAIGN_LINK = 'campaign_link';

    public const SUBJECT_CANARY_NUMBER = 'canary_number';

    public const SUBJECT_INTEGRATION = 'integration';

    /** Los 7 sujetos del CHECK `usae_subject_type_chk` desde la migración 2026_11_01_100002. */
    public const SUBJECT_TYPES = [
        self::SUBJECT_DRAFT, self::SUBJECT_VERSION, self::SUBJECT_RULE, self::SUBJECT_SYSTEM,
        self::SUBJECT_CAMPAIGN_LINK, self::SUBJECT_CANARY_NUMBER, self::SUBJECT_INTEGRATION,
    ];

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
