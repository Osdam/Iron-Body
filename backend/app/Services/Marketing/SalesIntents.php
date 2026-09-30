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
    public const PRICING_QUESTION = 'pricing_question';

    public const PAYMENT_LINK_REQUEST = 'payment_link_request';

    public const PRICE_OBJECTION = 'price_objection';

    public const TIME_OBJECTION = 'time_objection';

    public const DELAY_OBJECTION = 'delay_objection';

    public const BEGINNER_FEAR = 'beginner_fear';

    public const INSECURITY_BODY = 'insecurity_body';

    public const LOCATION_QUESTION = 'location_question';

    public const SCHEDULE_QUESTION = 'schedule_question';

    public const GENERAL_INFO = 'general_info';

    public const GOAL_FAT_LOSS = 'goal_fat_loss';

    public const GOAL_MUSCLE_GAIN = 'goal_muscle_gain';

    public const GOAL_RECOMPOSITION = 'goal_recomposition';

    public const HIGH_INTENT_CLOSE = 'high_intent_close';

    public const GREETING = 'greeting';

    public const THANKS = 'thanks';

    public const GOODBYE = 'goodbye';

    public const NOT_INTERESTED = 'not_interested';

    public const BOT_QUESTION = 'bot_question';

    public const HUMAN_REQUEST = 'human_request';

    public const COMPLAINT = 'complaint';

    public const INVOICE_REQUEST = 'invoice_request';

    public const MEDICAL_RISK_ESCALATION = 'medical_risk_escalation';

    public const FRAUD_OR_PAYMENT_CLAIM = 'fraud_or_payment_claim';

    public const DO_NOT_CONTACT_REQUEST = 'do_not_contact_request';

    public const SPAM_LOW_QUALITY = 'spam_or_low_quality';

    public const UNKNOWN = 'unknown';

    // ── Temperaturas comerciales ─────────────────────────────────────────────
    public const TEMP_COLD = 'cold';

    public const TEMP_WARM = 'warm';

    public const TEMP_HOT = 'hot';

    public const TEMP_VERY_HOT = 'very_hot';

    public const TEMP_RISK = 'risk';

    // ── Etapas del embudo (internas, ricas) ──────────────────────────────────
    public const STAGE_DISCOVERY = 'discovery';

    public const STAGE_OBJECTION = 'objection';

    public const STAGE_CLOSING = 'closing';

    public const STAGE_RISK = 'risk';

    public const STAGE_OPT_OUT = 'opt_out';

    // ── Etapas del lead (taxonomía comercial del brief) ───────────────────────
    public const LEAD_STAGE_NEW = 'new';

    public const LEAD_STAGE_INFORMED = 'informed';

    public const LEAD_STAGE_INTERESTED = 'interested';

    public const LEAD_STAGE_READY_TO_PAY = 'ready_to_pay';

    public const LEAD_STAGE_NEEDS_HUMAN = 'needs_human';

    public const LEAD_STAGE_LOST = 'lost';

    // ── Acciones recomendadas ────────────────────────────────────────────────
    public const ACTION_REPLY = 'reply';

    public const ACTION_GENERATE_PAYMENT_LINK = 'generate_payment_link';

    public const ACTION_SCHEDULE_FOLLOWUP = 'schedule_followup';

    // staff_review = la IA responde Y deja una alerta interna; NO apaga la IA.
    public const ACTION_STAFF_REVIEW = 'staff_review';

    public const ACTION_ESCALATE_HUMAN = 'escalate_human'; // SOLO uso manual desde CRM.

    public const ACTION_NO_REPLY = 'no_reply';

    public const ACTION_BLOCKED_DNC = 'blocked_do_not_contact';

    public const ACTION_MARK_DNC = 'mark_do_not_contact';

    public const ACTION_REGISTER_OBJECTION = 'register_objection';

    // ── Herramientas que la decisión puede solicitar (auto_execute seguro) ────
    public const TOOL_PAYMENT_LINK_SEND = 'payment_link_send';

    /** Laravel envía los enlaces oficiales de la app en un mensaje propio. Sin dinero en juego: siempre disponible. */
    public const TOOL_APP_LINKS_SEND = 'app_links_send';

    /**
     * Dejar REGISTRADA una solicitud de día de cortesía.
     *
     * Registrar no es agendar. Lo que esta herramienta escribe es una petición
     * que una persona de Iron Body confirma a mano, y por eso el nombre dice
     * `request`: el día que se llame `courtesy_book` alguien escribirá «quedaste
     * agendado» y será mentira.
     */
    public const TOOL_COURTESY_REQUEST = 'courtesy_request';

    public const TOOL_SCHEDULE_FOLLOWUP = 'schedule_followup';

    // staff_review crea una alerta interna SIN tocar ai_enabled/human_takeover.
    public const TOOL_STAFF_REVIEW = 'staff_review';

    public const TOOL_HUMAN_TAKEOVER = 'human_takeover'; // SOLO uso manual desde CRM.

    public const TOOL_MARK_DNC = 'mark_do_not_contact';

    /**
     * Intenciones SENSIBLES que dejan una marca de revisión para el equipo
     * (needs_staff_review). NUNCA apagan la IA: el bot sigue respondiendo seguro.
     */
    public const STAFF_REVIEW_INTENTS = [
        self::MEDICAL_RISK_ESCALATION,
        self::FRAUD_OR_PAYMENT_CLAIM,
        self::HUMAN_REQUEST,
        self::COMPLAINT,
        self::INVOICE_REQUEST,
    ];

    /** Alias retrocompatible (mismas intenciones; semántica = staff review). */
    public const ESCALATION_INTENTS = self::STAFF_REVIEW_INTENTS;

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
        self::GOAL_RECOMPOSITION,
    ];

    /** Intenciones que solicitan un link de pago. */
    public const PAYMENT_INTENTS = [
        self::PAYMENT_LINK_REQUEST,
        self::HIGH_INTENT_CLOSE,
    ];

    /**
     * Una PREGUNTA DE MEMORIA SOBRE LA VISITA: la persona pide que le
     * recordemos un compromiso que YA pidió («me acuerdas a qué hora agendé»,
     * «a qué hora era mi visita», «¿para qué día quedó la cita?»). Se
     * resuelve, se confirma el compromiso y se espera: no es un turno de
     * venta. Nació de una prueba física en la que a «me acuerdas mañana a qué
     * horas agendé» se le contestó con la hora y con el plan Élite.
     *
     * Es deliberadamente ESTRECHA. Un falso negativo deja el turno normal, que
     * ya lleva los compromisos en el contexto; un falso positivo prohíbe
     * vender y sustituye respuestas legítimas. Por eso pide recordar, o
     * preguntar por el cuándo de algo PASADO, o «mi visita» con la pregunta por
     * el cuándo y sin proponer nada. Y quedan fuera, y se contestan como lo que
     * son:
     *   · cambiar o cancelar («cámbiala», «ya no voy a poder ir», «¿la
     *     dejamos para mañana?», «me enfermé», «mejor olvídalo»): eso es la
     *     herramienta de cortesía;
     *   · pedir o proponer la visita («¿cuándo puedo hacer mi cortesía?», «¿a
     *     qué hora sería mi visita si voy el sábado?», cualquier hora dada);
     *   · opinar de la visita hecha, o preguntar otra cosa de ella (si es
     *     gratis, en qué sede, qué llevar);
     *   · otra cita (valoración, llamada), otra persona, la app, el pago, el
     *     precio o los planes.
     */
    public static function isMemoryQuestion(string $texto): bool
    {
        $t = SalesAgentDecisionSchema::normalize($texto);

        if (preg_match(
            '/\b(cancel\w*|cambi\w*|anul\w*|borr(a|ar)la\b|mover\w*|muev\w*|pas(ar|arla|ala|a)\s+(para|al|a)|reprogram\w*|reagend\w*|aplaz\w*|posterg\w*|corre(r|rla|la)\b'
            .'|no (voy|vamos) a (poder )?(ir|asistir|llegar)|no (puedo|podre|podemos|podria) (ir|asistir|llegar)|ya no voy|no voy a ir|no creo que pueda'
            // Lo que también pide mover o cancelar sin decirlo: «¿la dejamos para mañana?», «¿se puede el viernes?», «me enfermé».
            .'|dej(ar|arla|ala|amos|emos|arlo)\s+para|se puede (el|para|mas|otro)|otro dia|no (puedo|podre|podemos|alcanzo|alcanzare)\b|no (voy|vamos) a poder\b'
            .'|me enferme|se me complico|(un|algun) (inconveniente|imprevisto)|olvidalo'
            .'|quiero (ir|agendar|reservar|separar|pedir|hacer)|quisiera (ir|agendar|reservar)|puedo (ir|hacer|tomar|usar|reclamar|pedir)|podria (ir|quedar|ser|hacer)|me gustaria ir|me dan|reclamar|si voy|agendar|reservar|separar'
            .'|clases?\b|con (el|la|mi) (entrenador|entrenadora|profe|coach|nutricionista|fisio\w*)|valoracion|evaluacion|llamada|asesor\w*|inbody|nutricion\w*|inscripcion|medic\w*'
            .'|fue (muy |super |re )?(buena|bueno|excelente|genial|chevere|bacan\w*|increible|linda)|estuvo|me gusto|me encanto|gratis|sede|(que|q) (llevo|debo llevar|tengo que llevar)'
            .'|app|aplicacion|link|enlace|pag(ar|o|ue)|precio|planes?\b|inscrib\w*|matricul\w*'
            .'|mi (hijo|hija|esposo|esposa|novio|novia|mama|papa|hermano|hermana|amigo|amiga|pareja|mujer|marido))\b/u',
            $t,
        ) === 1) {
            return false;
        }
        // Quien da una hora propone, no pregunta: «¿mi visita puede ser a las 10?».
        if (preg_match('/\ba\s+las?\s+\d{1,2}\b|\b\d{1,2}(:\d{2})?\s*(am|pm|a\.?\s?m|p\.?\s?m)\b|\b\d{1,2}\s+de\s+la\s+(manana|tarde|noche)\b/u', $t) === 1) {
            return false;
        }

        $objeto = preg_match('/\b(visita|cita|cortesia)\b/u', $t) === 1;
        $agendePropio = preg_match('/\b(agende|agendamos|agendado|agendada|reserve|reservamos|reservado|reservada|quede|quedamos|habiamos quedado|pedi|tenia que (ir|venir|llegar))\b/u', $t) === 1;
        $recordar = preg_match('/\b(me acuerdas|me recuerdas|recuerdame|recuerdas|me (podrias|puedes) recordar|recordarme|me confirmas|me repites|me dices otra vez|se me (borro|olvido|perdio|elimino|fue)|que (habiamos|habia|hemos|habias) (quedado|hablado|dicho|acordado)|en que (habiamos )?quedamos|como quedamos)\b/u', $t) === 1;
        $pasado = preg_match('/\b(era|quedo|quede|quedamos|habiamos quedado|agende|agendamos|agendado|agendada|reserve|reservamos|pedi|me dijiste|me dijeron|tenia)\b/u', $t) === 1;
        $pregunta = preg_match('/\b(a (que|q) horas?|(que|q) horas?|(que|q) dia|cuando|para (que|q) (dia|hora)|a las cuantas)\b/u', $t) === 1;
        $mia = preg_match('/\b(mi|tu) (visita|cita|cortesia)\b|\btengo (la|mi) (visita|cita)\b/u', $t) === 1;
        $esHoy = preg_match('/\b(es|era)\s+(hoy|manana)\b/u', $t) === 1;
        $propone = preg_match('/\b(seria|serian|podria|podriamos|puede ser)\b/u', $t) === 1;

        return ($recordar && ($objeto || ($pasado && $pregunta)))
            || ($pregunta && $pasado && ($objeto || $agendePropio))
            || ($mia && ($pregunta || $esHoy) && ! $propone);
    }

    /**
     * Un saludo y NADA MÁS: «hola», «buenas», «hola buenos días», «qué tal»,
     * con nombre o emoji si acaso. En cuanto trae otra cosa («hola, quiero
     * información», «buenas, cuánto vale») deja de ser un saludo puro y la
     * intención la pone lo demás.
     *
     * Existe porque un «hola» a secas se decidía con la memoria comercial del
     * lead (READY_TO_BUY, hot lead) y el turno salía vendiendo o preguntando
     * el objetivo; el saludo puro tiene prioridad conversacional y entra en
     * modo recepción.
     */
    public static function isPureGreeting(string $texto): bool
    {
        $t = SalesAgentDecisionSchema::normalize($texto);
        // Fuera emojis, signos y muletillas de apertura: queda lo que se dijo.
        $t = trim((string) preg_replace('/[^a-z0-9\s]/u', ' ', $t));
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        if ($t === '' || mb_strlen($t) > 60) {
            return false;
        }
        $saludo = '(hola|holi|holaa+|buenas|buenos dias|buen dia|buenas tardes|buenas noches|que tal|que mas|hey|hello|hi|saludos|como estas|como esta|como estan|como va|todo bien)';
        $relleno = '(muy|de nuevo|otra vez|amigos|amigo|equipo|iron body|iron|body|gym|gimnasio)';

        // Al menos UN saludo de verdad: «gimnasio» o «de nuevo» a secas son
        // contenido (una respuesta a «¿planes, clases o cómo empezar?»), no un
        // saludo. La revisión lo midió entrando en recepción.
        return preg_match('/^('.$relleno.'\s*)*'.$saludo.'(\s*('.$saludo.'|'.$relleno.'))*$/u', $t) === 1;
    }
}
