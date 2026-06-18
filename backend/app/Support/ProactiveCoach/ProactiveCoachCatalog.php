<?php

namespace App\Support\ProactiveCoach;

/**
 * Catálogo premium del Iron Body Proactive Coach.
 *
 * Fuente ÚNICA de los mensajes (tono coach), rutas, prioridad, intensidad y
 * cadencia de cada evento proactivo nuevo. Laravel construye aquí el bloque
 * `notification` personalizado y lo envía a n8n dentro del payload; n8n lo
 * prefiere sobre su catálogo estático de respaldo.
 *
 * Reglas de copy: humano, premium, accionable, en primera persona del coach
 * cuando aplica, sin alarmismo, sin emojis, corto pero potente. `{name}` se
 * reemplaza por el primer nombre del miembro (o se omite con elegancia).
 *
 * intensity: 'strong' (urgencia positiva, cuenta para el presupuesto fuerte)
 *            'soft'   (acompañamiento/invitación).
 * cadence:   'daily' | 'weekly' (define el sufijo de la idempotency_key).
 */
final class ProactiveCoachCatalog
{
    /**
     * @var array<string, array{
     *   type:string, route:string, priority:string, intensity:string,
     *   cadence:string, variants:array<int,array{title:string, body:string}>
     * }>
     */
    public const EVENTS = [

        // ── Entrenamiento ────────────────────────────────────────────────────
        'workout.not_started_today' => [
            'type' => 'workout', 'route' => '/iron-ai?focus=workout',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'daily',
            'variants' => [
                ['title' => 'Tu entrenamiento te está esperando', 'body' => 'Aún estás a tiempo, {name}. Ven, te ayudo a empezar la rutina de hoy sin complicarte.'],
                ['title' => 'Hoy todavía puedes sumar', 'body' => 'No necesitas hacerlo perfecto. Empieza tu entrenamiento y mantén el avance.'],
                ['title' => 'Entrenemos con intención', 'body' => 'Tu rutina de hoy está lista. Ven, te acompaño paso a paso.'],
            ],
        ],

        // ── Racha ────────────────────────────────────────────────────────────
        'streak.at_risk' => [
            'type' => 'streak', 'route' => '/iron-ai?focus=streak',
            'priority' => 'high', 'intensity' => 'strong', 'cadence' => 'daily',
            'variants' => [
                ['title' => 'Tu racha está en riesgo', 'body' => 'Aún puedes protegerla hoy, {name}. Ven, hagamos una acción rápida para mantener el impulso.'],
                ['title' => 'No dejemos caer la constancia', 'body' => 'Tu racha todavía se puede salvar. Te ayudo a cerrar el día con una victoria.'],
                ['title' => 'Hoy cuenta', 'body' => 'Una acción pequeña puede mantener tu racha viva. Ven, te muestro qué hacer.'],
            ],
        ],
        'streak.not_started' => [
            'type' => 'streak', 'route' => '/iron-ai?focus=streak',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Empieza tu primera racha', 'body' => 'Hoy puede ser tu primer día de constancia, {name}. Ven, te ayudo a comenzar con una acción simple.'],
                ['title' => 'Construyamos tu ritmo', 'body' => 'Tu progreso empieza con una primera victoria. Hagámosla hoy.'],
            ],
        ],

        // ── Cumplimiento diario ──────────────────────────────────────────────
        'daily.compliance_missing' => [
            'type' => 'today', 'route' => '/iron-ai?focus=today',
            'priority' => 'normal', 'intensity' => 'strong', 'cadence' => 'daily',
            'variants' => [
                ['title' => 'Aún puedes cumplir hoy', 'body' => 'Te falta una acción clave para cerrar el día mejor. Ven, te ayudo a elegir la más importante.'],
                ['title' => 'Todavía hay tiempo', 'body' => 'No necesitas hacerlo todo. Hagamos una acción inteligente para mantener tu proceso activo.'],
                ['title' => 'Cierra el día con una victoria', 'body' => 'Ven, revisemos qué puedes completar hoy en pocos minutos.'],
            ],
        ],

        // ── Nudge contextual flexible ────────────────────────────────────────
        'coach.nudge' => [
            'type' => 'today', 'route' => '/iron-ai?focus=today',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'daily',
            'variants' => [
                ['title' => 'Tengo una recomendación para ti', 'body' => 'Vi tu actividad reciente, {name}. Ven, revisemos juntos qué acción te conviene hacer ahora.'],
                ['title' => 'Hagamos que hoy cuente', 'body' => 'Te puedo ayudar a elegir entre entrenar, registrar nutrición o revisar tu progreso.'],
                ['title' => 'Tu coach está listo', 'body' => 'Ven, te ayudo a ordenar el día y avanzar con intención.'],
            ],
        ],

        // ── Invitaciones a IA ────────────────────────────────────────────────
        'iron_ai.chat_invite' => [
            'type' => 'ai', 'route' => '/iron-ai?focus=chat',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Tu Coach IA puede ayudarte', 'body' => 'Pregúntame qué entrenar, cómo comer mejor o cómo avanzar esta semana. Ven, probémoslo juntos.'],
                ['title' => 'Hay una forma más fácil de avanzar', 'body' => 'Puedo ayudarte a resolver dudas de entrenamiento, nutrición y progreso en segundos.'],
                ['title' => 'Descubre tu Coach IA', 'body' => 'Ven, conversemos sobre tu objetivo y te ayudo a tomar una mejor decisión hoy.'],
            ],
        ],
        'iron_ai.nutrition_invite' => [
            'type' => 'ai', 'route' => '/iron-ai?focus=nutrition',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Puedo revisar tu nutrición contigo', 'body' => 'Ven, analicemos tus comidas y descubramos qué puedes mejorar sin complicarte.'],
                ['title' => 'Tu alimentación puede ser más clara', 'body' => 'Te ayudo a entender si estás comiendo acorde a tu objetivo.'],
            ],
        ],
        'iron_ai.progress_invite' => [
            'type' => 'ai', 'route' => '/iron-ai?focus=progress',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Revisemos tu progreso', 'body' => 'Ven, miremos juntos qué está funcionando y qué podríamos ajustar para avanzar mejor.'],
                ['title' => 'Tu evolución merece una lectura', 'body' => 'Puedo ayudarte a entender tus cambios y convertirlos en una siguiente acción.'],
            ],
        ],
        'iron_ai.streak_invite' => [
            'type' => 'ai', 'route' => '/iron-ai?focus=streak',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Construyamos constancia', 'body' => 'Tu racha puede ayudarte a mantener disciplina. Ven, te explico cómo empezar.'],
            ],
        ],

        // ── Descubrimiento de módulos (PREPARADO, INACTIVO) ──────────────────
        'module.discovery' => [
            'type' => 'discover', 'route' => '/iron-ai?focus=discover',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Hay algo nuevo para ti', 'body' => 'Puedo ayudarte a sacarle más provecho a Iron Body. Ven, descubramos qué módulo te conviene usar hoy.'],
                ['title' => 'Tu app puede guiarte mejor', 'body' => 'Hay herramientas que quizás aún no has probado. Te llevo a la más útil para tu momento actual.'],
            ],
        ],

        // ── Reactivación ─────────────────────────────────────────────────────
        'coach.reactivation' => [
            'type' => 'reactivation', 'route' => '/iron-ai?focus=reactivation',
            'priority' => 'normal', 'intensity' => 'strong', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Retomemos sin presión', 'body' => 'No importa cuántos días pasaron, {name}. Ven, te ayudo a volver con una acción simple.'],
                ['title' => 'Tu proceso sigue aquí', 'body' => 'Podemos retomar desde donde estás. Hagamos hoy una pequeña victoria.'],
                ['title' => 'Volvamos al ritmo', 'body' => 'Te ayudo a reorganizar entrenamiento, nutrición y constancia para esta semana.'],
            ],
        ],

        // ── Plan semanal del coach ───────────────────────────────────────────
        'weekly.coach_plan' => [
            'type' => 'weekly', 'route' => '/iron-ai?focus=weekly-plan',
            'priority' => 'normal', 'intensity' => 'soft', 'cadence' => 'weekly',
            'variants' => [
                ['title' => 'Planifiquemos tu semana', 'body' => 'Ven, te ayudo a ordenar entrenamiento, nutrición y constancia para avanzar con claridad.'],
                ['title' => 'Tu semana puede empezar mejor', 'body' => 'Tengo una guía para que sepas qué priorizar desde hoy.'],
            ],
        ],
    ];

    /** ¿Es un evento de esta capa proactiva? */
    public static function isProactive(string $eventType): bool
    {
        return array_key_exists($eventType, self::EVENTS);
    }

    /** Intensidad ('strong'|'soft') o 'soft' por defecto. */
    public static function intensity(string $eventType): string
    {
        return self::EVENTS[$eventType]['intensity'] ?? 'soft';
    }

    /** Cadencia ('daily'|'weekly'). */
    public static function cadence(string $eventType): string
    {
        return self::EVENTS[$eventType]['cadence'] ?? 'daily';
    }

    /**
     * Construye el bloque `notification` personalizado para n8n/notify-member.
     * `$variantSeed` rota el copy (p. ej. día del año) para dar variedad.
     *
     * @return array{type:string,title:string,body:string,action_route:string,priority:string}|null
     */
    public static function buildNotification(string $eventType, ?string $fullName, int $variantSeed = 0): ?array
    {
        $def = self::EVENTS[$eventType] ?? null;
        if ($def === null || empty($def['variants'])) {
            return null;
        }
        $variants = $def['variants'];
        $variant = $variants[$variantSeed % count($variants)];

        $first = self::firstName($fullName);
        $title = self::applyName($variant['title'], $first);
        $body = self::applyName($variant['body'], $first);

        // `type` = event_type: convención ya probada en producción y usada por el
        // anti-spam por tipo de AppNotificationService (dedupe por tipo/día).
        return [
            'type' => $eventType,
            'title' => $title,
            'body' => $body,
            'action_route' => $def['route'],
            'priority' => $def['priority'],
        ];
    }

    private static function firstName(?string $fullName): ?string
    {
        $fullName = trim((string) $fullName);
        if ($fullName === '') {
            return null;
        }
        $first = explode(' ', $fullName)[0] ?? '';
        $first = trim($first);
        return $first !== '' ? mb_convert_case($first, MB_CASE_TITLE, 'UTF-8') : null;
    }

    /**
     * Reemplaza `{name}` con el nombre o, si no hay, elimina el placeholder con
     * elegancia (sin dejar comas/espacios colgando).
     */
    private static function applyName(string $text, ?string $name): string
    {
        if ($name !== null) {
            return str_replace('{name}', $name, $text);
        }
        // Sin nombre: quita ", {name}" / " {name}" / "{name}, " y limpia espacios.
        $text = preg_replace('/,?\s*\{name\}/u', '', $text);
        $text = preg_replace('/\{name\}\s*,?\s*/u', '', (string) $text);
        return trim(preg_replace('/\s{2,}/u', ' ', (string) $text));
    }
}
