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
                'Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a '
                .'aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?',

            SalesIntents::TIME_OBJECTION =>
                'Te entiendo. A veces la clave es armar algo realista, no perfecto. '
                .'¿Cuántos días a la semana crees que podrías ir sin complicarte?',

            SalesIntents::DELAY_OBJECTION =>
                'Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo '
                .'bien. ¿Te queda alguna duda que pueda resolverte?',

            SalesIntents::BEGINNER_FEAR =>
                'Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el '
                .'ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?',

            SalesIntents::INSECURITY_BODY =>
                'Te entiendo, y no tienes por qué sentirte juzgado por eso. Mucha gente empieza '
                .'justo desde ahí, paso a paso. ¿Te preocupa más el ambiente o no saber cómo empezar?',

            default => null,
        };
    }
}
