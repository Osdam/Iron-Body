<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Contracts\AiSalesResponderInterface;

/**
 * Clasifica la intención de un mensaje del lead delegando en el responder
 * configurado (fake/openai). Normaliza la salida y degrada a `unknown` si el
 * cerebro está deshabilitado o devuelve algo inválido (nunca lanza).
 */
class SalesIntentClassifierService
{
    public function __construct(private readonly AiSalesResponderInterface $responder)
    {
    }

    /**
     * @return array{intent:string, confidence:float, extracted_fields:array, missing_fields:array, responder:string}
     */
    public function classify(string $body, array $context = []): array
    {
        if (! (bool) config('marketing.ai.enabled', true)) {
            return $this->fallback('disabled');
        }

        try {
            $result = $this->responder->classify($body, $context);
        } catch (\Throwable $e) {
            return $this->fallback($this->responder->name());
        }

        return [
            'intent'            => (string) ($result['intent'] ?? SalesIntents::UNKNOWN),
            'confidence'        => (float) ($result['confidence'] ?? 0.0),
            'extracted_fields'  => (array) ($result['extracted_fields'] ?? []),
            'missing_fields'    => (array) ($result['missing_fields'] ?? []),
            // El responder efectivo puede ser distinto del nombre (openai → fallback).
            'responder'         => (string) ($result['responder'] ?? $this->responder->name()),
            // Campos opcionales del cerebro OpenAI (el fake no los aporta).
            'reply'             => isset($result['reply']) && is_string($result['reply']) ? $result['reply'] : null,
            'force_escalate'    => (bool) ($result['force_escalate'] ?? false),
            'escalation_reason' => $result['escalation_reason'] ?? null,
            'risk_flags'        => (array) ($result['risk_flags'] ?? []),
        ];
    }

    private function fallback(string $responder): array
    {
        return [
            'intent'            => SalesIntents::UNKNOWN,
            'confidence'        => 0.0,
            'extracted_fields'  => [],
            'missing_fields'    => [],
            'responder'         => $responder,
            'reply'             => null,
            'force_escalate'    => false,
            'escalation_reason' => null,
            'risk_flags'        => [],
        ];
    }
}
