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
        SalesIntents::TOOL_APP_LINKS_SEND,
        SalesIntents::TOOL_SCHEDULE_FOLLOWUP,
        SalesIntents::TOOL_STAFF_REVIEW,
        SalesIntents::TOOL_MARK_DNC,
        'reply',
    ];

    /**
     * De las permitidas, las que SOLO ejecuta ULTRON.
     *
     * `ALLOWED_TOOLS` es el vocabulario del VALIDADOR, y lo reutilizan los dos
     * flujos: el legado (Hermes/OpenAI) y ULTRON. Pero ejecutarlas no las
     * ejecutan los dos. `app_links_send` entró aquí con el motor de la app y,
     * de paso, se coló en el prompt del flujo legado —{@see SalesAgentPromptBuilder}
     * lista esta constante—, donde `SalesAgentOrchestratorService::execute()`
     * la manda a `unknown_tool`: el modelo la pedía y no pasaba nada.
     *
     * Un prompt no anuncia herramientas que su flujo no ejecuta. Quitarlas del
     * VALIDADOR, en cambio, rompería ULTRON, que lo reutiliza tal cual: por eso
     * son dos listas y no una.
     *
     * `payment_link_send` NO está aquí: el flujo legado sí la ejecuta
     * (`SalesAgentOrchestratorService::execPaymentLink()`).
     */
    public const ULTRON_ONLY_TOOLS = [
        SalesIntents::TOOL_APP_LINKS_SEND,
    ];

    /**
     * Las que puede nombrar el prompt del flujo legado: las que ese flujo ejecuta.
     *
     * @return string[]
     */
    public static function legacyPromptTools(): array
    {
        return array_values(array_diff(self::ALLOWED_TOOLS, self::ULTRON_ONLY_TOOLS));
    }

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

    /**
     * Cualquier cosa que se parezca a un precio: `$120`, «cop», «pesos», el
     * patrón de miles `120.000` o un número de cuatro cifras o más.
     *
     * Estaba escrito a mano dentro de {@see SalesAgentDecisionValidator}. Vive
     * aquí porque ya hay un segundo sitio que necesita la misma pregunta —el
     * guard de salida— y dos copias de una expresión regular son dos reglas que
     * el día que cambie una se separan sin que nadie lo note.
     */
    public const PRICE_PATTERN = '/(\$\s?\d)|(\bcop\b)|(\bpesos\b)|(\d{1,3}[.,]\d{3})|(\d{4,})/i';

    /** Minúsculas y sin tildes, para comparar señales contra texto real. */
    public static function normalize(string $s): string
    {
        $lower = mb_strtolower($s);

        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /** ¿El texto contiene algo que un cliente leería como un precio? */
    public static function containsPrice(string $text): bool
    {
        return preg_match(self::PRICE_PATTERN, $text) === 1;
    }

    /**
     * Primera señal de acción PROHIBIDA presente en el texto, o null.
     *
     * Devuelve la señal y no un booleano porque quien bloquea tiene que poder
     * decir cuál fue: «bloqueado» sin el motivo obliga a reproducir el caso
     * para entenderlo.
     */
    public static function forbiddenSignalIn(string $text): ?string
    {
        return self::firstSignalIn($text, self::FORBIDDEN_SIGNALS);
    }

    /** Primera promesa/diagnóstico prohibido presente en el texto, o null. */
    public static function unsafeSignalIn(string $text): ?string
    {
        return self::firstSignalIn($text, self::UNSAFE_REPLY_SIGNALS);
    }

    /**
     * @param  string[]  $signals
     */
    private static function firstSignalIn(string $text, array $signals): ?string
    {
        $needle = self::normalize($text);

        foreach ($signals as $signal) {
            if (str_contains($needle, self::normalize($signal))) {
                return $signal;
            }
        }

        return null;
    }

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
