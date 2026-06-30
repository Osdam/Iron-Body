<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingConversation extends Model
{
    protected $fillable = [
        'lead_id', 'channel', 'status', 'last_message_at', 'human_takeover',
        'human_takeover_source', 'ai_enabled',
        // Memoria comercial (aditivo).
        'summary', 'detected_objective', 'lead_score', 'lead_stage',
        'primary_intent', 'last_intent',
        // Operación del Inbox CRM (Fase 2A, aditivo).
        'assigned_to_admin_id', 'assigned_at', 'assigned_by',
        'unread_count', 'last_read_at',
        'last_inbound_at', 'last_outbound_at', 'first_response_at',
        'staff_review_pending', 'staff_review_reason',
        'staff_review_resolved_at', 'staff_review_resolved_by',
        'manual_takeover_at', 'manual_takeover_by',
        'closed_at', 'snooze_until',
    ];

    protected $casts = [
        'last_message_at'          => 'datetime',
        'human_takeover'           => 'boolean',
        'ai_enabled'               => 'boolean',
        'lead_score'               => 'integer',
        // Inbox CRM.
        'assigned_at'              => 'datetime',
        'unread_count'             => 'integer',
        'last_read_at'             => 'datetime',
        'last_inbound_at'          => 'datetime',
        'last_outbound_at'         => 'datetime',
        'first_response_at'        => 'datetime',
        'staff_review_pending'     => 'boolean',
        'staff_review_resolved_at' => 'datetime',
        'manual_takeover_at'       => 'datetime',
        'closed_at'                => 'datetime',
        'snooze_until'             => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'lead_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MarketingMessage::class, 'conversation_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MarketingConversationNote::class, 'conversation_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(MarketingConversationTag::class, 'conversation_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'assigned_to_admin_id');
    }
}
