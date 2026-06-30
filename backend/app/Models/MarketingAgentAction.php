<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Acción CRM propuesta por el agente comercial (Fase 4C). Human-in-the-loop:
 * se sugiere, se aprueba/ejecuta manualmente y se audita. Nunca ejecuta acciones
 * críticas (pagos/membresías/WhatsApp real).
 */
class MarketingAgentAction extends Model
{
    // Tipos de acción permitidos (whitelist estricta; nada dinámico).
    public const TYPE_CREATE_NOTE = 'create_note';
    public const TYPE_ADD_TAG = 'add_tag';
    public const TYPE_SUGGEST_APPOINTMENT = 'suggest_appointment';
    public const TYPE_CREATE_APPOINTMENT = 'create_appointment';
    public const TYPE_CREATE_FOLLOW_UP = 'create_follow_up';
    public const TYPE_ASSIGN_CONVERSATION = 'assign_conversation';
    public const TYPE_REQUEST_STAFF_REVIEW = 'request_staff_review';
    public const TYPE_PAUSE_AI = 'pause_ai';
    public const TYPE_RELEASE_AI = 'release_ai';
    public const TYPE_DRAFT_REPLY = 'draft_reply';
    public const TYPE_UPDATE_LEAD_PROFILE = 'update_lead_profile';

    public const TYPES = [
        self::TYPE_CREATE_NOTE, self::TYPE_ADD_TAG, self::TYPE_SUGGEST_APPOINTMENT,
        self::TYPE_CREATE_APPOINTMENT, self::TYPE_CREATE_FOLLOW_UP, self::TYPE_ASSIGN_CONVERSATION,
        self::TYPE_REQUEST_STAFF_REVIEW, self::TYPE_PAUSE_AI, self::TYPE_RELEASE_AI,
        self::TYPE_DRAFT_REPLY, self::TYPE_UPDATE_LEAD_PROFILE,
    ];

    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_SUGGESTED, self::STATUS_APPROVED, self::STATUS_EXECUTED,
        self::STATUS_REJECTED, self::STATUS_FAILED, self::STATUS_CANCELLED,
    ];

    /** Estados "abiertos": aún accionables (para dedupe). */
    public const OPEN_STATUSES = [self::STATUS_SUGGESTED, self::STATUS_APPROVED];

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected $fillable = [
        'uuid', 'marketing_lead_id', 'marketing_conversation_id', 'marketing_message_id',
        'suggested_by', 'action_type', 'status', 'priority', 'title', 'reason',
        'payload', 'result', 'confidence', 'requires_approval',
        'approved_by_admin_id', 'approved_at', 'executed_by_admin_id', 'executed_at',
        'rejected_by_admin_id', 'rejected_at', 'rejection_reason', 'failed_reason',
    ];

    protected $casts = [
        'payload'           => 'array',
        'result'            => 'array',
        'confidence'        => 'float',
        'requires_approval' => 'boolean',
        'approved_at'       => 'datetime',
        'executed_at'       => 'datetime',
        'rejected_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (MarketingAgentAction $action): void {
            $action->uuid ??= (string) Str::uuid();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(MarketingConversation::class, 'marketing_conversation_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }
}
