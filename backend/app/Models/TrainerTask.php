<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarea/alerta accionable para el entrenador humano (ver migración).
 *
 * @property int $id
 * @property int $trainer_id
 * @property int $member_id
 * @property int|null $automation_event_id
 * @property string $type
 * @property string $title
 * @property string $body
 * @property string $priority
 * @property string $status
 * @property string|null $action_route
 * @property array|null $metadata
 * @property string|null $idempotency_key
 * @property \Carbon\Carbon|null $due_at
 * @property \Carbon\Carbon|null $seen_at
 * @property \Carbon\Carbon|null $completed_at
 */
class TrainerTask extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SEEN      = 'seen';
    public const STATUS_DONE      = 'done';
    public const STATUS_DISMISSED = 'dismissed';

    public const PRIORITY_LOW    = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH   = 'high';

    protected $fillable = [
        'trainer_id', 'member_id', 'automation_event_id', 'type', 'title',
        'body', 'priority', 'status', 'action_route', 'metadata',
        'idempotency_key', 'due_at', 'seen_at', 'completed_at',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'due_at'       => 'datetime',
        'seen_at'      => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function automationEvent(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class);
    }

    /** Representación pública para CRM/API (sin datos sensibles). */
    public function toPublicArray(): array
    {
        return [
            'id'            => $this->id,
            'trainer_id'    => $this->trainer_id,
            'member_id'     => $this->member_id,
            'member_name'   => $this->relationLoaded('member') ? $this->member?->full_name : null,
            'type'          => $this->type,
            'title'         => $this->title,
            'body'          => $this->body,
            'priority'      => $this->priority,
            'status'        => $this->status,
            'action_route'  => $this->action_route,
            'metadata'      => $this->metadata ?? [],
            'due_at'        => $this->due_at?->toIso8601String(),
            'seen_at'       => $this->seen_at?->toIso8601String(),
            'completed_at'  => $this->completed_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
