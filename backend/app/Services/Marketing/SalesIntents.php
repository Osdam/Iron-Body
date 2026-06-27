<?php

namespace App\Services\Marketing;

/**
 * Catálogo de intenciones comerciales, temperaturas y etapas. Centraliza las
 * constantes que comparten clasificador, scoring, reply, escalation y guardrail.
 */
final class SalesIntents
{
    // ── Intenciones ──────────────────────────────────────────────────────────
    public const PRICING_QUESTION        = 'pricing_question';
    public const PAYMENT_LINK_REQUEST    = 'payment_link_request';
    public const PRICE_OBJECTION         = 'price_objection';
    public const LOCATION_QUESTION       = 'location_question';
    public const SCHEDULE_QUESTION       = 'schedule_question';
    public const HIGH_INTENT_CLOSE       = 'high_intent_close';
    public const MEDICAL_RISK_ESCALATION = 'medical_risk_escalation';
    public const FRAUD_OR_PAYMENT_CLAIM  = 'fraud_or_payment_claim';
    public const DO_NOT_CONTACT_REQUEST  = 'do_not_contact_request';
    public const UNKNOWN                 = 'unknown';

    // ── Temperaturas comerciales ─────────────────────────────────────────────
    public const TEMP_COLD     = 'cold';
    public const TEMP_WARM     = 'warm';
    public const TEMP_HOT      = 'hot';
    public const TEMP_VERY_HOT = 'very_hot';
    public const TEMP_RISK     = 'risk';

    // ── Etapas del embudo ────────────────────────────────────────────────────
    public const STAGE_DISCOVERY = 'discovery';
    public const STAGE_OBJECTION = 'objection';
    public const STAGE_CLOSING   = 'closing';
    public const STAGE_RISK      = 'risk';
    public const STAGE_OPT_OUT   = 'opt_out';

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
    ];

    /** Intenciones que solicitan un link de pago. */
    public const PAYMENT_INTENTS = [
        self::PAYMENT_LINK_REQUEST,
        self::HIGH_INTENT_CLOSE,
    ];
}
