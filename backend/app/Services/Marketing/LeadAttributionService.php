<?php

namespace App\Services\Marketing;

use App\Models\MarketingLeadAttribution;
use App\Services\Observability\ChannelLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;

/**
 * Traduce el `referral` de Meta a una atribución consultable.
 *
 * Lo que hace y, sobre todo, lo que se niega a hacer:
 *
 *  · **No infiere campañas del texto del cliente.** Que alguien escriba «vi su
 *    anuncio de Instagram» no crea una atribución de Instagram. Eso es una
 *    afirmación de un desconocido, no un dato; si el canal no trae `referral`,
 *    la fuente es `unknown` y así se queda.
 *
 *  · **No inventa identificadores.** El `referral` de WhatsApp Cloud API trae
 *    `source_type`, `source_id`, `source_url`, `headline`, `body`,
 *    `media_type` y `ctwa_clid`. No trae campaña, conjunto ni creatividad por
 *    separado. Esas columnas quedan nulas mientras nadie las aporte.
 *
 *  · **No sobrescribe el primer contacto.** Es la única respuesta a «¿qué nos
 *    trajo a esta persona?», y se pierde para siempre si la segunda visita
 *    pisa a la primera.
 *
 * Nunca lanza hacia quien la llama: se invoca desde el procesado de un webhook
 * y perder una atribución es infinitamente preferible a perder el mensaje de un
 * prospecto.
 */
class LeadAttributionService
{
    /** Lo que Meta puede poner en `referral.source_type`. */
    private const META_SOURCE_TYPES = ['ad', 'post', 'page'];

    /**
     * Registra o actualiza la atribución de un lead a partir de un mensaje.
     *
     * @param  array<string,mixed>|null  $referral  bloque `referral` tal cual llegó
     */
    public function record(
        int $leadId,
        ?array $referral,
        ?int $conversationId = null,
        ?string $contactId = null,
        ?\DateTimeInterface $receivedAt = null,
    ): ?MarketingLeadAttribution {
        try {
            return $this->doRecord($leadId, $referral, $conversationId, $contactId, $receivedAt);
        } catch (\Throwable $e) {
            ChannelLog::error('attribution.record_failed', [
                'lead_id' => $leadId,
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function doRecord(
        int $leadId,
        ?array $referral,
        ?int $conversationId,
        ?string $contactId,
        ?\DateTimeInterface $receivedAt,
    ): ?MarketingLeadAttribution {
        $normalized = $this->normalize($referral);
        $now = $receivedAt ?? now();

        $existing = MarketingLeadAttribution::query()
            ->where('marketing_lead_id', $leadId)
            ->first();

        // Sin referral y con atribución ya registrada, no hay nada que hacer:
        // un mensaje corriente no cambia de dónde vino nadie.
        if ($normalized === null && $existing !== null) {
            return $existing;
        }

        // Sin referral y sin nada previo: se deja constancia de que se
        // desconoce. Un hueco y un «no se sabe» no son lo mismo cuando después
        // se mide qué porcentaje de ingresos viene sin atribuir.
        if ($normalized === null) {
            return $this->createUnknown($leadId, $conversationId, $contactId, $now);
        }

        $dedupeKey = $this->dedupeKeyFor($leadId, $normalized, $now);

        if ($existing === null) {
            return $this->createFirst($leadId, $conversationId, $contactId, $normalized, $now, $dedupeKey, $referral);
        }

        return $this->applyNewTouch($existing, $normalized, $now, $dedupeKey, $referral, $conversationId);
    }

    /**
     * Normaliza SOLO lo que de verdad llegó.
     *
     * @return array<string,mixed>|null null si el bloque no aporta nada
     */
    private function normalize(?array $referral): ?array
    {
        if (empty($referral)) {
            return null;
        }

        $sourceType = strtolower(trim((string) ($referral['source_type'] ?? '')));
        $sourceId = $referral['source_id'] ?? null;
        $clickId = $referral['ctwa_clid'] ?? null;

        // Un bloque sin ninguna señal identificable no es una atribución.
        if ($sourceType === '' && blank($sourceId) && blank($clickId)) {
            return null;
        }

        return [
            // 'ad' de Meta es un anuncio; 'post'/'page' son alcance orgánico.
            'source_type' => match ($sourceType) {
                'ad' => 'ad',
                'post', 'page' => 'organic',
                default => in_array($sourceType, self::META_SOURCE_TYPES, true) ? $sourceType : 'unknown',
            },
            'source_platform' => $this->platformFrom($referral),
            // En el referral de WhatsApp, `source_id` ES el identificador del
            // anuncio. No hay campaña ni conjunto: quedan nulos a propósito.
            'ad_id' => filled($sourceId) ? (string) $sourceId : null,
            'click_id' => filled($clickId) ? (string) $clickId : null,
            'source_url' => $referral['source_url'] ?? null,
            'headline' => $this->trimText($referral['headline'] ?? null, 500),
            'body' => $this->trimText($referral['body'] ?? null, 1000),
            'media_type' => $referral['media_type'] ?? null,
        ];
    }

    private function platformFrom(array $referral): ?string
    {
        $url = strtolower((string) ($referral['source_url'] ?? ''));

        if (str_contains($url, 'instagram.')) {
            return 'instagram';
        }
        if (str_contains($url, 'facebook.') || str_contains($url, 'fb.')) {
            return 'facebook';
        }

        // Sin URL no se adivina: quedarse en null es más útil que acertar a
        // medias, porque después se mide cuánto viene sin identificar.
        return null;
    }

    private function createFirst(
        int $leadId,
        ?int $conversationId,
        ?string $contactId,
        array $n,
        \DateTimeInterface $now,
        string $dedupeKey,
        ?array $raw,
    ): ?MarketingLeadAttribution {
        try {
            $attribution = MarketingLeadAttribution::create([
                'marketing_lead_id' => $leadId,
                'marketing_conversation_id' => $conversationId,
                'contact_id' => $contactId,
                ...$n,
                'first_touch_at' => $now,
                'first_touch_source_type' => $n['source_type'],
                'first_touch_ad_id' => $n['ad_id'],
                'last_touch_at' => $now,
                'last_touch_source_type' => $n['source_type'],
                'last_touch_ad_id' => $n['ad_id'],
                'received_at' => $now,
                'attribution_confidence' => $this->confidenceFor($n),
                'evidence' => $this->evidenceFor($n, 'first_touch'),
                'raw_referral_payload' => $raw,
                'dedupe_key' => $dedupeKey,
                'correlation_id' => $this->correlationId(),
            ]);

            ChannelLog::info('attribution.recorded', [
                'lead_id' => $leadId,
                'source_type' => $n['source_type'],
                'ad_id' => $n['ad_id'],
                'confidence' => $attribution->attribution_confidence,
            ]);

            return $attribution;
        } catch (QueryException $e) {
            // Carrera entre dos mensajes del mismo lead: gana el primero.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return MarketingLeadAttribution::query()->where('marketing_lead_id', $leadId)->first();
        }
    }

    /**
     * Actualiza el ÚLTIMO contacto, dejando intacto el primero.
     */
    private function applyNewTouch(
        MarketingLeadAttribution $attribution,
        array $n,
        \DateTimeInterface $now,
        string $dedupeKey,
        ?array $raw,
        ?int $conversationId,
    ): MarketingLeadAttribution {
        // El mismo referral otra vez (webhook reintentado): no es un contacto
        // nuevo, es el mismo visto dos veces.
        if ($attribution->dedupe_key === $dedupeKey) {
            return $attribution;
        }

        $attribution->forceFill([
            'last_touch_at' => $now,
            'last_touch_source_type' => $n['source_type'],
            'last_touch_ad_id' => $n['ad_id'],
            'received_at' => $now,
            'dedupe_key' => $dedupeKey,
            'marketing_conversation_id' => $conversationId ?? $attribution->marketing_conversation_id,
            // El contenido del anuncio se refresca al del contacto más
            // reciente: es lo que la persona acaba de ver, y es lo que el
            // agente necesita para no hablar de una oferta antigua.
            'headline' => $n['headline'] ?? $attribution->headline,
            'body' => $n['body'] ?? $attribution->body,
            'source_url' => $n['source_url'] ?? $attribution->source_url,
            'raw_referral_payload' => $raw ?? $attribution->raw_referral_payload,
            'evidence' => $this->evidenceFor($n, 'last_touch', $attribution->evidence),
        ])->save();

        ChannelLog::info('attribution.new_touch', [
            'lead_id' => $attribution->marketing_lead_id,
            'first_touch' => $attribution->first_touch_source_type,
            'last_touch' => $n['source_type'],
        ]);

        return $attribution;
    }

    private function createUnknown(
        int $leadId,
        ?int $conversationId,
        ?string $contactId,
        \DateTimeInterface $now,
    ): ?MarketingLeadAttribution {
        try {
            return MarketingLeadAttribution::create([
                'marketing_lead_id' => $leadId,
                'marketing_conversation_id' => $conversationId,
                'contact_id' => $contactId,
                'source_type' => 'unknown',
                'first_touch_at' => $now,
                'first_touch_source_type' => 'unknown',
                'last_touch_at' => $now,
                'last_touch_source_type' => 'unknown',
                'received_at' => $now,
                'attribution_confidence' => 'unknown',
                'evidence' => [['fact' => 'no_referral_in_payload', 'at' => $now->format('c')]],
                'correlation_id' => $this->correlationId(),
            ]);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            return MarketingLeadAttribution::query()->where('marketing_lead_id', $leadId)->first();
        }
    }

    /**
     * Confianza según lo que se pudo verificar, nunca por optimismo.
     */
    private function confidenceFor(array $n): string
    {
        // Identificador de clic + anuncio: se puede cruzar con Meta más tarde.
        if (filled($n['click_id']) && filled($n['ad_id'])) {
            return 'high';
        }

        if (filled($n['ad_id']) || filled($n['click_id'])) {
            return 'medium';
        }

        if ($n['source_type'] !== 'unknown') {
            return 'low';
        }

        return 'unknown';
    }

    /** @return array<int,array<string,mixed>> */
    private function evidenceFor(array $n, string $kind, ?array $previous = null): array
    {
        $entry = array_filter([
            'kind' => $kind,
            'fact' => 'meta_referral_received',
            'source_type' => $n['source_type'],
            'ad_id' => $n['ad_id'],
            'click_id' => $n['click_id'] !== null ? 'present' : null,
            'at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null);

        return array_slice([...($previous ?? []), $entry], -10);
    }

    /**
     * Identidad del CONTACTO publicitario, no de la llamada.
     *
     * Se construye con el anuncio y el clic. Dos mensajes seguidos desde el
     * mismo clic son el mismo contacto; un clic nuevo en el mismo anuncio, una
     * semana después, sí es un contacto nuevo, y por eso entra la fecha.
     */
    private function dedupeKeyFor(int $leadId, array $n, \DateTimeInterface $at): string
    {
        $parts = [
            $leadId,
            $n['ad_id'] ?? 'no-ad',
            $n['click_id'] ?? $at->format('Y-m-d'),
        ];

        return substr(implode(':', $parts), 0, 250);
    }

    private function correlationId(): ?string
    {
        $id = Context::get('correlation_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function trimText(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim($text);

        return $clean === '' ? null : mb_substr($clean, 0, $max);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) ($e->errorInfo[0] ?? ''), ['23505', '23000'], true);
    }
}
