<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;
use App\Models\Plan;

/**
 * Guardrails de PAGO del agente comercial. Defensa en profundidad: aunque el
 * cerebro IA (n8n / futuro motor) proponga algo inseguro, AQUÍ se valida antes
 * de generar cualquier link. Reglas NO negociables:
 *
 *   - Nunca generar link si el lead tiene do_not_contact=true.
 *   - Nunca aceptar `amount`/`amount_in_cents` desde el request: el monto es
 *     SIEMPRE autoritativo del backend (Plan::price).
 *   - Nunca generar link para un plan inactivo o sin precio válido.
 *   - Generar un link NUNCA activa membresía (eso es exclusivo del webhook
 *     Wompi aprobado / reconciliación / PaymentMembershipActivator).
 *   - Una venta NO se marca ganada hasta que el pago esté aprobado (la
 *     atribución la hace MarketingAttributionService desde un pago real).
 *   - Capturas/“comprobantes” enviados por el lead JAMÁS confirman un pago.
 *   - Descuentos/precios especiales no autorizados → escalar a humano.
 *
 * Las violaciones se lanzan como {@see SalesGuardrailException} (code + mensaje
 * saneado). No se loguean secretos ni montos libres del cliente.
 */
class SalesPaymentGuardrailService
{
    /**
     * Valida que se PUEDA generar un link de pago para (lead, plan).
     *
     * @param  array  $input  payload tal cual llegó (para detectar montos prohibidos).
     *
     * @throws SalesGuardrailException
     */
    public function assertCanGeneratePaymentLink(MarketingLead $lead, Plan $plan, array $input = []): void
    {
        // 1) do_not_contact o consentimiento denegado: bloqueo duro.
        if (! $lead->canReplyReactively()) {
            throw SalesGuardrailException::make(
                'lead_do_not_contact',
                'Este lead está marcado como no contactar (do_not_contact).',
            );
        }

        // 2) Monto autoritativo: el cliente/n8n NUNCA define el precio.
        foreach (['amount', 'amount_in_cents', 'price', 'total'] as $forbidden) {
            if (array_key_exists($forbidden, $input)) {
                throw SalesGuardrailException::make(
                    'amount_not_allowed',
                    'El monto no se acepta desde el cliente: es autoritativo del backend.',
                );
            }
        }

        /*
         * 3) ¿Se le puede vender esto a alguien?
         *
         * Una sola pregunta, contestada por la regla de dominio (Plan::isSellable),
         * no por condiciones repetidas aquí. Antes se comprobaban `active` y
         * precio > 0 por separado, y entre las dos se colaba lo que de verdad
         * importaba: un plan interno con precio simbólico —uno de 1 peso, dos
         * días— pasaba ambas y habría generado un cobro real.
         *
         * El motivo se desglosa sólo para el diagnóstico; quien decide es
         * isSellable().
         */
        if (! $plan->isSellable()) {
            [$code, $message] = match (true) {
                ! (bool) $plan->active => ['plan_inactive', 'El plan seleccionado no está activo.'],
                (float) $plan->price <= 0 => ['plan_price_invalid', 'El plan no tiene un precio válido para cobrar.'],
                (int) $plan->duration_days <= 0 => ['plan_duration_invalid', 'El plan no tiene una duración válida.'],
                default => ['plan_not_sellable', 'Ese plan no está a la venta.'],
            };

            throw SalesGuardrailException::make($code, $message);
        }
    }

    /**
     * Heurística mínima: ¿el lead pide un descuento/precio especial no
     * autorizado? El agente NO debe inventar promociones; debe escalar.
     * (Detección textual liviana; el cerebro IA puede afinarla luego.)
     */
    public function requestsUnauthorizedDiscount(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = mb_strtolower($text);
        foreach (['descuento', 'rebaja', 'promo', 'oferta', 'me dejas', 'precio especial', 'mas barato', 'más barato', 'cupon', 'cupón'] as $kw) {
            if (str_contains($needle, $kw)) {
                return true;
            }
        }

        return false;
    }
}
