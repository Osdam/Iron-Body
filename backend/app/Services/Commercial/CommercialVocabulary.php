<?php

namespace App\Services\Commercial;

/**
 * El vocabulario del motor comercial en un solo sitio.
 *
 * Objetivos, acciones, segmentos y eventos son cadenas que viajan entre la base
 * de datos, el panel y las pruebas. Dispersarlas por el código garantiza que
 * antes o después alguien escriba `renewal_due` en un sitio y `renew_due` en
 * otro, y que el fallo aparezca en producción y no en una prueba.
 *
 * Nada de esto lo elige el modelo de lenguaje: son los valores que Laravel
 * acepta, y cualquier otro se rechaza.
 */
final class CommercialVocabulary
{
    // ── Objetivos comerciales ────────────────────────────────────────────────
    // Lo que se persigue con esta persona AHORA. Uno activo por sujeto.

    public const GOAL_COLLECT_DATA = 'collect_data';

    public const GOAL_BOOK_VISIT = 'book_visit';

    public const GOAL_BOOK_ASSESSMENT = 'book_assessment';

    public const GOAL_CLOSE_PLAN = 'close_plan';

    public const GOAL_COLLECT_PAYMENT = 'collect_payment';

    public const GOAL_RECOVER_PAYMENT_LINK = 'recover_payment_link';

    public const GOAL_ACTIVATE_MEMBERSHIP = 'activate_membership';

    public const GOAL_COMPLETE_ONBOARDING = 'complete_onboarding';

    public const GOAL_LINK_APP = 'link_app';

    public const GOAL_INCREASE_ADHERENCE = 'increase_adherence';

    public const GOAL_RENEW = 'renew';

    public const GOAL_UPGRADE = 'upgrade';

    public const GOAL_CROSS_SELL = 'cross_sell';

    public const GOAL_REACTIVATE = 'reactivate';

    public const GOAL_REQUEST_REFERRAL = 'request_referral';

    public const GOAL_RESOLVE_OBJECTION = 'resolve_objection';

    public const GOAL_RECOVER_SATISFACTION = 'recover_satisfaction';

    public const GOAL_ESCALATE = 'escalate';

    public const GOALS = [
        self::GOAL_COLLECT_DATA, self::GOAL_BOOK_VISIT, self::GOAL_BOOK_ASSESSMENT,
        self::GOAL_CLOSE_PLAN, self::GOAL_COLLECT_PAYMENT, self::GOAL_RECOVER_PAYMENT_LINK,
        self::GOAL_ACTIVATE_MEMBERSHIP, self::GOAL_COMPLETE_ONBOARDING, self::GOAL_LINK_APP,
        self::GOAL_INCREASE_ADHERENCE, self::GOAL_RENEW, self::GOAL_UPGRADE,
        self::GOAL_CROSS_SELL, self::GOAL_REACTIVATE, self::GOAL_REQUEST_REFERRAL,
        self::GOAL_RESOLVE_OBJECTION, self::GOAL_RECOVER_SATISFACTION, self::GOAL_ESCALATE,
    ];

    // ── Acciones ─────────────────────────────────────────────────────────────

    public const ACTION_ASK_DISCOVERY = 'ask_discovery';

    public const ACTION_RECOMMEND_PLAN = 'recommend_plan';

    public const ACTION_SEND_PAYMENT_LINK = 'send_payment_link';

    public const ACTION_RESEND_PAYMENT_LINK = 'resend_payment_link';

    public const ACTION_OFFER_APPOINTMENT = 'offer_appointment';

    public const ACTION_CONFIRM_ACTIVATION = 'confirm_activation';

    public const ACTION_GUIDE_APP_LINK = 'guide_app_link';

    public const ACTION_CHECK_IN = 'check_in';

    public const ACTION_OFFER_UPGRADE = 'offer_upgrade';

    public const ACTION_OFFER_RENEWAL = 'offer_renewal';

    public const ACTION_OFFER_COMPLEMENT = 'offer_complement';

    public const ACTION_ASK_REFERRAL = 'ask_referral';

    public const ACTION_HANDLE_OBJECTION = 'handle_objection';

    /**
     * No hacer nada TODAVÍA. Es una decisión de pleno derecho y la más
     * infravalorada: escribirle a alguien que acaba de pagar para venderle otra
     * cosa deteriora la relación más de lo que aporta.
     */
    public const ACTION_WAIT = 'wait';

    public const ACTION_ESCALATE_HUMAN = 'escalate_human';

    public const ACTIONS = [
        self::ACTION_ASK_DISCOVERY, self::ACTION_RECOMMEND_PLAN, self::ACTION_SEND_PAYMENT_LINK,
        self::ACTION_RESEND_PAYMENT_LINK, self::ACTION_OFFER_APPOINTMENT,
        self::ACTION_CONFIRM_ACTIVATION, self::ACTION_GUIDE_APP_LINK, self::ACTION_CHECK_IN,
        self::ACTION_OFFER_UPGRADE, self::ACTION_OFFER_RENEWAL, self::ACTION_OFFER_COMPLEMENT,
        self::ACTION_ASK_REFERRAL, self::ACTION_HANDLE_OBJECTION, self::ACTION_WAIT,
        self::ACTION_ESCALATE_HUMAN,
    ];

    // ── Segmentos ────────────────────────────────────────────────────────────

    public const SEG_NEW_PROSPECT = 'new_prospect';

    public const SEG_QUALIFIED_PROSPECT = 'qualified_prospect';

    public const SEG_HIGH_INTENT = 'high_intent';

    public const SEG_PRICE_SENSITIVE = 'price_sensitive';

    public const SEG_UNDECIDED = 'undecided';

    public const SEG_PAYMENT_LINK_PENDING = 'payment_link_pending';

    public const SEG_PAYMENT_DECLINED = 'payment_declined';

    public const SEG_NEW_MEMBER = 'new_member';

    public const SEG_HIGH_ADHERENCE = 'high_adherence';

    public const SEG_LOW_ADHERENCE = 'low_adherence';

    public const SEG_EXPIRING_SOON = 'expiring_soon';

    public const SEG_EXPIRED = 'expired';

    public const SEG_INACTIVE = 'inactive';

    public const SEG_AT_RISK = 'at_risk';

    public const SEG_UPGRADE_OPPORTUNITY = 'upgrade_opportunity';

    public const SEG_CROSS_SELL_OPPORTUNITY = 'cross_sell_opportunity';

    public const SEG_REFERRAL_CANDIDATE = 'referral_candidate';

    public const SEG_NEEDS_HUMAN = 'needs_human';

    public const SEGMENTS = [
        self::SEG_NEW_PROSPECT, self::SEG_QUALIFIED_PROSPECT, self::SEG_HIGH_INTENT,
        self::SEG_PRICE_SENSITIVE, self::SEG_UNDECIDED, self::SEG_PAYMENT_LINK_PENDING,
        self::SEG_PAYMENT_DECLINED, self::SEG_NEW_MEMBER, self::SEG_HIGH_ADHERENCE,
        self::SEG_LOW_ADHERENCE, self::SEG_EXPIRING_SOON, self::SEG_EXPIRED,
        self::SEG_INACTIVE, self::SEG_AT_RISK, self::SEG_UPGRADE_OPPORTUNITY,
        self::SEG_CROSS_SELL_OPPORTUNITY, self::SEG_REFERRAL_CANDIDATE, self::SEG_NEEDS_HUMAN,
    ];

    // ── Eventos ──────────────────────────────────────────────────────────────

    public const EV_LEAD_CREATED = 'lead_created';

    public const EV_LEAD_QUALIFIED = 'lead_qualified';

    public const EV_OFFER_PRESENTED = 'offer_presented';

    public const EV_PAYMENT_LINK_CREATED = 'payment_link_created';

    public const EV_PAYMENT_APPROVED = 'payment_approved';

    public const EV_PAYMENT_FAILED = 'payment_failed';

    public const EV_MEMBERSHIP_ACTIVATED = 'membership_activated';

    public const EV_FIRST_CHECKIN = 'first_checkin';

    public const EV_ATTENDANCE_MILESTONE = 'attendance_milestone';

    public const EV_INACTIVITY_DETECTED = 'inactivity_detected';

    public const EV_RENEWAL_WINDOW_OPENED = 'renewal_window_opened';

    public const EV_MEMBERSHIP_EXPIRED = 'membership_expired';

    public const EV_APPOINTMENT_CREATED = 'appointment_created';

    public const EV_APPOINTMENT_COMPLETED = 'appointment_completed';

    public const EV_INVOICE_REQUESTED = 'invoice_requested';

    public const EV_APP_LINKED = 'app_linked';

    public const EV_OBJECTION_RAISED = 'objection_raised';

    public const EV_COMPLAINT_CREATED = 'complaint_created';

    public const EV_OFFER_REJECTED = 'offer_rejected';

    public const EVENTS = [
        self::EV_LEAD_CREATED, self::EV_LEAD_QUALIFIED, self::EV_OFFER_PRESENTED,
        self::EV_PAYMENT_LINK_CREATED, self::EV_PAYMENT_APPROVED, self::EV_PAYMENT_FAILED,
        self::EV_MEMBERSHIP_ACTIVATED, self::EV_FIRST_CHECKIN, self::EV_ATTENDANCE_MILESTONE,
        self::EV_INACTIVITY_DETECTED, self::EV_RENEWAL_WINDOW_OPENED, self::EV_MEMBERSHIP_EXPIRED,
        self::EV_APPOINTMENT_CREATED, self::EV_APPOINTMENT_COMPLETED, self::EV_INVOICE_REQUESTED,
        self::EV_APP_LINKED, self::EV_OBJECTION_RAISED, self::EV_COMPLAINT_CREATED,
        self::EV_OFFER_REJECTED,
    ];

    // ── Estados de la oportunidad ────────────────────────────────────────────

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_WON = 'won';

    public const STATUS_LOST = 'lost';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    /** Bloqueada por una condición externa (opt-out, revisión humana). */
    public const STATUS_BLOCKED = 'blocked';

    public const OPEN_STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS];

    /**
     * Prioridad base por objetivo, de 1 a 100.
     *
     * El dinero ya comprometido manda: recuperar un pago a medias vale más que
     * empezar una conversación nueva, porque ahí ya hubo intención explícita.
     */
    public const GOAL_PRIORITY = [
        self::GOAL_ESCALATE => 95,
        self::GOAL_RECOVER_PAYMENT_LINK => 90,
        self::GOAL_COLLECT_PAYMENT => 85,
        self::GOAL_ACTIVATE_MEMBERSHIP => 80,
        self::GOAL_RECOVER_SATISFACTION => 78,
        self::GOAL_RENEW => 75,
        self::GOAL_CLOSE_PLAN => 70,
        self::GOAL_RESOLVE_OBJECTION => 65,
        self::GOAL_REACTIVATE => 60,
        self::GOAL_COMPLETE_ONBOARDING => 55,
        self::GOAL_INCREASE_ADHERENCE => 50,
        self::GOAL_BOOK_ASSESSMENT => 48,
        self::GOAL_BOOK_VISIT => 45,
        self::GOAL_UPGRADE => 40,
        self::GOAL_LINK_APP => 35,
        self::GOAL_CROSS_SELL => 30,
        self::GOAL_COLLECT_DATA => 25,
        self::GOAL_REQUEST_REFERRAL => 20,
    ];

    public static function priorityFor(string $goal): int
    {
        return self::GOAL_PRIORITY[$goal] ?? 50;
    }
}
