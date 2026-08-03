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

    public const CONSENT_DENIED = 'denied';

    public const CONSENT_PENDING = 'pending';

    public const CONSENT_UNKNOWN = 'unknown';

    /**
     * Dominio reconocido de consent_status. La columna es un string sin CHECK,
     * así que cualquier valor fuera de esta lista se trata como NO reconocido y
     * se bloquea (fail-closed): ante un estado que no sabemos leer, no se contacta.
     */
    public const CONSENT_STATUSES = [
        self::CONSENT_GRANTED, self::CONSENT_DENIED,
        self::CONSENT_PENDING, self::CONSENT_UNKNOWN,
    ];

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
        'last_message_at' => 'datetime',
        'converted_at' => 'datetime',
        'do_not_contact' => 'boolean',
        'consent_at' => 'datetime',
        'last_human_takeover_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Freno duro: el lead pidió expresamente no ser contactado. Gana sobre
     * cualquier consent_status y sobre cualquier canal.
     *
     * Se mantiene con la semántica original (solo do_not_contact) porque hay
     * siete llamadores que dependen de ella. Para decidir si se puede hablar con
     * el lead, usa canReplyReactively() o canContactProactively(), que además
     * miran el consentimiento.
     */
    public function isContactable(): bool
    {
        return ! (bool) $this->do_not_contact;
    }

    /**
     * ¿Podemos RESPONDER a un lead que nos escribió a nosotros?
     *
     * Un consentimiento ausente (null) no bloquea: el titular inició el contacto
     * por un canal comercial publicado, y no responderle sería peor servicio y
     * peor experiencia que responderle. Lo que sí bloquea es una negativa expresa
     * y cualquier valor que no sepamos interpretar.
     */
    public function canReplyReactively(): bool
    {
        if (! $this->isContactable()) {
            return false;
        }

        $consent = $this->consent_status;
        if ($consent === null || $consent === '') {
            return true;
        }
        if (! in_array($consent, self::CONSENT_STATUSES, true)) {
            return false;
        }

        return $consent !== self::CONSENT_DENIED;
    }

    /**
     * ¿Podemos ESCRIBIR NOSOTROS PRIMERO (seguimiento, reactivación, campaña)?
     *
     * Exige consentimiento expreso: iniciar el contacto es tratamiento de datos
     * con finalidad comercial (Ley 1581 de 2012). Ni null ni pending ni unknown
     * bastan — solo granted.
     */
    public function canContactProactively(): bool
    {
        return $this->canReplyReactively()
            && $this->consent_status === self::CONSENT_GRANTED;
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
