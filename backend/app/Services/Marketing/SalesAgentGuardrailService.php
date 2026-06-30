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

        // 3) Caso sensible (needs_staff_review): NUNCA se cierra ni se genera link,
        // pero la IA SIGUE RESPONDIENDO. Se deja una marca interna (staff_review)
        // sin apagar el bot: jamás human_takeover ni ai_enabled=false automáticos.
        if (! empty($decision['needs_staff_review'])) {
            $decision['should_generate_payment_link'] = false;
            $tools = (array) ($decision['tools_requested'] ?? []);
            // Quita cualquier intento de apagar la IA; deja solo staff_review.
            $tools = array_values(array_filter($tools, fn ($t) => $t !== SalesIntents::TOOL_HUMAN_TAKEOVER));
            if (! in_array(SalesIntents::TOOL_STAFF_REVIEW, $tools, true)) {
                $tools[] = SalesIntents::TOOL_STAFF_REVIEW;
            }
            $decision['tools_requested'] = $tools;
            // recommended_action queda en reply (la IA responde); NO escalate_human.
            if (($decision['recommended_action'] ?? null) === SalesIntents::ACTION_ESCALATE_HUMAN) {
                $decision['recommended_action'] = SalesIntents::ACTION_REPLY;
            }
        }

        // 4) Defensa dura: el flujo automático NUNCA solicita human_takeover.
        $decision['tools_requested'] = array_values(array_filter(
            (array) ($decision['tools_requested'] ?? []),
            fn ($t) => $t !== SalesIntents::TOOL_HUMAN_TAKEOVER,
        ));
        if (($decision['recommended_action'] ?? null) === SalesIntents::ACTION_ESCALATE_HUMAN) {
            $decision['recommended_action'] = SalesIntents::ACTION_REPLY;
        }
        // should_escalate ya NO apaga la IA; queda informativo y en false.
        $decision['should_escalate'] = false;

        // 5) safe_to_send: hay una respuesta segura que se puede enviar. El envío
        // REAL sigue gated por META_ENABLED + flags + auto_execute.
        $decision['safe_to_send'] = (bool) ($decision['should_reply'] ?? false) && ! empty($decision['reply']);

        return $decision;
    }

    private function withFlag(array $decision, string $flag): array
    {
        return array_values(array_unique(array_merge((array) ($decision['risk_flags'] ?? []), [$flag])));
    }
}
