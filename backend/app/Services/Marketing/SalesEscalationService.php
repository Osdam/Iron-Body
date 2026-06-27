<?php

namespace App\Services\Marketing;

/**
 * Decide cuándo escalar a un humano y por qué. Casos sensibles (médico, fraude
 * o reclamo de pago, facturación, devoluciones, reclamos) NO los responde la IA:
 * se derivan a una persona. Devuelve banderas de riesgo para auditoría.
 */
class SalesEscalationService
{
    /** Palabras que disparan escalado adicional (facturación / reclamos / devolución). */
    private const SENSITIVE_KEYWORDS = [
        'factura', 'facturacion', 'facturación', 'devolucion', 'devolución', 'reembolso',
        'reclamo', 'queja', 'demanda', 'estafa', 'tutela', 'sic', 'profeco',
    ];

    /**
     * @return array{should_escalate:bool, escalation_reason:?string, risk_flags:array}
     */
    public function evaluate(string $intent, string $body): array
    {
        $flags = [];
        $reason = null;

        if ($intent === SalesIntents::MEDICAL_RISK_ESCALATION) {
            $flags[] = 'medical';
            $reason = 'medical_case';
        }

        if ($intent === SalesIntents::FRAUD_OR_PAYMENT_CLAIM) {
            $flags[] = 'payment_claim';
            $reason ??= 'payment_or_fraud_claim';
        }

        // Temas sensibles por palabras clave (facturación / reclamos / devoluciones).
        $text = mb_strtolower($body);
        foreach (self::SENSITIVE_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                $flags[] = 'sensitive_topic';
                $reason ??= 'sensitive_topic';
                break;
            }
        }

        return [
            'should_escalate'   => $flags !== [],
            'escalation_reason' => $reason,
            'risk_flags'        => array_values(array_unique($flags)),
        ];
    }
}
