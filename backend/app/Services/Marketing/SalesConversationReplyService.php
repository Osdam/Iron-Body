<?php

namespace App\Services\Marketing;

use App\Models\Plan;

/**
 * Construye la respuesta HUMANA sugerida para cada intención. Textos curados,
 * comerciales y SEGUROS: nunca incluyen precios inventados, ni prometen
 * resultados físicos garantizados, ni dan diagnósticos médicos. El precio real
 * (cuando se comparte) sale SIEMPRE del plan activo del backend.
 */
class SalesConversationReplyService
{
    /** Dirección OFICIAL de Iron Body Neiva (provista por el negocio). */
    public const ADDRESS = 'Cl. 24 Sur #33-53, Neiva, Huila';

    public function __construct(
        private readonly SalesObjectionResponderService $objections = new SalesObjectionResponderService(),
    ) {
    }

    /**
     * Respuesta sugerida (curada) para una intención. null = no responder.
     *
     * Tono de ASESOR HUMANO de Iron Body: cercano, tranquilo, breve y cálido.
     * Estructura: (1) reconoce lo que dijo, (2) ayuda/orienta concreto,
     * (3) MÁXIMO una pregunta pequeña. Nada de presión, urgencia, promesas de
     * resultados, ni listas robóticas de beneficios. Primero entiende y cuida;
     * vende solo cuando hay intención clara.
     */
    public function replyFor(string $intent, array $context = []): ?string
    {
        // Objeciones / pena / inseguridad: manejador dedicado (validar + cuidar).
        if ($this->objections->isObjection($intent)) {
            return $this->objections->reply($intent);
        }

        return match ($intent) {
            SalesIntents::GREETING =>
                'Hola, bienvenido a Iron Body. ¿Buscas información de planes, ubicación o quieres '
                .'empezar con algún objetivo?',

            SalesIntents::PRICING_QUESTION =>
                'Sí, claro. Para recomendarte mejor, ¿quieres empezar por bajar grasa, ganar masa '
                .'o simplemente coger hábito?',

            SalesIntents::PAYMENT_LINK_REQUEST, SalesIntents::HIGH_INTENT_CLOSE =>
                $this->paymentPendingReply(),

            SalesIntents::GOAL_FAT_LOSS =>
                'Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. '
                .'¿Ya vienes entrenando o arrancas desde cero?',

            SalesIntents::GOAL_MUSCLE_GAIN =>
                'Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. '
                .'¿Ya has entrenado antes o estás empezando?',

            SalesIntents::LOCATION_QUESTION =>
                'Estamos en '.self::ADDRESS.'. ¿Vas a ir por primera vez?',

            SalesIntents::SCHEDULE_QUESTION =>
                'No quiero darte un horario incorrecto. Te paso con alguien del equipo para '
                .'confirmarlo bien.',

            SalesIntents::GENERAL_INFO =>
                'Con gusto te cuento. El mensual te sirve para entrenar constante, y según tu '
                .'objetivo miramos lo que mejor te sirva. ¿Quieres entrenar por salud, bajar grasa '
                .'o ganar masa?',

            SalesIntents::THANKS =>
                'Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo '
                .'sin problema.',

            SalesIntents::NOT_INTERESTED =>
                'Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver '
                .'dudas, aquí te ayudamos.',

            SalesIntents::BOT_QUESTION =>
                'Soy el asistente de Iron Body y te puedo ayudar con información inicial. Si '
                .'prefieres, también te paso con una persona del equipo.',

            SalesIntents::HUMAN_REQUEST =>
                'Claro. Te paso con alguien del equipo para que te atienda directo.',

            SalesIntents::COMPLAINT =>
                'Lamento que hayas tenido una mala experiencia. Te paso con alguien del equipo para '
                .'que revise tu caso y te ayude bien.',

            SalesIntents::INVOICE_REQUEST =>
                'Claro. Para facturación te paso con alguien del equipo y así toman los datos '
                .'correctamente.',

            SalesIntents::MEDICAL_RISK_ESCALATION =>
                'Gracias por contarlo. En ese caso prefiero que lo revise alguien del equipo contigo, '
                .'para orientarte con cuidado y no recomendarte algo que te moleste más.',

            SalesIntents::FRAUD_OR_PAYMENT_CLAIM =>
                'Para revisar tu caso con cuidado, lo va a atender una persona del equipo. La '
                .'membresía solo queda activa cuando el pago está confirmado en el sistema.',

            SalesIntents::GOODBYE =>
                'De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y '
                .'te ayudo.',

            SalesIntents::DO_NOT_CONTACT_REQUEST =>
                null, // respetamos: no insistimos.

            SalesIntents::SPAM_LOW_QUALITY =>
                null, // no enganchamos con mensajes sin contenido.

            default =>
                'No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o '
                .'ubicación?',
        };
    }

    /**
     * Mensaje para cuando hay intención de pago: como Wompi aún no es productivo,
     * NO se envía link; un asesor comparte el medio de pago. Cierra con una sola
     * pregunta suave.
     */
    public function paymentPendingReply(): string
    {
        return 'Listo. Por ahora un asesor te comparte el medio de pago para hacerlo correctamente. '
            .'¿Es para el plan mensual?';
    }

    /**
     * Respuesta DETERMINISTA de precio: tono natural, precio REAL de la DB y UNA
     * pregunta para entender a la persona. NO lista beneficios robóticos, NO ofrece
     * link, NO empuja el pago. Si no hay plan activo, NO inventa precio: pregunta
     * el objetivo.
     */
    public function pricingReply(?Plan $plan): string
    {
        if ($plan === null) {
            return (string) $this->replyFor(SalesIntents::PRICING_QUESTION);
        }

        $price = $this->formatCop((float) $plan->price);

        return "Sí, claro. El {$plan->name} está en {$price}. "
            .'¿Ya has entrenado antes o vas arrancando desde cero?';
    }

    /**
     * Cierre suave de despedida: deja valor (precio + ubicación) y puerta abierta,
     * SIN acosar. Se envía como máximo una vez (lo controla el orquestador).
     */
    public function goodbyeReply(?Plan $plan): string
    {
        if ($plan !== null) {
            $price = $this->formatCop((float) $plan->price);

            return "De una, tranquilo. Te dejo el dato por si lo quieres mirar después: el "
                ."{$plan->name} está en {$price} y estamos en ".self::ADDRESS.'. Si más adelante '
                .'quieres empezar, me escribes y te ayudo.';
        }

        return (string) $this->replyFor(SalesIntents::GOODBYE);
    }

    /** Frases que OFRECEN un link de pago (prohibidas si Wompi no es productivo). */
    private const LINK_OFFER_PHRASES = [
        'link seguro', 'te envio el link', 'te envío el link', 'envie el link', 'envíe el link',
        'link de pago', 'pagar por aqui', 'pagar por aquí', 'pagar por link', 'te paso el link',
        'link para pagar', 'mando el link', 'mandar el link', 'el link', 'por link',
    ];

    /** ¿El texto OFRECE/menciona un link de pago? (para el guardrail Wompi). */
    public function offersLink(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = $this->normalize($text);
        foreach (self::LINK_OFFER_PHRASES as $p) {
            if (str_contains($needle, $this->normalize($p))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Elimina del texto cualquier oración que ofrezca un link de pago (cuando
     * Wompi no es productivo). Garantiza que quede al menos una pregunta de cierre
     * sin mencionar links.
     */
    public function scrubLinkOffer(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = array_values(array_filter(
            $sentences,
            fn ($s) => trim($s) !== '' && ! $this->offersLink($s),
        ));

        $out = trim(implode(' ', $kept));
        if ($out === '') {
            $out = 'Con gusto te oriento con lo que necesites.';
        }
        if (! str_contains($out, '?')) {
            $out .= ' ¿Quieres que te explique los planes?';
        }
        return $out;
    }

    /** Términos que delatan un CTA/empuje de pago (para intenciones que no deben pagar aún). */
    private const PAYMENT_CTA_TERMS = ['pago', 'pagar', 'link', 'medio de pago', 'proceso de compra'];

    /** ¿El texto empuja el pago (CTA de pago)? */
    public function mentionsPaymentCta(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }
        $needle = $this->normalize($text);
        foreach (self::PAYMENT_CTA_TERMS as $t) {
            if (str_contains($needle, $this->normalize($t))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Elimina del texto cualquier oración que empuje el pago y garantiza un cierre
     * suave (sin pago). Usado para intenciones que NO deben llevar a pago todavía
     * (ubicación, miedo de principiante, objeción de precio).
     */
    public function scrubPaymentCta(?string $text, string $softClosing): ?string
    {
        if ($text === null) {
            return null;
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($text)) ?: [];
        $kept = array_values(array_filter(
            $sentences,
            fn ($s) => trim($s) !== '' && ! $this->mentionsPaymentCta($s),
        ));

        $out = trim(implode(' ', $kept));
        if ($out === '') {
            $out = 'Con gusto te oriento.';
        }
        if (! str_contains($out, '?')) {
            $out .= ' '.$softClosing;
        }
        return $out;
    }

    private function normalize(string $s): string
    {
        $lower = mb_strtolower(trim($s));
        return strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    }

    /** Formato de precio en COP sin decimales: 80000 → "$80.000 COP". */
    public function formatCop(float $amount): string
    {
        return '$'.number_format($amount, 0, ',', '.').' COP';
    }

    /** Mensaje neutro de espera cuando se fuerza un escalado a humano. */
    public function escalationReply(): string
    {
        return 'Para ayudarte bien con esto, te va a atender una persona del equipo en un momento. '
            .'Gracias por tu paciencia.';
    }

    /**
     * Mensaje humano corto que acompaña un link de pago. El precio es el REAL
     * del plan activo (nunca inventado).
     */
    public function paymentLinkMessage(Plan $plan, float $amount, string $url): string
    {
        $price = '$'.number_format($amount, 0, ',', '.').' COP';

        return "¡Hola! 💪 Aquí tienes tu link para activar tu membresía {$plan->name} ({$price}) "
            .'en Iron Body Neiva. Pagas seguro desde acá y tu acceso queda listo al confirmarse '
            ."el pago: {$url}";
    }
}
