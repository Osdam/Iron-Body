<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Observability\ChannelLog;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Genera un LINK DE PAGO Wompi (Web Checkout hosteado) reutilizable para enviar
 * por WhatsApp/Meta cuando un lead no quiere pagar desde la app.
 *
 * ESTE ES EL EMBUDO. Todos los caminos que pueden acuñar un cobro —los dos
 * endpoints internos firmados con el secreto de automatización, el panel del
 * CRM, la herramienta de ULTRON, el orquestador legado y la herramienta del
 * subsistema Commercial— terminan en {@see self::generateForLead()}, y ninguno
 * en ningún otro sitio. Por eso el permiso se exige AQUÍ y no en cada llamador:
 * el agujero que esto cierra existía precisamente porque la comprobación estaba
 * repetida en unos caminos y ausente en otros. La regla vive en
 * {@see SalesPaymentGuardrailService}; este método la invoca por todos.
 *
 * Diseño SEGURO y ADITIVO:
 *   - Reutiliza WompiTransactionService::createOrReuse para CREAR (monto
 *     autoritativo, reference única, anti doble pago ya probado). La
 *     idempotencia por (lead, plan) se resuelve aquí con un prefijo en la
 *     columna STRING `idempotency_key` — NUNCA con `order_id` (que es bigint y
 *     pertenece al flujo in-app de órdenes/compras).
 *   - El monto es AUTORITATIVO del backend (Plan::price). El cliente/n8n nunca
 *     lo define (lo refuerza SalesPaymentGuardrailService).
 *   - El link es una URL FIRMADA con la integridad del comercio. NO se llama a
 *     la API de Wompi para generarlo (no hay riesgo de cobro al crearlo).
 *   - Wompi confirma el pago por el MISMO webhook S2S existente → la membresía
 *     se activa SOLO ahí (este servicio jamás activa nada).
 *   - Si falta configuración (public_key / integrity_secret), devuelve
 *     `configured=false` con un error controlado y NO crea datos corruptos.
 *
 * Nunca loguea ni expone secretos (private_key / integrity_secret).
 */
class WompiPaymentLinkService
{
    public function __construct(
        private readonly WompiTransactionService $tx,
        private readonly WompiSignatureService $signature,
        private readonly array $cfg,
        private readonly ?SalesPaymentGuardrailService $guardrail = null,
    ) {}

    public static function make(): self
    {
        return new self(
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            (array) config('wompi'),
            app(SalesPaymentGuardrailService::class),
        );
    }

    /** ¿Hay configuración suficiente para firmar un Web Checkout? */
    public function isConfigured(): bool
    {
        return $this->missingConfig() === [];
    }

    /** @return string[] nombres de config faltante (sin valores). */
    public function missingConfig(): array
    {
        $missing = [];
        if (empty($this->cfg['public_key'])) {
            $missing[] = 'WOMPI_PUBLIC_KEY';
        }
        if (empty($this->cfg['integrity_secret'])) {
            $missing[] = 'WOMPI_INTEGRITY_SECRET';
        }
        if (empty(data_get($this->cfg, 'checkout.base_url'))) {
            $missing[] = 'WOMPI_CHECKOUT_URL';
        }

        return $missing;
    }

    /**
     * Genera (o reutiliza) el link de pago para un lead + plan.
     *
     * El permiso se comprueba ANTES de tocar la base de datos: un rechazo no deja
     * fila de cobro, ni reutiliza una en vuelo, ni refresca una URL existente.
     * Nunca lanza —un fallo aquí no puede romper un turno de conversación que ya
     * salió—: devuelve `authorized=false` con el código y el estado HTTP que el
     * llamador debe responder.
     *
     * @param  array  $options  {
     *                          conversation_id?: int|null, message_id?: int|null, channel?: string|null,
     *                          wants_invoice?: bool, invoice_email?: string|null,
     *                          origin?: string, authorized_by?: int|null
     *                          }
     * @return array resultado seguro (sin secretos) para el caller / n8n.
     */
    public function generateForLead(MarketingLead $lead, Plan $plan, array $options = []): array
    {
        // Sin configuración → error controlado, sin crear transacción.
        if (! $this->isConfigured()) {
            return [
                'configured' => false,
                'error' => 'wompi_checkout_not_configured',
                'missing' => $this->missingConfig(),
                'message' => 'El link de pago Wompi no está configurado todavía.',
                'payment_url' => null,
            ];
        }

        // EL CERROJO. Antes de crear, reutilizar o devolver nada.
        if ($denial = $this->denyIfNotAuthorized($lead, $plan, $options)) {
            return $denial;
        }

        $currency = strtoupper((string) ($this->cfg['currency'] ?? 'COP'));
        $prefix = $this->dedupKeyPrefix($lead, $plan);

        // Si ya hay un pago APROBADO para (lead, plan), NO se genera link nuevo.
        $approved = $this->latestForPrefix($prefix, [PaymentStateMachine::APPROVED]);
        if ($approved !== null) {
            return [
                'configured' => true,
                'authorized' => true,
                'already_paid' => true,
                'payment_url' => null,
                'reference' => $approved->reference,
                'amount' => (float) $approved->amount,
                'currency' => $approved->currency,
                'transaction_id' => $approved->provider_ref ?: (string) $approved->id,
                'status' => $approved->status,
                'message' => 'Este lead ya tiene un pago aprobado para este plan.',
            ];
        }

        // Idempotencia: reutiliza una transacción de marketing EN VUELO (no final)
        // para el mismo (lead, plan); si no hay, crea una nueva. NUNCA reutiliza
        // approved/declined/voided/error/expired. order_id queda SIEMPRE en null.
        $transaction = $this->latestForPrefix($prefix, PaymentStateMachine::IN_FLIGHT)
            ?? $this->tx->createOrReuse([
                // order_id se OMITE a propósito (es bigint del flujo de órdenes).
                'idempotency_key' => $prefix.Str::uuid(),  // string único → nunca colisiona
                'plan_id' => $plan->id,
                'member_id' => $lead->member_id,
                'method' => 'web_checkout',
                'currency' => $currency,
                'description' => 'Membresía Iron Body — '.$plan->name,
                'customer' => array_filter([
                    'name' => $lead->name,
                    'phone' => $lead->phone,
                ]),
                // Factura electrónica: SOLO se guarda metadata; el flujo existente
                // (PaymentMembershipActivator) decide tras el pago aprobado.
                'request_invoice' => (bool) ($options['wants_invoice'] ?? false),
                'invoice_email' => $options['invoice_email'] ?? null,
            ]);

        // Enriquecer metadata con la trazabilidad comercial (sin pisar invoice).
        $this->stampMarketingMetadata($transaction, $lead, $options);

        $expiresAt = $this->resolveExpiresAt($transaction);
        $url = $this->buildCheckoutUrl($transaction, $currency);

        // Persistir el link y la vigencia en la transacción (sin secretos).
        $transaction->forceFill([
            'checkout_url' => $url,
            'expires_at' => $transaction->expires_at ?: $expiresAt,
        ])->save();

        return [
            'configured' => true,
            'authorized' => true,
            'already_paid' => false,
            'payment_url' => $url,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'currency' => $transaction->currency,
            'expires_at' => optional($transaction->expires_at)->toIso8601String(),
            'transaction_id' => $transaction->provider_ref ?: (string) $transaction->id,
            'status' => $transaction->status,
        ];
    }

    /**
     * Aplica la regla central de autoridad y, si deniega, devuelve el resultado
     * controlado que verá el llamador. `null` significa «adelante».
     *
     * Quién lo pide sale de `$options`, que construye el llamador en código:
     * ningún controlador vuelca aquí el payload de la petición, así que
     * `origin` no es un campo que se pueda mandar desde fuera. El origen humano
     * exige además el id del administrador que la sesión real ya resolvió.
     *
     * La denegación se registra una sola vez, aquí, con lead y plan por id y
     * sin un dato personal: el nombre y el teléfono no hacen falta para saber
     * que alguien intentó acuñar un cobro sin permiso.
     *
     * @return array|null resultado de rechazo, o null si puede continuar.
     */
    private function denyIfNotAuthorized(MarketingLead $lead, Plan $plan, array $options): ?array
    {
        $origin = (string) ($options['origin'] ?? SalesPaymentGuardrailService::ORIGIN_AUTOMATIC);
        $adminId = $options['authorized_by'] ?? null;
        $guardrail = $this->guardrail ?? app(SalesPaymentGuardrailService::class);

        try {
            $guardrail->assertCanGeneratePaymentLink($lead, $plan, [], [
                'origin' => $origin,
                'admin_id' => $adminId,
                // Para QUIÉN es este cobro. Lo mira el cerrojo del canario, y
                // si no viene, no se acuña nada: falla cerrado.
                'conversation_id' => $options['conversation_id'] ?? null,
            ]);
        } catch (SalesGuardrailException $e) {
            ChannelLog::warning('marketing.payment_link.denied', [
                'lead_id' => $lead->id,
                'plan_id' => $plan->id,
                'origin' => $origin,
                'admin_id' => $adminId,
                'reason' => $e->errorCode,
            ]);

            return [
                'configured' => true,
                'authorized' => false,
                'already_paid' => false,
                'error' => $e->errorCode,
                'message' => $e->getMessage(),
                'escalate' => $e->escalate,
                'http_status' => $e->httpStatus,
                'payment_url' => null,
            ];
        }

        return null;
    }

    /**
     * Prefijo determinístico por (lead, plan) para la columna STRING
     * `idempotency_key`. Cada transacción concreta añade un sufijo único (uuid),
     * así varias transacciones del mismo lead/plan conviven (la unique de
     * idempotency_key nunca choca) y la idempotencia se resuelve por LIKE.
     */
    private function dedupKeyPrefix(MarketingLead $lead, Plan $plan): string
    {
        return 'mkt-lead-'.$lead->id.'-plan-'.$plan->id.'-';
    }

    /**
     * Última transacción Wompi de marketing (por prefijo de idempotency_key) en
     * alguno de los estados dados. LIKE sobre columna string → cross-DB seguro
     * (no usa JSON path ni casts). Nunca toca order_id.
     *
     * @param  string[]  $statuses
     */
    private function latestForPrefix(string $prefix, array $statuses): ?PaymentTransaction
    {
        return PaymentTransaction::query()
            ->where('provider', 'wompi')
            ->where('method', 'web_checkout')
            ->where('idempotency_key', 'like', $prefix.'%')
            ->whereIn('status', $statuses)
            ->latest('id')
            ->first();
    }

    /**
     * Mezcla la trazabilidad comercial en metadata sin borrar lo de facturación.
     * source=marketing_agent permite distinguir estos pagos en el CRM.
     */
    private function stampMarketingMetadata(PaymentTransaction $transaction, MarketingLead $lead, array $options): void
    {
        $existing = is_array($transaction->metadata) ? $transaction->metadata : [];

        $marketing = array_filter([
            'source' => (string) (config('marketing.payment_links.source', 'marketing_agent')),
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $options['conversation_id'] ?? null,
            'marketing_message_id' => $options['message_id'] ?? null,
            'channel' => $options['channel'] ?? $lead->channel,
            'plan_id' => $transaction->plan_id,
        ], fn ($v) => $v !== null);

        $transaction->forceFill(['metadata' => array_merge($existing, $marketing)])->save();
    }

    /** Vigencia local del link (no caduca en Wompi por sí solo). */
    private function resolveExpiresAt(PaymentTransaction $transaction): Carbon
    {
        if ($transaction->expires_at) {
            return $transaction->expires_at;
        }
        $minutes = (int) data_get($this->cfg, 'checkout.expiration_minutes', 1440);

        return now()->addMinutes(max(5, $minutes));
    }

    /**
     * URL del Web Checkout hosteado por Wompi, firmada con la integridad del
     * comercio. Las CLAVES se dejan literales (Wompi espera `signature:integrity`);
     * los VALORES van url-encoded.
     */
    private function buildCheckoutUrl(PaymentTransaction $transaction, string $currency): string
    {
        $amountInCents = $this->tx->amountInCents($transaction);
        $signature = $this->signature->integritySignature(
            $transaction->reference,
            $amountInCents,
            $currency,
        );

        $base = (string) data_get($this->cfg, 'checkout.base_url');
        $redirect = (string) (data_get($this->cfg, 'checkout.redirect_url') ?: ($this->cfg['redirect_url'] ?? ''));

        $params = array_filter([
            'public-key' => (string) $this->cfg['public_key'],
            'currency' => $currency,
            'amount-in-cents' => (string) $amountInCents,
            'reference' => $transaction->reference,
            'signature:integrity' => $signature,
            'redirect-url' => $redirect !== '' ? $redirect : null,
        ], fn ($v) => $v !== null && $v !== '');

        $query = collect($params)
            ->map(fn ($value, $key) => $key.'='.rawurlencode((string) $value))
            ->implode('&');

        return rtrim($base, '?').'?'.$query;
    }
}
