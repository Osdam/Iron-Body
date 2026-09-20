<?php

namespace App\Services\Marketing;

/**
 * Valida y SANEA la salida cruda del modelo. Laravel tiene la última palabra:
 * lo que el modelo proponga se acota al catálogo y se bloquea cualquier intento
 * peligroso. Devuelve un resultado MÍNIMO y seguro que consume el orquestador
 * (intención + reply saneado + señales de escalado). El orquestador deriva de
 * forma DETERMINISTA temperatura, herramientas y guardrails (el modelo nunca
 * decide acciones por sí mismo).
 */
class SalesAgentDecisionValidator
{
    public function __construct(
        private readonly HumanHandoffAuthority $handoff = new HumanHandoffAuthority,
    ) {}

    /**
     * @param  array  $raw  decisión cruda devuelta por el modelo (json).
     * @param  ?string  $inboundBody  lo que la persona escribió DE VERDAD en este
     *                                turno. Sin esto, «quiere hablar con alguien»
     *                                es una afirmación del modelo que nadie puede
     *                                contrastar.
     * @return array{intent:string, confidence:float, extracted_fields:array, missing_fields:array, reply:?string, force_escalate:bool, escalation_reason:?string, risk_flags:array}
     */
    public function sanitize(array $raw, ?string $inboundBody = null): array
    {
        $flags = [];

        // 1) Intención dentro del catálogo (si no, unknown).
        $intent = (string) ($raw['intent'] ?? SalesIntents::UNKNOWN);
        if (! in_array($intent, SalesAgentDecisionSchema::INTENTS, true)) {
            $intent = SalesIntents::UNKNOWN;
            $flags[] = 'invalid_intent';
        }

        // 2) Confianza acotada [0,1].
        $confidence = (float) ($raw['confidence'] ?? 0.5);
        $confidence = max(0.0, min(1.0, $confidence));

        $extracted = is_array($raw['extracted_fields'] ?? null) ? $raw['extracted_fields'] : [];
        $missing = is_array($raw['missing_fields'] ?? null) ? $raw['missing_fields'] : [];

        // 3) Herramientas: solo las permitidas; lo demás se bloquea (no se honra).
        $tools = is_array($raw['tools_requested'] ?? null) ? $raw['tools_requested'] : [];
        $forbiddenTools = array_diff($tools, SalesAgentDecisionSchema::ALLOWED_TOOLS);
        if ($forbiddenTools !== []) {
            $flags[] = 'forbidden_tool';
        }

        // 4) Intentos PROHIBIDOS (activar membresía / aprobar pago / facturación)
        //    en cualquier parte de la salida → bloquear + escalar.
        $blob = json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '';
        $forbiddenAction = SalesAgentDecisionSchema::forbiddenSignalIn($blob) !== null;
        if ($forbiddenAction) {
            $flags[] = 'forbidden_action';
        }

        // 5) Reply saneado: nunca precios (no inventar) ni promesas/diagnóstico.
        $reply = is_string($raw['reply'] ?? null) ? trim($raw['reply']) : null;
        $unsafeClaim = false;
        if ($reply !== null && $reply !== '') {
            // Precio en el texto → se CORRIGE (se elimina; el precio real solo va
            // en el mensaje del link, tomado de Plan::price).
            if (SalesAgentDecisionSchema::containsPrice($reply)) {
                $reply = null;
                $flags[] = 'price_in_reply';
            }
        }
        if ($reply !== null && $reply !== '') {
            if (SalesAgentDecisionSchema::unsafeSignalIn($reply) !== null) {
                $unsafeClaim = true;
                $reply = null;
                $flags[] = 'unsafe_claim';
            }
        }

        /*
         * 6) Escalado forzado: intención sensible, intento prohibido o claim inseguro.
         *
         * Con una excepción que costó una conversación entera: pedir hablar con
         * una persona es la ÚNICA de estas intenciones que afirma algo sobre lo
         * que el cliente dijo, y por tanto la única que se puede desmentir
         * leyéndolo. Antes bastaba con que el modelo escribiera la etiqueta; así
         * se escaló un «si por favor» y, peor, un «no quiero alguien del equipo».
         *
         * Las demás (riesgo médico, fraude, queja, factura) describen la
         * naturaleza del asunto, no una petición, y siguen escalando solas.
         */
        $escalationIntents = SalesIntents::ESCALATION_INTENTS;

        if ($intent === SalesIntents::HUMAN_REQUEST && $inboundBody !== null) {
            if ($this->handoff->peticionDeHumanoEn($inboundBody) === null) {
                $escalationIntents = array_values(array_diff($escalationIntents, [SalesIntents::HUMAN_REQUEST]));
                $flags[] = 'human_request_not_corroborated';
            }
        }

        $forceEscalate = in_array($intent, $escalationIntents, true)
            || $forbiddenAction || $unsafeClaim;

        $reason = null;
        if ($forbiddenAction) {
            $reason = 'forbidden_action_attempt';
        } elseif ($unsafeClaim) {
            $reason = 'unsafe_claim';
        } elseif (in_array($intent, $escalationIntents, true)) {
            // La tabla vive en la autoridad: la rendición del commit necesita
            // la misma y dos copias acabarían marcando con motivos distintos.
            $reason = StaffReviewAuthority::motivoDeIntencion($intent) ?? StaffReviewAuthority::MOTIVO_GENERICO;
        }

        return [
            'intent' => $intent,
            'confidence' => $confidence,
            'extracted_fields' => $extracted,
            'missing_fields' => $missing,
            'reply' => ($reply === '' ? null : $reply),
            'force_escalate' => $forceEscalate,
            'escalation_reason' => $reason,
            'risk_flags' => array_values(array_unique($flags)),
        ];
    }
}
