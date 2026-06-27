<?php

namespace App\Services\Marketing;

use App\Models\MarketingLead;

/**
 * Guardrails del CEREBRO comercial (defensa en profundidad sobre la decisión IA,
 * complementa a {@see SalesPaymentGuardrailService} que cubre el link de pago).
 * Reglas NO negociables aplicadas SIEMPRE antes de ejecutar nada:
 *
 *   - do_not_contact=true → no responder, no link, no seguimiento (bloqueo total).
 *   - Casos sensibles (médico, fraude/pago, facturación, devolución, reclamo) →
 *     escalar a humano; NUNCA generar link ni intentar cerrar.
 *   - No inventar precios (las respuestas curadas no incluyen precios; el precio
 *     real solo viaja en el mensaje del link, tomado del plan activo).
 *   - No prometer resultados físicos garantizados ni dar diagnósticos médicos
 *     (los textos curados ya lo respetan; aquí se fuerza el escalado).
 *   - No activar membresías ni marcar pagos: la decisión jamás expone esas
 *     acciones; la activación es exclusiva del webhook Wompi aprobado.
 */
class SalesAgentGuardrailService
{
    /** Aplica los guardrails a la decisión ya ensamblada y la devuelve saneada. */
    public function apply(array $decision, MarketingLead $lead): array
    {
        // 1) do_not_contact: bloqueo total (gana sobre todo).
        if (! $lead->isContactable()) {
            return array_merge($decision, [
                'should_reply'                  => false,
                'should_generate_payment_link'  => false,
                'should_send_message'           => false,
                'should_schedule_followup'      => false,
                'followup_delay_minutes'        => null,
                'should_escalate'               => false,
                'escalation_reason'             => null,
                'recommended_action'            => SalesIntents::ACTION_BLOCKED_DNC,
                'reply'                         => null,
                'tools_requested'               => [],
                'safe_to_send'                  => false,
                'risk_flags'                    => $this->withFlag($decision, 'do_not_contact'),
            ]);
        }

        // 2) El lead pide no ser contactado → marcar, no insistir.
        if (($decision['intent'] ?? null) === SalesIntents::DO_NOT_CONTACT_REQUEST) {
            $decision = array_merge($decision, [
                'should_reply'                 => false,
                'should_generate_payment_link' => false,
                'should_send_message'          => false,
                'should_schedule_followup'     => false,
                'followup_delay_minutes'       => null,
                'recommended_action'           => SalesIntents::ACTION_MARK_DNC,
                'reply'                        => null,
                'tools_requested'              => [SalesIntents::TOOL_MARK_DNC],
            ]);
        }

        // 3) Escalado: casos sensibles los atiende un humano. Nunca link/cierre.
        if (! empty($decision['should_escalate'])) {
            $decision['should_generate_payment_link'] = false;
            $decision['should_schedule_followup']     = false;
            $decision['followup_delay_minutes']       = null;
            $decision['recommended_action']           = SalesIntents::ACTION_ESCALATE_HUMAN;
            $decision['tools_requested']              = [SalesIntents::TOOL_HUMAN_TAKEOVER];
            // Se conserva un `reply` de espera (no comercial) si lo hay.
        }

        // 4) safe_to_send: hay una respuesta segura que un humano/n8n podría
        // enviar. El envío REAL sigue gated por META_ENABLED + flags + auto_execute.
        $decision['safe_to_send'] = (bool) ($decision['should_reply'] ?? false) && ! empty($decision['reply']);

        return $decision;
    }

    private function withFlag(array $decision, string $flag): array
    {
        return array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), [$flag])));
    }
}
