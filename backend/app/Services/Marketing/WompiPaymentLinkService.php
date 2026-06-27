<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Services\Wompi\PaymentStateMachine;
use App\Services\Wompi\WompiSignatureService;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Support\Carbon;

/**
 * Genera un LINK DE PAGO Wompi (Web Checkout hosteado) reutilizable para enviar
 * por WhatsApp/Meta cuando un lead no quiere pagar desde la app.
 *
 * Diseño SEGURO y ADITIVO:
 *   - Reutiliza WompiTransactionService::createOrReuse (idempotencia por
 *     `order_id` = lead+plan; anti doble pago ya probado).
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
    ) {
    }

    public static function make(): self
    {
        return new self(
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            (array) config('wompi'),
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
     * @param  array  $options  {
     *   conversation_id?: int|null, message_id?: int|null, channel?: string|null,
     *   wants_invoice?: bool, invoice_email?: string|null
     * }
     * @return array resultado seguro (sin secretos) para el caller / n8n.
     */
    public function generateForLead(MarketingLead $lead, Plan $plan, array $options = []): array
    {
        // Sin configuración → error controlado, sin crear transacción.
        if (! $this->isConfigured()) {
            return [
                'configured'  => false,
                'error'       => 'wompi_checkout_not_configured',
                'missing'     => $this->missingConfig(),
                'message'     => 'El link de pago Wompi no está configurado todavía.',
                'payment_url' => null,
            ];
        }

        $currency = strtoupper((string) ($this->cfg['currency'] ?? 'COP'));

        // Transacción autoritativa (monto desde el plan). order_id estable por
        // (lead, plan) → idempotencia/anti doble pago reutilizando el núcleo Wompi.
        $transaction = $this->tx->createOrReuse([
            'order_id'        => $this->orderIdFor($lead, $plan),
            'plan_id'         => $plan->id,
            'member_id'       => $lead->member_id,
            'method'          => 'web_checkout',
            'currency'        => $currency,
            'description'     => 'Membresía Iron Body — '.$plan->name,
            'customer'        => array_filter([
                'name'  => $lead->name,
                'phone' => $lead->phone,
            ]),
            // Factura electrónica: SOLO se guarda metadata; el flujo existente
            // (PaymentMembershipActivator) decide tras el pago aprobado.
            'request_invoice' => (bool) ($options['wants_invoice'] ?? false),
            'invoice_email'   => $options['invoice_email'] ?? null,
        ]);

        // Enriquecer metadata con la trazabilidad comercial (sin pisar invoice).
        $this->stampMarketingMetadata($transaction, $lead, $options);

        // Si ya está aprobada, NO se genera link nuevo: el pago ya ocurrió.
        if ($transaction->status === PaymentStateMachine::APPROVED) {
            return [
                'configured'     => true,
                'already_paid'   => true,
                'payment_url'    => null,
                'reference'      => $transaction->reference,
                'amount'         => (float) $transaction->amount,
                'currency'       => $transaction->currency,
                'transaction_id' => $transaction->provider_ref ?: (string) $transaction->id,
                'status'         => $transaction->status,
                'message'        => 'Este lead ya tiene un pago aprobado para este plan.',
            ];
        }

        $expiresAt = $this->resolveExpiresAt($transaction);
        $url = $this->buildCheckoutUrl($transaction, $currency);

        // Persistir el link y la vigencia en la transacción (sin secretos).
        $transaction->forceFill([
            'checkout_url' => $url,
            'expires_at'   => $transaction->expires_at ?: $expiresAt,
        ])->save();

        return [
            'configured'     => true,
            'already_paid'   => false,
            'payment_url'    => $url,
            'reference'      => $transaction->reference,
            'amount'         => (float) $transaction->amount,
            'currency'       => $transaction->currency,
            'expires_at'     => optional($transaction->expires_at)->toIso8601String(),
            'transaction_id' => $transaction->provider_ref ?: (string) $transaction->id,
            'status'         => $transaction->status,
        ];
    }

    /** order_id determinístico por (lead, plan): habilita la idempotencia. */
    private function orderIdFor(MarketingLead $lead, Plan $plan): string
    {
        return 'mkt-lead-'.$lead->id.'-plan-'.$plan->id;
    }

    /**
     * Mezcla la trazabilidad comercial en metadata sin borrar lo de facturación.
     * source=marketing_agent permite distinguir estos pagos en el CRM.
     */
    private function stampMarketingMetadata(PaymentTransaction $transaction, MarketingLead $lead, array $options): void
    {
        $existing = is_array($transaction->metadata) ? $transaction->metadata : [];

        $marketing = array_filter([
            'source'                  => (string) (config('marketing.payment_links.source', 'marketing_agent')),
            'marketing_lead_id'       => $lead->id,
            'marketing_conversation_id' => $options['conversation_id'] ?? null,
            'marketing_message_id'    => $options['message_id'] ?? null,
            'channel'                 => $options['channel'] ?? $lead->channel,
            'plan_id'                 => $transaction->plan_id,
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
            'public-key'          => (string) $this->cfg['public_key'],
            'currency'            => $currency,
            'amount-in-cents'     => (string) $amountInCents,
            'reference'           => $transaction->reference,
            'signature:integrity' => $signature,
            'redirect-url'        => $redirect !== '' ? $redirect : null,
        ], fn ($v) => $v !== null && $v !== '');

        $query = collect($params)
            ->map(fn ($value, $key) => $key.'='.rawurlencode((string) $value))
            ->implode('&');

        return rtrim($base, '?').'?'.$query;
    }
}
