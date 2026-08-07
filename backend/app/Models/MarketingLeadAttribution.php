<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * De dónde vino un prospecto.
 *
 * Una fila por lead: dentro conviven el primer contacto —que no se sobrescribe
 * nunca— y el último. Separarlos en filas obligaría a decidir cuál vale, y las
 * dos valen para preguntas distintas: el primero dice qué nos lo trajo, el
 * último dice qué lo hizo volver.
 */
class MarketingLeadAttribution extends Model
{
    /** Anuncio pagado. */
    public const SOURCE_AD = 'ad';

    /** Publicación o página sin pauta. */
    public const SOURCE_ORGANIC = 'organic';

    public const SOURCE_REFERRAL = 'referral';

    public const SOURCE_SEARCH = 'search';

    /** Escribió al número sin pasar por nada nuestro. */
    public const SOURCE_DIRECT = 'direct';

    public const SOURCE_UNKNOWN = 'unknown';

    protected $fillable = [
        'marketing_lead_id', 'marketing_conversation_id', 'contact_id',
        'source_type', 'source_platform',
        'campaign_id', 'campaign_name', 'adset_id', 'adset_name',
        'ad_id', 'ad_name', 'creative_id', 'click_id', 'source_url',
        'advertised_product', 'advertised_plan_id', 'headline', 'body', 'media_type',
        'first_touch_at', 'first_touch_source_type', 'first_touch_ad_id',
        'last_touch_at', 'last_touch_source_type', 'last_touch_ad_id',
        'received_at', 'attribution_confidence', 'evidence',
        'raw_referral_payload', 'dedupe_key', 'correlation_id',
    ];

    protected $casts = [
        'evidence' => 'array',
        'raw_referral_payload' => 'array',
        'first_touch_at' => 'datetime',
        'last_touch_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }

    /** ¿Vino de una pauta pagada? */
    public function isPaidAd(): bool
    {
        return $this->source_type === self::SOURCE_AD;
    }

    /** ¿Se sabe realmente de dónde vino? */
    public function isKnown(): bool
    {
        return $this->source_type !== self::SOURCE_UNKNOWN;
    }

    /**
     * Lo que se le entrega al agente comercial.
     *
     * Deliberadamente NO incluye el payload crudo ni identificadores internos.
     * Y el texto publicitario va marcado como no confiable: es contenido que
     * alguien redactó fuera del sistema, y tratarlo como instrucción es
     * exactamente por donde entra una inyección de prompt.
     *
     * @return array<string,mixed>
     */
    public function toAgentContext(): array
    {
        return array_filter([
            'source' => $this->source_type,
            'platform' => $this->source_platform,
            'campaign' => $this->campaign_name,
            'ad' => $this->ad_name ?? $this->ad_id,
            'advertised_product' => $this->advertised_product,
            // Se entrega para que el agente sepa QUÉ vio la persona, nunca
            // como una promesa que deba cumplir: el precio se consulta al
            // catálogo, siempre.
            'headline' => $this->headline,
            'confidence' => $this->attribution_confidence,
            'untrusted_text' => $this->headline !== null || $this->body !== null,
            'evidence' => $this->evidence,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
