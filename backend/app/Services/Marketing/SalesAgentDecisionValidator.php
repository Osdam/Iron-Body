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
    /**
     * @param  array  $raw  decisión cruda devuelta por el modelo (json).
     * @return array{intent:string, confidence:float, extracted_fields:array, missing_fields:array, reply:?string, force_escalate:bool, escalation_reason:?string, risk_flags:array}
     */
    public function sanitize(array $raw): array
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
        $missing   = is_array($raw['missing_fields'] ?? null) ? $raw['missing_fields'] : [];

        // 3) Herramientas: solo las permitidas; lo demás se bloquea (no se honra).
        $tools = is_array($raw['tools_requested'] ?? null) ? $raw['tools_requested'] : [];
        $forbiddenTools = array_diff($tools, SalesAgentDecisionSchema::ALLOWED_TOOLS);
        if ($forbiddenTools !== []) {
            $flags[] = 'forbidden_tool';
        }

        // 4) Intentos PROHIBIDOS (activar membresía / aprobar pago / facturación)
        //    en cualquier parte de la salida → bloquear + escalar.
        $blob = $this->normalize(json_encode($raw, JSON_UNESCAPED_UNICODE) ?: '');
        $forbiddenAction = false;
        foreach (SalesAgentDecisionSchema::FORBIDDEN_SIGNALS as $signal) {
            if (str_contains($blob, $this->normalize($signal))) {
                $forbiddenAction = true;
                break;
            }
        }
        if ($forbiddenAction) {
            $flags[] = 'forbidden_action';
        }

        // 5) Reply saneado: nunca precios (no inventar) ni promesas/diagnóstico.
        $reply = is_string($raw['reply'] ?? null) ? trim($raw['reply']) : null;
        $unsafeClaim = false;
        if ($reply !== null && $reply !== '') {
            // Precio en el texto → se CORRIGE (se elimina; el precio real solo va
            // en el mensaje del link, tomado de Plan::price).
            if (preg_match('/(\$\s?\d)|(\bcop\b)|(\bpesos\b)|(\d{1,3}[.,]\d{3})|(\d{4,})/i', $reply)) {
                $reply = null;
                $flags[] = 'price_in_reply';
            }
        }
        if ($reply !== null && $reply !== '') {
            $needle = $this->normalize($reply);
            foreach (SalesAgentDecisionSchema::UNSAFE_REPLY_SIGNALS as $bad) {
                if (str_contains($needle, $this->normalize($bad))) {
                    $unsafeClaim = true;
                    $reply = null;
                    $flags[] = 'unsafe_claim';
                    break;
                }
            }
        }

        // 6) Escalado forzado: intención sensible, intento prohibido o claim inseguro.
        $forceEscalate = in_array($intent, SalesIntents::ESCALATION_INTENTS, true)
            || $forbiddenAction || $unsafeClaim;

        $reason = null;
        if ($forbiddenAction) {
            $reason = 'forbidden_action_attempt';
        } elseif ($unsafeClaim) {
            $reason = 'unsafe_claim';
        } elseif (in_array($intent, SalesIntents::ESCALATION_INTENTS, true)) {
            $reason = match ($intent) {
                SalesIntents::MEDICAL_RISK_ESCALATION => 'medical_case',
                SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => 'payment_or_fraud_claim',
                SalesIntents::HUMAN_REQUEST           => 'human_requested',
                SalesIntents::COMPLAINT               => 'complaint',
                SalesIntents::INVOICE_REQUEST         => 'invoice_request',
                default                               => 'escalation',
            };
        }

        return [
            'intent'            => $intent,
            'confidence'        => $confidence,
            'extracted_fields'  => $extracted,
            'missing_fields'    => $missing,
            'reply'             => ($reply === '' ? null : $reply),
            'force_escalate'    => $forceEscalate,
            'escalation_reason' => $reason,
            'risk_flags'        => array_values(array_unique($flags)),
        ];
    }

    private function normalize(string $s): string
    {
        $lower = mb_strtolower($s);
        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }
}
