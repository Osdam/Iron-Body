<?php

namespace App\Services\Marketing\Attribution;

use App\Models\MarketingLeadAttribution;

/**
 * De dónde llegó esta persona, en una estructura explícita y versionada.
 *
 * Existe para que «el contexto de adquisición» sea UNA cosa con una forma
 * conocida, en vez de un puñado de columnas que cada consumidor interpreta a su
 * manera. Lo leen el prompt del agente, el motor de siguiente mejor acción y la
 * analítica; si cada uno construyera lo suyo, acabarían discrepando y nadie
 * sabría cuál tiene razón.
 *
 * Tres reglas que lo definen:
 *
 *  · **No inventa.** El `referral` de WhatsApp Cloud API trae anuncio, URL,
 *    titular y cuerpo. NO trae campaña, conjunto ni creatividad. Esos campos
 *    salen nulos y `known` lo dice: es más útil un «no se sabe» honesto que un
 *    dato inventado que después alguien usa para decidir un presupuesto.
 *
 *  · **Nunca es fuente de verdad comercial.** Lo que decía el anuncio sobre
 *    precios, promociones o disponibilidad es HISTORIA, no catálogo. El precio
 *    sale de los planes activos, siempre, y por eso este objeto lleva encima el
 *    resultado de contrastar lo anunciado con lo vigente.
 *
 *  · **Es contexto de ADQUISICIÓN, no de cliente.** Lo que trajo a alguien hace
 *    ocho meses no describe a quien hoy entrena cuatro veces por semana. Quien
 *    lo consuma tiene que poder distinguirlo, y por eso está separado.
 *
 * La versión del esquema viaja dentro: el día que cambie la forma, quien lo
 * consuma podrá notarlo en vez de leer mal en silencio.
 */
final class AttributionContext
{
    /** Sube al cambiar la FORMA de lo que se entrega, no su contenido. */
    public const SCHEMA_VERSION = '1.0';

    /** Anuncio pagado. Se nombra así hacia fuera aunque dentro sea 'ad'. */
    public const TYPE_PAID_AD = 'paid_ad';

    private function __construct(
        public readonly bool $known,
        public readonly string $sourceType,
        public readonly ?string $platform,
        /** @var array{id:?string,name:?string} */
        public readonly array $campaign,
        /** @var array{id:?string,name:?string} */
        public readonly array $adset,
        /** @var array{id:?string,name:?string} */
        public readonly array $ad,
        /** @var array{id:?string,headline:?string,body:?string,advertised_product:?string} */
        public readonly array $creative,
        public readonly ?string $firstTouchAt,
        public readonly ?string $lastTouchAt,
        public readonly ?string $firstTouchSourceType,
        public readonly ?string $lastTouchSourceType,
        public readonly string $confidence,
        /** @var array<int,array<string,mixed>> */
        public readonly array $evidence,
        public readonly ?int $advertisedPlanId,
        public readonly OfferConsistency $consistency,
    ) {}

    /** Cuando no hay ninguna atribución registrada todavía. */
    public static function absent(): self
    {
        return new self(
            known: false,
            sourceType: MarketingLeadAttribution::SOURCE_UNKNOWN,
            platform: null,
            campaign: ['id' => null, 'name' => null],
            adset: ['id' => null, 'name' => null],
            ad: ['id' => null, 'name' => null],
            creative: ['id' => null, 'headline' => null, 'body' => null, 'advertised_product' => null],
            firstTouchAt: null,
            lastTouchAt: null,
            firstTouchSourceType: null,
            lastTouchSourceType: null,
            confidence: 'unknown',
            evidence: [],
            advertisedPlanId: null,
            consistency: OfferConsistency::notAdvertised(),
        );
    }

    public static function fromModel(MarketingLeadAttribution $a, OfferConsistency $consistency): self
    {
        return new self(
            known: $a->isKnown(),
            sourceType: self::publicSourceType($a->source_type),
            platform: $a->source_platform,
            campaign: ['id' => $a->campaign_id, 'name' => $a->campaign_name],
            adset: ['id' => $a->adset_id, 'name' => $a->adset_name],
            ad: ['id' => $a->ad_id, 'name' => $a->ad_name],
            creative: [
                'id' => $a->creative_id,
                'headline' => $a->headline,
                'body' => $a->body,
                'advertised_product' => $a->advertised_product,
            ],
            firstTouchAt: $a->first_touch_at?->toIso8601String(),
            lastTouchAt: $a->last_touch_at?->toIso8601String(),
            firstTouchSourceType: self::publicSourceType($a->first_touch_source_type),
            lastTouchSourceType: self::publicSourceType($a->last_touch_source_type),
            confidence: (string) ($a->attribution_confidence ?: 'unknown'),
            evidence: (array) ($a->evidence ?? []),
            advertisedPlanId: $a->advertised_plan_id,
            consistency: $consistency,
        );
    }

    /**
     * `ad` dentro, `paid_ad` fuera.
     *
     * El nombre interno viene del vocabulario de Meta; el de fuera dice lo que
     * significa. Quien lea el contexto no tiene por qué saber que «ad» en el
     * referral quiere decir «anuncio pagado».
     */
    private static function publicSourceType(?string $internal): string
    {
        return match ($internal) {
            MarketingLeadAttribution::SOURCE_AD => self::TYPE_PAID_AD,
            null => MarketingLeadAttribution::SOURCE_UNKNOWN,
            default => $internal,
        };
    }

    public function isPaidAd(): bool
    {
        return $this->sourceType === self::TYPE_PAID_AD;
    }

    /** ¿Hay texto publicitario, es decir, texto que NO escribimos nosotros? */
    public function hasUntrustedText(): bool
    {
        return $this->creative['headline'] !== null
            || $this->creative['body'] !== null
            || $this->campaign['name'] !== null
            || $this->ad['name'] !== null;
    }

    /**
     * Lo MÍNIMO que necesita el agente, y ni un campo más.
     *
     * Fuera quedan el payload crudo, el identificador de clic, la URL de origen
     * y los identificadores internos. No porque sean secretos, sino porque no
     * ayudan a atender mejor a nadie y cada campo que viaja es superficie: más
     * tokens, más texto ajeno dentro del prompt y más cosas que el modelo puede
     * repetirle al cliente sin venir a cuento.
     *
     * @return array<string,mixed>
     */
    public function toAgentPayload(): array
    {
        if (! $this->known) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'known' => false,
                'confidence' => $this->confidence,
            ];
        }

        return array_filter([
            'schema_version' => self::SCHEMA_VERSION,
            'known' => true,
            'source_type' => $this->sourceType,
            'platform' => $this->platform,
            'campaign_name' => $this->campaign['name'],
            'ad_name' => $this->ad['name'],
            'advertised_product' => $this->creative['advertised_product'],
            // El titular y el cuerpo viajan para que el agente sepa QUÉ vio la
            // persona, jamás como una promesa que deba cumplir.
            'ad_headline' => $this->creative['headline'],
            'ad_body' => $this->creative['body'],
            'first_touch_at' => $this->firstTouchAt,
            'last_touch_at' => $this->lastTouchAt,
            'confidence' => $this->confidence,
            // Lo más importante del bloque: si lo anunciado sigue existiendo.
            'offer_status' => $this->consistency->status,
            'offer_note' => $this->consistency->agentNote(),
        ], fn ($v) => $v !== null);
    }

    /**
     * Señales para el motor comercial. Números y banderas, sin texto libre.
     *
     * El motor decide con reglas auditables; darle titulares publicitarios solo
     * abriría la puerta a que una decisión de negocio dependa de cómo redactó
     * alguien un anuncio.
     *
     * @return array<string,mixed>
     */
    public function toSignals(): array
    {
        return [
            'known' => $this->known,
            'is_paid_ad' => $this->isPaidAd(),
            'source_type' => $this->sourceType,
            'platform' => $this->platform,
            'advertised_plan_id' => $this->advertisedPlanId,
            'advertised_offer_usable' => $this->consistency->isUsable(),
            'confidence' => $this->confidence,
            'first_touch_at' => $this->firstTouchAt,
            'last_touch_at' => $this->lastTouchAt,
        ];
    }

    /**
     * Hechos verificables para la memoria comercial.
     *
     * Solo lo que consta en una fila y se puede volver a comprobar. Nada de
     * interpretaciones del modelo: «parecía interesado en adelgazar» no es un
     * hecho y no entra aquí ni aunque el modelo lo afirme con seguridad.
     *
     * @return array<string,mixed>
     */
    public function toMemoryFacts(): array
    {
        return array_filter([
            'initial_source' => $this->firstTouchSourceType,
            'initial_campaign' => $this->campaign['name'],
            'initial_ad' => $this->ad['name'] ?? $this->ad['id'],
            'advertised_product' => $this->creative['advertised_product'],
            'last_source' => $this->lastTouchSourceType,
            'first_touch_at' => $this->firstTouchAt,
            'last_touch_at' => $this->lastTouchAt,
            'confidence' => $this->confidence,
        ], fn ($v) => $v !== null);
    }
}
