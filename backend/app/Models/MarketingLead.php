<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingLead extends Model
{
    // Estados del lead.
    public const STATUS_NEW = 'new';
    public const STATUS_INTERESTED = 'interested';
    public const STATUS_HOT = 'hot';
    public const STATUS_WARM = 'warm';
    public const STATUS_COLD = 'cold';
    public const STATUS_UNQUALIFIED = 'unqualified';
    public const STATUS_DISCARDED = 'discarded';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_NEEDS_HUMAN = 'needs_human';

    // Estado del consentimiento de contacto comercial.
    public const CONSENT_GRANTED = 'granted';
    public const CONSENT_DENIED  = 'denied';
    public const CONSENT_PENDING = 'pending';
    public const CONSENT_UNKNOWN = 'unknown';

    protected $fillable = [
        'channel', 'source', 'meta_user_id', 'phone', 'instagram_username',
        'name', 'status', 'temperature', 'objective', 'assigned_to',
        'campaign_id', 'member_id', 'first_message_at', 'last_message_at',
        'converted_at',
        // Consentimiento / do-not-contact / escalado (aditivo).
        'do_not_contact', 'consent_status', 'consent_source', 'consent_at',
        'last_human_takeover_at', 'human_takeover_reason', 'metadata',
    ];

    protected $casts = [
        'first_message_at' => 'datetime',
        'last_message_at'  => 'datetime',
        'converted_at'     => 'datetime',
        'do_not_contact'   => 'boolean',
        'consent_at'       => 'datetime',
        'last_human_takeover_at' => 'datetime',
        'metadata'         => 'array',
    ];

    /** ¿Es seguro para el agente contactar a este lead? */
    public function isContactable(): bool
    {
        return ! (bool) $this->do_not_contact;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'campaign_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(MarketingConversation::class, 'lead_id');
    }

    public function aiActions(): HasMany
    {
        return $this->hasMany(MarketingAiAction::class, 'lead_id');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(MarketingFollowup::class, 'lead_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(MarketingCall::class, 'marketing_lead_id');
    }
}
