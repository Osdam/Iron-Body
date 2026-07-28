<?php

namespace App\Models;

use App\Services\Moderation\ModerationAudit;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Bitácora APPEND-ONLY de moderación.
 *
 * La inmutabilidad se garantiza aquí: los hooks `updating` y `deleting` lanzan.
 * No es una convención documentada, es una barrera — si alguien intenta editar
 * una entrada desde cualquier punto del código, la petición falla en vez de
 * corromper la traza en silencio.
 *
 * Escribir SIEMPRE a través de {@see ModerationAudit},
 * que sanea el payload antes de llegar aquí.
 */
class ModerationAuditLog extends Model
{
    /** Solo created_at: la fila nunca se modifica. */
    public const UPDATED_AT = null;

    // Acciones registradas — espejo de los logs estructurados (Fase 20).
    public const ACTION_REPORT_SUBMITTED = 'report_submitted';

    public const ACTION_MEMBER_BLOCKED = 'member_blocked';

    public const ACTION_MEMBER_UNBLOCKED = 'member_unblocked';

    public const ACTION_REPORT_ASSIGNED = 'report_assigned';

    public const ACTION_REPORT_STATUS_CHANGED = 'report_status_changed';

    public const ACTION_CONTENT_QUARANTINED = 'content_quarantined';

    public const ACTION_CONTENT_RESTORED = 'content_restored';

    public const ACTION_ACTION_APPLIED = 'moderation_action_applied';

    public const ACTION_ACTION_REVOKED = 'moderation_action_revoked';

    public const ACTION_APPEAL_SUBMITTED = 'appeal_submitted';

    public const ACTION_APPEAL_RESOLVED = 'appeal_resolved';

    public const ACTION_EVIDENCE_VIEWED = 'evidence_viewed';

    public const ACTION_EVIDENCE_PURGED = 'evidence_purged';

    public const ACTION_GUIDELINES_ACCEPTED = 'guidelines_accepted';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'action',
        'entity_type',
        'entity_id',
        'before_data',
        'after_data',
        'ip_hash',
        'user_agent',
        'request_id',
        'created_at',
    ];

    protected $casts = [
        'before_data' => 'array',
        'after_data' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('moderation_audit_logs es append-only: no se puede modificar.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('moderation_audit_logs es append-only: no se puede eliminar.');
        });
    }
}
