<?php

namespace App\Services\Marketing;

/**
 * Manejo de OBJECIONES comerciales con tono de asesor (no agresivo). Cada
 * respuesta sigue el patrón EMPATÍA + BENEFICIO + UNA pregunta suave, en mensajes
 * cortos tipo WhatsApp. Cubre: precio, tiempo, "lo pienso / después voy", pena de
 * empezar y "nunca he entrenado". NUNCA inventa precios ni promete resultados.
 */
class SalesObjectionResponderService
{
    /** ¿La intención es una objeción comercial? */
    public function isObjection(string $intent): bool
    {
        return in_array($intent, SalesIntents::OBJECTION_INTENTS, true);
    }

    /**
     * Respuesta empática para la objeción. null si la intención no es una objeción
     * que este servicio maneje.
     */
    public function reply(string $intent): ?string
    {
        return match ($intent) {
            SalesIntents::PRICE_OBJECTION =>
                'Te entiendo. Si estás comparando solo por precio puede parecer así, pero la idea '
                .'es que entrenes en un lugar serio y con estructura. ¿Tu idea es empezar este mes '
                .'o apenas estás mirando opciones?',

            SalesIntents::TIME_OBJECTION =>
                'Te entiendo, el tiempo es lo más valioso. Por eso acá las rutinas se ajustan a tu '
                .'agenda y con poquitas sesiones a la semana ya se notan cambios. '
                .'¿En qué horario se te haría más fácil entrenar?',

            SalesIntents::DELAY_OBJECTION =>
                'Tranquilo, sin presión. Solo te digo algo: el mejor momento para empezar suele ser '
                .'hoy, aunque sea con calma. ¿Quieres que te deje la info lista para cuando decidas?',

            SalesIntents::BEGINNER_FEAR =>
                'Tranquilo, a todos nos pasó la primera vez 💪. Acá te acompañamos desde cero, sin '
                .'pena y a tu ritmo; nadie nace sabiendo. ¿Te gustaría que te cuente cómo es el primer día?',

            default => null,
        };
    }
}
