<?php

namespace App\Models;

use App\Services\Marketing\Attribution\LeadIdentityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La persona del CRM que hay detrás de un lead, según la regla D7.
 *
 * Tabla derivada: la escribe solo {@see LeadIdentityResolver} y se puede
 * recalcular entera. NO sustituye a `marketing_leads.member_id`, que es un
 * enlace confirmado por una persona y sigue siendo la fuente de las demás
 * pantallas; este vínculo solo lo usa la analítica de mercadeo.
 */
class MarketingLeadIdentity extends Model
{
    /** El lead ya traía su socio (`marketing_leads.member_id`). */
    public const METHOD_EXPLICIT = 'explicit';

    /** Los últimos 10 dígitos del teléfono casan con UNA ficha de socio. */
    public const METHOD_PHONE_MEMBER = 'phone_member';

    /** Ninguna ficha casa, pero sí UN usuario del CRM por su teléfono. */
    public const METHOD_PHONE_USER = 'phone_user';

    /** Sin teléfono utilizable o sin coincidencias. */
    public const METHOD_NONE = 'none';

    /** El teléfono casa con varias personas: no se enlaza a ninguna. */
    public const METHOD_AMBIGUOUS = 'ambiguous';

    public const METHODS = [
        self::METHOD_EXPLICIT, self::METHOD_PHONE_MEMBER, self::METHOD_PHONE_USER,
        self::METHOD_NONE, self::METHOD_AMBIGUOUS,
    ];

    /** Los métodos que sí dejan a la persona enlazada. */
    public const LINKED = [self::METHOD_EXPLICIT, self::METHOD_PHONE_MEMBER, self::METHOD_PHONE_USER];

    protected $fillable = [
        'marketing_lead_id', 'user_id', 'member_id', 'method', 'candidates', 'resolved_at',
    ];

    protected $casts = [
        'marketing_lead_id' => 'integer',
        'user_id' => 'integer',
        'member_id' => 'integer',
        'candidates' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    public function isLinked(): bool
    {
        return in_array($this->method, self::LINKED, true);
    }
}
