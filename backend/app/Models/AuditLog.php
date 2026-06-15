<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento de auditoría general del CRM (append-only). Ver la migración
 * `create_audit_logs_table`. El actor es auto-reportado por el CRM y NUNCA debe
 * usarse como mecanismo de autorización: es únicamente traza.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null; // append-only

    /** Acciones canónicas (espejo del front: AuditAction). */
    public const ACTIONS = ['create', 'update', 'delete', 'status', 'assign', 'settings'];

    protected $fillable = [
        'action',
        'module',
        'entity',
        'entity_id',
        'target_name',
        'actor_id',
        'actor_name',
        'actor_role',
        'summary',
        'changes',
        'metadata',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Forma pública que consume el CRM (espejo de AuditLogEntry del front). */
    public function toPublicArray(): array
    {
        return [
            'id' => (string) $this->id,
            'action' => $this->action,
            'module' => $this->module,
            'entity' => $this->entity,
            'entityId' => $this->entity_id,
            'targetName' => $this->target_name,
            'actorId' => $this->actor_id,
            'actorName' => $this->actor_name,
            'actorRole' => $this->actor_role ?? '',
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'summary' => $this->summary ?? '',
            'changes' => is_array($this->changes) ? array_values($this->changes) : [],
            'metadata' => $this->metadata,
        ];
    }
}
