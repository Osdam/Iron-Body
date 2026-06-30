<?php

namespace App\Services\Marketing;

/**
 * Determina si se puede ENTREGAR un link de pago Wompi como si fuera real.
 *
 * Regla de producto (seguridad comercial):
 *   - Solo se entrega un link en VIVO cuando Wompi está en PRODUCCIÓN y con la
 *     configuración de Web Checkout completa.
 *   - Si Wompi está configurado pero en sandbox → estado "pending": NUNCA se
 *     entrega el link de sandbox como si fuera real; un asesor comparte el medio
 *     de pago.
 *   - Si falta configuración → estado "not_configured".
 *
 * En modo dry_run (Meta deshabilitado) se permite PREPARAR el link para pruebas/
 * staging; el bloqueo real aplica cuando el mensaje se entregaría en vivo.
 */
class SalesPaymentReadinessService
{
    public const STATE_PRODUCTION_READY = 'production_ready';
    public const STATE_SANDBOX_PENDING  = 'sandbox_pending';
    public const STATE_NOT_CONFIGURED   = 'not_configured';

    /** ¿Wompi está en producción y con Web Checkout configurado? */
    public function isProductionReady(): bool
    {
        return $this->state() === self::STATE_PRODUCTION_READY;
    }

    /**
     * ¿Puede el agente generar/ofrecer un link de pago AUTOMÁTICO? Solo si Wompi
     * es productivo. En sandbox/sin configurar devuelve false: NUNCA se ofrece ni
     * se menciona un link; un asesor comparte el medio de pago.
     */
    public function canGenerateAutomaticLink(): bool
    {
        return $this->isProductionReady();
    }

    /** ¿Hay configuración de Web Checkout (independiente del ambiente)? */
    public function isConfigured(): bool
    {
        return $this->missingConfig() === [];
    }

    /**
     * Estado de preparación del pago para el agente comercial (sin secretos).
     *
     * @return self::STATE_*
     */
    public function state(): string
    {
        if (! $this->isConfigured()) {
            return self::STATE_NOT_CONFIGURED;
        }

        return $this->isProduction()
            ? self::STATE_PRODUCTION_READY
            : self::STATE_SANDBOX_PENDING;
    }

    /** Reporte saneado para el doctor (sin llaves ni secretos). */
    public function report(): array
    {
        $state = $this->state();

        return [
            'env'              => (string) config('wompi.env', 'sandbox'),
            'production'       => $this->isProduction(),
            'checkout_configured' => $this->isConfigured(),
            'missing'          => $this->missingConfig(),
            'state'            => $state,
            'can_send_live'    => $state === self::STATE_PRODUCTION_READY,
        ];
    }

    private function isProduction(): bool
    {
        // `wompi.env` es la fuente de verdad del ambiente en todo el backend.
        return (string) config('wompi.env', 'sandbox') === 'production';
    }

    /** @return string[] nombres de config faltante (sin valores). */
    private function missingConfig(): array
    {
        $missing = [];
        if (empty(config('wompi.public_key'))) {
            $missing[] = 'WOMPI_PUBLIC_KEY';
        }
        if (empty(config('wompi.integrity_secret'))) {
            $missing[] = 'WOMPI_INTEGRITY_SECRET';
        }
        if (empty(config('wompi.checkout.base_url'))) {
            $missing[] = 'WOMPI_CHECKOUT_URL';
        }
        return $missing;
    }
}
