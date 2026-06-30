<?php

namespace App\Services\Marketing;

/**
 * Catálogo de intenciones comerciales, temperaturas y etapas. Centraliza las
 * constantes que comparten clasificador, scoring, reply, escalation y guardrail.
 *
 * Alias de nombres "de negocio" del brief → constante canónica usada en código:
 *   price_question   → PRICING_QUESTION       schedule_question → SCHEDULE_QUESTION
 *   location_question→ LOCATION_QUESTION       objection_price  → PRICE_OBJECTION
 *   objection_time   → TIME_OBJECTION          payment_intent   → PAYMENT_LINK_REQUEST
 *   medical_or_injury→ MEDICAL_RISK_ESCALATION beginner_fear    → BEGINNER_FEAR
 *   general_info     → GENERAL_INFO            human_request    → HUMAN_REQUEST
 *   complaint        → COMPLAINT               spam_or_low_quality → SPAM_LOW_QUALITY
 *   goal_fat_loss    → GOAL_FAT_LOSS           goal_muscle_gain → GOAL_MUSCLE_GAIN
 */
final class SalesIntents
{
    // ── Intenciones ──────────────────────────────────────────────────────────
    public const PRICING_QUESTION        = 'pricing_question';
    public const PAYMENT_LINK_REQUEST    = 'payment_link_request';
    public const PRICE_OBJECTION         = 'price_objection';
    public const TIME_OBJECTION          = 'time_objection';
    public const DELAY_OBJECTION         = 'delay_objection';
    public const BEGINNER_FEAR           = 'beginner_fear';
    public const INSECURITY_BODY         = 'insecurity_body';
    public const LOCATION_QUESTION       = 'location_question';
    public const SCHEDULE_QUESTION       = 'schedule_question';
    public const GENERAL_INFO            = 'general_info';
    public const GOAL_FAT_LOSS           = 'goal_fat_loss';
    public const GOAL_MUSCLE_GAIN        = 'goal_muscle_gain';
    public const HIGH_INTENT_CLOSE       = 'high_intent_close';
    public const GREETING                = 'greeting';
    public const THANKS                  = 'thanks';
    public const GOODBYE                 = 'goodbye';
    public const NOT_INTERESTED          = 'not_interested';
    public const BOT_QUESTION            = 'bot_question';
    public const HUMAN_REQUEST           = 'human_request';
    public const COMPLAINT               = 'complaint';
    public const INVOICE_REQUEST         = 'invoice_request';
    public const MEDICAL_RISK_ESCALATION = 'medical_risk_escalation';
    public const FRAUD_OR_PAYMENT_CLAIM  = 'fraud_or_payment_claim';
    public const DO_NOT_CONTACT_REQUEST  = 'do_not_contact_request';
    public const SPAM_LOW_QUALITY        = 'spam_or_low_quality';
    public const UNKNOWN                 = 'unknown';

    // ── Temperaturas comerciales ─────────────────────────────────────────────
    public const TEMP_COLD     = 'cold';
    public const TEMP_WARM     = 'warm';
    public const TEMP_HOT      = 'hot';
    public const TEMP_VERY_HOT = 'very_hot';
    public const TEMP_RISK     = 'risk';

    // ── Etapas del embudo (internas, ricas) ──────────────────────────────────
    public const STAGE_DISCOVERY = 'discovery';
    public const STAGE_OBJECTION = 'objection';
    public const STAGE_CLOSING   = 'closing';
    public const STAGE_RISK      = 'risk';
    public const STAGE_OPT_OUT   = 'opt_out';

    // ── Etapas del lead (taxonomía comercial del brief) ───────────────────────
    public const LEAD_STAGE_NEW           = 'new';
    public const LEAD_STAGE_INFORMED      = 'informed';
    public const LEAD_STAGE_INTERESTED    = 'interested';
    public const LEAD_STAGE_READY_TO_PAY  = 'ready_to_pay';
    public const LEAD_STAGE_NEEDS_HUMAN   = 'needs_human';
    public const LEAD_STAGE_LOST          = 'lost';

    // ── Acciones recomendadas ────────────────────────────────────────────────
    public const ACTION_REPLY                 = 'reply';
    public const ACTION_GENERATE_PAYMENT_LINK = 'generate_payment_link';
    public const ACTION_SCHEDULE_FOLLOWUP     = 'schedule_followup';
    public const ACTION_ESCALATE_HUMAN        = 'escalate_human';
    public const ACTION_NO_REPLY              = 'no_reply';
    public const ACTION_BLOCKED_DNC           = 'blocked_do_not_contact';
    public const ACTION_MARK_DNC              = 'mark_do_not_contact';
    public const ACTION_REGISTER_OBJECTION    = 'register_objection';

    // ── Herramientas que la decisión puede solicitar (auto_execute seguro) ────
    public const TOOL_PAYMENT_LINK_SEND = 'payment_link_send';
    public const TOOL_SCHEDULE_FOLLOWUP = 'schedule_followup';
    public const TOOL_HUMAN_TAKEOVER    = 'human_takeover';
    public const TOOL_MARK_DNC          = 'mark_do_not_contact';

    /** Intenciones que SIEMPRE escalan a humano (riesgo / sensibles). */
    public const ESCALATION_INTENTS = [
        self::MEDICAL_RISK_ESCALATION,
        self::FRAUD_OR_PAYMENT_CLAIM,
        self::HUMAN_REQUEST,
        self::COMPLAINT,
        self::INVOICE_REQUEST,
    ];

    /** Intenciones de cierre suave: el usuario se despide / no quiere / agradece. */
    public const SOFT_CLOSE_INTENTS = [
        self::GOODBYE,
        self::NOT_INTERESTED,
        self::THANKS,
    ];

    /** Intenciones de objeción comercial (empatía + valor + pregunta suave). */
    public const OBJECTION_INTENTS = [
        self::PRICE_OBJECTION,
        self::TIME_OBJECTION,
        self::DELAY_OBJECTION,
        self::BEGINNER_FEAR,
        self::INSECURITY_BODY,
    ];

    /** Intenciones donde el lead declara un objetivo fitness. */
    public const GOAL_INTENTS = [
        self::GOAL_FAT_LOSS,
        self::GOAL_MUSCLE_GAIN,
    ];

    /** Intenciones que solicitan un link de pago. */
    public const PAYMENT_INTENTS = [
        self::PAYMENT_LINK_REQUEST,
        self::HIGH_INTENT_CLOSE,
    ];
}
