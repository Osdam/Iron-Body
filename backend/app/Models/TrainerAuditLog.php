<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de auditoría del dominio profesional (append-only). Ver la migración
 * `create_trainer_audit_logs_table` para las garantías de privacidad.
 */
class TrainerAuditLog extends Model
{
    public const UPDATED_AT = null; // append-only

    public const ACTOR_ADMIN = 'admin';

    public const ACTOR_TRAINER = 'trainer';

    public const ACTOR_SYSTEM = 'system';

    // Eventos del CRM (Fase 3).
    public const EVENT_ROLES_UPDATED = 'trainer.roles_updated';

    public const EVENT_PROFILE_UPDATED = 'trainer.profile_updated';

    public const EVENT_IDENTITY_LINKED = 'trainer.identity_linked';

    public const EVENT_ACTIVATED = 'trainer.activated';

    public const EVENT_DEACTIVATED = 'trainer.deactivated';

    // Eventos de acceso profesional (Fase 4/5).
    public const EVENT_OTP_REQUESTED = 'trainer.otp_requested';

    public const EVENT_OTP_VERIFIED = 'trainer.otp_verified';

    public const EVENT_LOGIN = 'trainer.login';

    public const EVENT_SESSION_REVOKED = 'trainer.session_revoked';

    public const EVENT_WORKSPACE_SWITCH = 'trainer.workspace_switch';

    // Eventos de asistencia a clases (Fase 9).
    public const EVENT_ATTENDANCE_MARKED = 'class.attendance_marked';

    public const EVENT_ATTENDANCE_CORRECTED = 'class.attendance_corrected';

    protected $fillable = [
        'actor_type',
        'actor_id',
        'trainer_id',
        'identity_id',
        'event',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }
}
