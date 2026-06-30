<?php

namespace App\Services\Marketing;

/**
 * Catálogo de valores PERMITIDOS para la decisión del cerebro comercial y la
 * descripción del contrato JSON que debe devolver el modelo. Centraliza los
 * enums que usan el prompt y el validador (Laravel tiene la última palabra).
 */
final class SalesAgentDecisionSchema
{
    public const INTENTS = [
        SalesIntents::PRICING_QUESTION,
        SalesIntents::PAYMENT_LINK_REQUEST,
        SalesIntents::PRICE_OBJECTION,
        SalesIntents::TIME_OBJECTION,
        SalesIntents::DELAY_OBJECTION,
        SalesIntents::BEGINNER_FEAR,
        SalesIntents::INSECURITY_BODY,
        SalesIntents::LOCATION_QUESTION,
        SalesIntents::SCHEDULE_QUESTION,
        SalesIntents::GENERAL_INFO,
        SalesIntents::GOAL_FAT_LOSS,
        SalesIntents::GOAL_MUSCLE_GAIN,
        SalesIntents::GOAL_RECOMPOSITION,
        SalesIntents::HIGH_INTENT_CLOSE,
        SalesIntents::GREETING,
        SalesIntents::THANKS,
        SalesIntents::GOODBYE,
        SalesIntents::NOT_INTERESTED,
        SalesIntents::BOT_QUESTION,
        SalesIntents::HUMAN_REQUEST,
        SalesIntents::COMPLAINT,
        SalesIntents::INVOICE_REQUEST,
        SalesIntents::MEDICAL_RISK_ESCALATION,
        SalesIntents::FRAUD_OR_PAYMENT_CLAIM,
        SalesIntents::DO_NOT_CONTACT_REQUEST,
        SalesIntents::SPAM_LOW_QUALITY,
        SalesIntents::UNKNOWN,
    ];

    public const TEMPERATURES = [
        SalesIntents::TEMP_COLD, SalesIntents::TEMP_WARM, SalesIntents::TEMP_HOT,
        SalesIntents::TEMP_VERY_HOT, SalesIntents::TEMP_RISK,
    ];

    public const STAGES = [
        SalesIntents::STAGE_DISCOVERY, SalesIntents::STAGE_OBJECTION,
        SalesIntents::STAGE_CLOSING, SalesIntents::STAGE_RISK, SalesIntents::STAGE_OPT_OUT,
    ];

    /** Taxonomía comercial del lead (la deriva Laravel, no el modelo). */
    public const LEAD_STAGES = [
        SalesIntents::LEAD_STAGE_NEW, SalesIntents::LEAD_STAGE_INFORMED,
        SalesIntents::LEAD_STAGE_INTERESTED, SalesIntents::LEAD_STAGE_READY_TO_PAY,
        SalesIntents::LEAD_STAGE_NEEDS_HUMAN, SalesIntents::LEAD_STAGE_LOST,
    ];

    public const RECOMMENDED_ACTIONS = [
        SalesIntents::ACTION_REPLY, SalesIntents::ACTION_GENERATE_PAYMENT_LINK,
        SalesIntents::ACTION_SCHEDULE_FOLLOWUP, SalesIntents::ACTION_STAFF_REVIEW,
        SalesIntents::ACTION_ESCALATE_HUMAN, SalesIntents::ACTION_NO_REPLY,
        SalesIntents::ACTION_BLOCKED_DNC, SalesIntents::ACTION_MARK_DNC,
        SalesIntents::ACTION_REGISTER_OBJECTION,
    ];

    /**
     * Únicas herramientas que el modelo puede solicitar (lo demás se bloquea).
     * NO incluye human_takeover: la IA NUNCA se apaga sola. Los casos sensibles
     * usan staff_review (alerta interna que no apaga la IA).
     */
    public const ALLOWED_TOOLS = [
        SalesIntents::TOOL_PAYMENT_LINK_SEND,
        SalesIntents::TOOL_SCHEDULE_FOLLOWUP,
        SalesIntents::TOOL_STAFF_REVIEW,
        SalesIntents::TOOL_MARK_DNC,
        'reply',
    ];

    /**
     * Señales de intentos PROHIBIDOS (activar membresía, aprobar pago, tocar
     * facturación, prometer resultados, diagnosticar). Si aparecen en la salida
     * del modelo → se bloquea y se escala. Sin acentos, en minúscula.
     */
    public const FORBIDDEN_SIGNALS = [
        'activate_membership', 'activar membresia', 'activar membresía',
        'approve_payment', 'aprobar pago', 'marcar pagado', 'mark_paid',
        'confirmar pago', 'activar acceso', 'grant_access',
        'emitir factura', 'anular factura', 'nota credito', 'nota crédito',
        'reembolso', 'devolucion aprobada',
    ];

    /** Frases inseguras en el `reply` (promesas / diagnóstico médico). */
    public const UNSAFE_REPLY_SIGNALS = [
        'garantizado', 'garantizamos', 'te garantizo', 'resultados asegurados',
        'cura', 'curar', 'diagnostico', 'diagnóstico', 'es una hernia',
        'tienes una lesion', 'tienes una lesión', 'no es nada grave',
    ];

    /** @return string[] claves obligatorias del contrato de decisión. */
    public static function requiredKeys(): array
    {
        return [
            'ok', 'intent', 'confidence', 'temperature', 'sales_stage', 'should_reply',
            'should_generate_payment_link', 'should_send_message', 'should_schedule_followup',
            'followup_delay_minutes', 'should_escalate', 'escalation_reason', 'risk_flags',
            'extracted_fields', 'missing_fields', 'recommended_action', 'reply',
            'tools_requested', 'safe_to_send',
        ];
    }
}
