<?php

namespace App\Services\Marketing;

/**
 * Calcula la temperatura comercial, la etapa del embudo y el retraso de
 * seguimiento a partir de la intención. Lógica PURA y determinista (sin BD ni
 * side effects) para poder testearla con vectores fijos.
 */
class SalesLeadScoringService
{
    /** Intención → temperatura. */
    private const TEMP_MAP = [
        SalesIntents::PAYMENT_LINK_REQUEST    => SalesIntents::TEMP_VERY_HOT,
        SalesIntents::HIGH_INTENT_CLOSE       => SalesIntents::TEMP_VERY_HOT,
        SalesIntents::PRICE_OBJECTION         => SalesIntents::TEMP_HOT,
        SalesIntents::TIME_OBJECTION          => SalesIntents::TEMP_HOT,
        SalesIntents::DELAY_OBJECTION          => SalesIntents::TEMP_WARM,
        SalesIntents::BEGINNER_FEAR           => SalesIntents::TEMP_WARM,
        SalesIntents::INSECURITY_BODY         => SalesIntents::TEMP_WARM,
        SalesIntents::PRICING_QUESTION        => SalesIntents::TEMP_WARM,
        SalesIntents::GOAL_FAT_LOSS           => SalesIntents::TEMP_WARM,
        SalesIntents::GOAL_MUSCLE_GAIN        => SalesIntents::TEMP_WARM,
        SalesIntents::GOAL_RECOMPOSITION      => SalesIntents::TEMP_WARM,
        SalesIntents::LOCATION_QUESTION       => SalesIntents::TEMP_WARM,
        SalesIntents::SCHEDULE_QUESTION       => SalesIntents::TEMP_WARM,
        SalesIntents::GENERAL_INFO            => SalesIntents::TEMP_COLD,
        SalesIntents::GREETING                => SalesIntents::TEMP_COLD,
        SalesIntents::THANKS                  => SalesIntents::TEMP_COLD,
        SalesIntents::GOODBYE                 => SalesIntents::TEMP_COLD,
        SalesIntents::NOT_INTERESTED          => SalesIntents::TEMP_COLD,
        SalesIntents::BOT_QUESTION            => SalesIntents::TEMP_COLD,
        SalesIntents::HUMAN_REQUEST           => SalesIntents::TEMP_RISK,
        SalesIntents::COMPLAINT               => SalesIntents::TEMP_RISK,
        SalesIntents::INVOICE_REQUEST         => SalesIntents::TEMP_RISK,
        SalesIntents::MEDICAL_RISK_ESCALATION => SalesIntents::TEMP_RISK,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => SalesIntents::TEMP_RISK,
        SalesIntents::DO_NOT_CONTACT_REQUEST  => SalesIntents::TEMP_COLD,
        SalesIntents::SPAM_LOW_QUALITY        => SalesIntents::TEMP_COLD,
        SalesIntents::UNKNOWN                 => SalesIntents::TEMP_COLD,
    ];

    /** Intención → etapa del embudo (interna, rica). */
    private const STAGE_MAP = [
        SalesIntents::PAYMENT_LINK_REQUEST    => SalesIntents::STAGE_CLOSING,
        SalesIntents::HIGH_INTENT_CLOSE       => SalesIntents::STAGE_CLOSING,
        SalesIntents::PRICE_OBJECTION         => SalesIntents::STAGE_OBJECTION,
        SalesIntents::TIME_OBJECTION          => SalesIntents::STAGE_OBJECTION,
        SalesIntents::DELAY_OBJECTION          => SalesIntents::STAGE_OBJECTION,
        SalesIntents::BEGINNER_FEAR           => SalesIntents::STAGE_OBJECTION,
        SalesIntents::INSECURITY_BODY         => SalesIntents::STAGE_OBJECTION,
        SalesIntents::PRICING_QUESTION        => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GOAL_FAT_LOSS           => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GOAL_MUSCLE_GAIN        => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GOAL_RECOMPOSITION      => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::LOCATION_QUESTION       => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::SCHEDULE_QUESTION       => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GENERAL_INFO            => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GREETING                => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::BOT_QUESTION            => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::THANKS                  => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::GOODBYE                 => SalesIntents::STAGE_OPT_OUT,
        SalesIntents::NOT_INTERESTED          => SalesIntents::STAGE_OPT_OUT,
        SalesIntents::HUMAN_REQUEST           => SalesIntents::STAGE_RISK,
        SalesIntents::COMPLAINT               => SalesIntents::STAGE_RISK,
        SalesIntents::INVOICE_REQUEST         => SalesIntents::STAGE_RISK,
        SalesIntents::MEDICAL_RISK_ESCALATION => SalesIntents::STAGE_RISK,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => SalesIntents::STAGE_RISK,
        SalesIntents::DO_NOT_CONTACT_REQUEST  => SalesIntents::STAGE_OPT_OUT,
        SalesIntents::SPAM_LOW_QUALITY        => SalesIntents::STAGE_OPT_OUT,
        SalesIntents::UNKNOWN                 => SalesIntents::STAGE_DISCOVERY,
    ];

    /** Intención → score base comercial (0-100). Determinista. */
    private const SCORE_MAP = [
        SalesIntents::PAYMENT_LINK_REQUEST    => 95,
        SalesIntents::HIGH_INTENT_CLOSE       => 92,
        SalesIntents::PRICE_OBJECTION         => 72,
        SalesIntents::TIME_OBJECTION          => 66,
        SalesIntents::PRICING_QUESTION        => 60,
        SalesIntents::BEGINNER_FEAR           => 55,
        SalesIntents::DELAY_OBJECTION          => 52,
        SalesIntents::GOAL_FAT_LOSS           => 50,
        SalesIntents::GOAL_MUSCLE_GAIN        => 50,
        SalesIntents::GOAL_RECOMPOSITION      => 50,
        SalesIntents::SCHEDULE_QUESTION       => 45,
        SalesIntents::LOCATION_QUESTION       => 42,
        SalesIntents::INSECURITY_BODY         => 55,
        SalesIntents::GENERAL_INFO            => 35,
        SalesIntents::GREETING                => 30,
        SalesIntents::BOT_QUESTION            => 30,
        SalesIntents::THANKS                  => 25,
        SalesIntents::HUMAN_REQUEST           => 50,
        SalesIntents::COMPLAINT               => 40,
        SalesIntents::INVOICE_REQUEST         => 40,
        SalesIntents::MEDICAL_RISK_ESCALATION => 45,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => 30,
        SalesIntents::GOODBYE                 => 10,
        SalesIntents::NOT_INTERESTED          => 5,
        SalesIntents::DO_NOT_CONTACT_REQUEST  => 0,
        SalesIntents::SPAM_LOW_QUALITY        => 0,
        SalesIntents::UNKNOWN                 => 20,
    ];

    public function temperature(string $intent): string
    {
        return self::TEMP_MAP[$intent] ?? SalesIntents::TEMP_COLD;
    }

    public function salesStage(string $intent): string
    {
        return self::STAGE_MAP[$intent] ?? SalesIntents::STAGE_DISCOVERY;
    }

    /**
     * Score comercial 0-100 de la conversación. Parte del score base por
     * intención y suma una pequeña bonificación si el lead ya declaró un objetivo
     * (señal de calificación). Acotado a [0,100]. Determinista (testeable).
     */
    public function score(string $intent, array $context = []): int
    {
        $base = self::SCORE_MAP[$intent] ?? 20;

        // Bonus suave si hay objetivo declarado (en este mensaje o en memoria).
        $hasObjective = ! empty($context['objective'])
            || ! empty(($context['extracted_fields']['objective'] ?? null));
        if ($hasObjective && $base > 0 && $base < 90) {
            $base += 8;
        }

        return max(0, min(100, $base));
    }

    /**
     * Etapa comercial del lead (taxonomía del brief). La deriva Laravel a partir
     * de la intención y de si se va a escalar. Nunca la decide el modelo.
     */
    public function leadStage(string $intent, bool $escalate): string
    {
        // Intención de pago: el lead está listo para pagar aunque se escale a un
        // humano para compartirle el medio de pago (Wompi aún no productivo).
        if (in_array($intent, SalesIntents::PAYMENT_INTENTS, true)) {
            return SalesIntents::LEAD_STAGE_READY_TO_PAY;
        }

        if ($escalate) {
            return SalesIntents::LEAD_STAGE_NEEDS_HUMAN;
        }

        return match (true) {
            in_array($intent, SalesIntents::PAYMENT_INTENTS, true) => SalesIntents::LEAD_STAGE_READY_TO_PAY,
            $intent === SalesIntents::DO_NOT_CONTACT_REQUEST,
            $intent === SalesIntents::SPAM_LOW_QUALITY,
            $intent === SalesIntents::GOODBYE,
            $intent === SalesIntents::NOT_INTERESTED              => SalesIntents::LEAD_STAGE_LOST,
            in_array($intent, SalesIntents::OBJECTION_INTENTS, true),
            $intent === SalesIntents::PRICING_QUESTION           => SalesIntents::LEAD_STAGE_INTERESTED,
            in_array($intent, SalesIntents::GOAL_INTENTS, true),
            $intent === SalesIntents::LOCATION_QUESTION,
            $intent === SalesIntents::SCHEDULE_QUESTION,
            $intent === SalesIntents::GENERAL_INFO               => SalesIntents::LEAD_STAGE_INFORMED,
            default                                              => SalesIntents::LEAD_STAGE_NEW,
        };
    }

    /** Temperatura simplificada a la taxonomía del CRM (cold/warm/hot). */
    public function crmTemperature(string $intent): string
    {
        return match ($this->temperature($intent)) {
            SalesIntents::TEMP_VERY_HOT, SalesIntents::TEMP_HOT => 'hot',
            SalesIntents::TEMP_WARM                             => 'warm',
            default                                             => 'cold',
        };
    }

    /** ¿Conviene programar un seguimiento? Solo leads "tibios o más". */
    public function shouldScheduleFollowup(string $temperature): bool
    {
        return in_array($temperature, [
            SalesIntents::TEMP_WARM, SalesIntents::TEMP_HOT, SalesIntents::TEMP_VERY_HOT,
        ], true);
    }

    /** Retraso de seguimiento (minutos) por temperatura, desde config. null si no aplica. */
    public function followupDelayMinutes(string $temperature): ?int
    {
        $delays = (array) config('marketing.ai.followup_delays', []);
        return match ($temperature) {
            SalesIntents::TEMP_VERY_HOT => (int) ($delays['very_hot'] ?? 60),
            SalesIntents::TEMP_HOT      => (int) ($delays['hot'] ?? 120),
            SalesIntents::TEMP_WARM     => (int) ($delays['warm'] ?? 360),
            default                     => null,
        };
    }
}
