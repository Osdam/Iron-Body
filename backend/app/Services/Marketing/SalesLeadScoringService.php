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
        SalesIntents::PRICING_QUESTION        => SalesIntents::TEMP_WARM,
        SalesIntents::LOCATION_QUESTION       => SalesIntents::TEMP_WARM,
        SalesIntents::SCHEDULE_QUESTION       => SalesIntents::TEMP_WARM,
        SalesIntents::MEDICAL_RISK_ESCALATION => SalesIntents::TEMP_RISK,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => SalesIntents::TEMP_RISK,
        SalesIntents::DO_NOT_CONTACT_REQUEST  => SalesIntents::TEMP_COLD,
        SalesIntents::UNKNOWN                 => SalesIntents::TEMP_COLD,
    ];

    /** Intención → etapa del embudo. */
    private const STAGE_MAP = [
        SalesIntents::PAYMENT_LINK_REQUEST    => SalesIntents::STAGE_CLOSING,
        SalesIntents::HIGH_INTENT_CLOSE       => SalesIntents::STAGE_CLOSING,
        SalesIntents::PRICE_OBJECTION         => SalesIntents::STAGE_OBJECTION,
        SalesIntents::PRICING_QUESTION        => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::LOCATION_QUESTION       => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::SCHEDULE_QUESTION       => SalesIntents::STAGE_DISCOVERY,
        SalesIntents::MEDICAL_RISK_ESCALATION => SalesIntents::STAGE_RISK,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM  => SalesIntents::STAGE_RISK,
        SalesIntents::DO_NOT_CONTACT_REQUEST  => SalesIntents::STAGE_OPT_OUT,
        SalesIntents::UNKNOWN                 => SalesIntents::STAGE_DISCOVERY,
    ];

    public function temperature(string $intent): string
    {
        return self::TEMP_MAP[$intent] ?? SalesIntents::TEMP_COLD;
    }

    public function salesStage(string $intent): string
    {
        return self::STAGE_MAP[$intent] ?? SalesIntents::STAGE_DISCOVERY;
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
