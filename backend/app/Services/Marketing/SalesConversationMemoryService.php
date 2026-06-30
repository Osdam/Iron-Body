<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;

/**
 * Memoria comercial corta por conversación. Tras cada análisis guarda un resumen
 * legible, el objetivo detectado, el score, la etapa y las intenciones (principal
 * + última). Ese resumen se reinyecta en el siguiente análisis (prompt del
 * cerebro) para dar continuidad sin volver a preguntar lo ya dicho.
 *
 * PURO respecto al negocio: NO activa pagos ni membresías; solo persiste señales.
 */
class SalesConversationMemoryService
{
    /** Objetivos legibles (interno → texto comercial corto). */
    private const OBJECTIVE_LABELS = [
        'fat_loss'     => 'bajar grasa',
        'muscle_gain'  => 'ganar masa',
        'conditioning' => 'mejorar condición',
        'return'       => 'retomar',
        'health'       => 'salud',
        'discipline'   => 'disciplina',
    ];

    /**
     * Actualiza la memoria de la conversación a partir de la decisión ya derivada.
     * primary_intent se fija con la PRIMERA intención significativa (no unknown/
     * spam) y no se sobreescribe; last_intent siempre refleja la última.
     */
    public function remember(MarketingConversation $conversation, array $decision, string $body): MarketingConversation
    {
        $intent    = (string) ($decision['intent'] ?? SalesIntents::UNKNOWN);
        $objective = $this->resolveObjective($conversation, $decision);

        $primary = $conversation->primary_intent;
        if (($primary === null || $primary === SalesIntents::UNKNOWN) && $this->isSignificant($intent)) {
            $primary = $intent;
        }

        $conversation->forceFill([
            'detected_objective' => $objective,
            'lead_score'         => $decision['lead_score'] ?? $conversation->lead_score,
            'lead_stage'         => $decision['lead_stage'] ?? $conversation->lead_stage,
            'primary_intent'     => $primary,
            'last_intent'        => $intent,
            'summary'            => $this->buildSummary($conversation, $decision, $objective, $body),
        ])->save();

        return $conversation;
    }

    /** Resumen corto y humano (sin datos sensibles ni precios). */
    public function buildSummary(MarketingConversation $conversation, array $decision, ?string $objective, string $body): string
    {
        $parts = [];

        $objLabel = $objective !== null ? (self::OBJECTIVE_LABELS[$objective] ?? $objective) : null;
        if ($objLabel !== null) {
            $parts[] = 'Objetivo: '.$objLabel;
        }

        $parts[] = 'Última intención: '.($decision['intent'] ?? SalesIntents::UNKNOWN);

        if (isset($decision['lead_stage'])) {
            $parts[] = 'Etapa: '.$decision['lead_stage'];
        }
        if (isset($decision['lead_score'])) {
            $parts[] = 'Score: '.$decision['lead_score'];
        }
        if (! empty($decision['should_escalate'])) {
            $parts[] = 'Requiere humano ('.($decision['escalation_reason'] ?? 'escalación').')';
        }

        $snippet = trim(preg_replace('/\s+/', ' ', $body) ?? '');
        if ($snippet !== '') {
            $parts[] = 'Dijo: "'.mb_substr($snippet, 0, 80).'"';
        }

        return implode('. ', $parts).'.';
    }

    /**
     * Objetivo a recordar: el que llega en extracted_fields, si no el ya guardado
     * en la conversación (memoria), si no el del lead. null si no hay ninguno.
     */
    private function resolveObjective(MarketingConversation $conversation, array $decision): ?string
    {
        $fromDecision = $decision['extracted_fields']['objective'] ?? null;
        if (is_string($fromDecision) && $fromDecision !== '') {
            return $fromDecision;
        }

        return $conversation->detected_objective
            ?: ($conversation->lead?->objective ?: null);
    }

    private function isSignificant(string $intent): bool
    {
        return ! in_array($intent, [SalesIntents::UNKNOWN, SalesIntents::SPAM_LOW_QUALITY], true);
    }
}
