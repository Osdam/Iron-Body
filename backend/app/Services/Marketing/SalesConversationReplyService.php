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
    public function __construct(
        private readonly SalesObjectionResponderService $objections = new SalesObjectionResponderService(),
    ) {
    }

    /**
     * Respuesta sugerida para una intención. null = no responder. Tono de ASESOR
     * (no vendedor agresivo): empatía + valor + UNA pregunta suave al final.
     * Mensajes cortos tipo WhatsApp. Primero diagnostica el objetivo; cierra solo
     * cuando la intención es alta.
     */
    public function replyFor(string $intent, array $context = []): ?string
    {
        // Objeciones: delegadas al manejador dedicado (empatía + beneficio + pregunta).
        if ($this->objections->isObjection($intent)) {
            return $this->objections->reply($intent);
        }

        return match ($intent) {
            SalesIntents::PRICING_QUESTION =>
                'No te paso un plan cualquiera. Para recomendarte bien, dime: '
                .'¿quieres bajar grasa, ganar masa muscular, mejorar condición o volver a entrenar?',

            SalesIntents::PAYMENT_LINK_REQUEST =>
                'Claro, te envío el link seguro por acá. Apenas Wompi confirme el pago, '
                .'tu membresía queda registrada en el sistema.',

            SalesIntents::GOAL_FAT_LOSS =>
                '¡Buenísimo objetivo! Para bajar grasa lo que mejor funciona es entrenamiento con '
                .'acompañamiento y constancia, y acá te guiamos en eso. '
                .'¿Hoy entrenas algo o estarías empezando desde cero?',

            SalesIntents::GOAL_MUSCLE_GAIN =>
                '¡Me gusta! Para ganar masa muscular es clave la técnica y un plan progresivo, y acá '
                .'te acompañamos en cada fase. ¿Ya has entrenado antes o vas empezando?',

            SalesIntents::HIGH_INTENT_CLOSE =>
                'Perfecto. Te puedo dejar el link seguro de pago y apenas se confirme, '
                .'tu acceso queda listo.',

            SalesIntents::LOCATION_QUESTION =>
                'Estamos en Iron Body Neiva. Te oriento con la ubicación para que te quede fácil '
                .'llegar. ¿Vas a ir por primera vez?',

            SalesIntents::SCHEDULE_QUESTION =>
                'Con gusto te cuento los horarios de Iron Body Neiva. ¿Buscas entrenar en la '
                .'mañana, tarde o noche? Así te oriento mejor.',

            SalesIntents::GENERAL_INFO =>
                '¡Con gusto te cuento! En Iron Body Neiva te damos acompañamiento para que entrenes '
                .'seguro y con resultados. Para orientarte mejor, ¿cuál es tu objetivo principal?',

            SalesIntents::HUMAN_REQUEST =>
                '¡Claro! Le paso tu mensaje a una persona del equipo para que te atienda directamente. '
                .'En un momento te escriben por acá.',

            SalesIntents::COMPLAINT =>
                'Lamento que hayas tenido una mala experiencia. Para resolverlo bien, una persona del '
                .'equipo va a revisar tu caso y te contacta en breve.',

            SalesIntents::MEDICAL_RISK_ESCALATION =>
                'Prefiero que esto lo revise una persona del equipo para orientarte bien y no '
                .'darte una respuesta irresponsable.',

            SalesIntents::FRAUD_OR_PAYMENT_CLAIM =>
                'Para revisar tu caso con cuidado, lo va a atender una persona del equipo. '
                .'Tu membresía solo se activa cuando el pago queda confirmado en el sistema.',

            SalesIntents::DO_NOT_CONTACT_REQUEST =>
                null, // respetamos: no insistimos.

            SalesIntents::SPAM_LOW_QUALITY =>
                null, // no enganchamos con mensajes sin contenido.

            default =>
                '¡Hola! 💪 Soy del equipo de Iron Body Neiva. Cuéntame qué buscas y te ayudo: '
                .'¿planes, horarios o ubicación?',
        };
    }

    /**
     * Mensaje para cuando hay intención de pago pero NO se puede entregar un link
     * productivo (Wompi sin producción): un asesor comparte el medio de pago.
     * NUNCA se entrega un link de sandbox como si fuera real.
     */
    public function paymentPendingReply(): string
    {
        return 'Por ahora un asesor te comparte el medio de pago para hacerlo correctamente.';
    }

    /**
     * Respuesta DETERMINISTA de precio. Si hay un plan (identificado o el mensual/
     * ancla), incluye nombre + precio REAL (Plan::price, COP) + beneficios + UNA
     * pregunta de cierre. El cierre solo OFRECE link si Wompi es productivo
     * ($canLink); si no, invita a conocer otros planes (sin mencionar link). Si NO
     * hay plan activo, NO inventa precio: pide objetivo/plan.
     */
    public function pricingReply(?Plan $plan, bool $canLink = false): string
    {
        if ($plan === null) {
            return (string) $this->replyFor(SalesIntents::PRICING_QUESTION);
        }

        $price    = $this->formatCop((float) $plan->price);
        $benefits = $plan->benefitsArray();
        $incl     = '';
        if (! empty($benefits)) {
            $incl = ' e incluye '.$this->joinSpanish(array_slice($benefits, 0, 3));
        }

        $closing = $canLink
            ? ' ¿Quieres que te envíe el link seguro de pago?'
            : ' ¿Quieres que te explique los otros planes también?';

        return "El {$plan->name} está en {$price}{$incl}.{$closing}";
    }

    /** Une elementos con coma y "y" final al estilo español: "a, b, y c". */
    private function joinSpanish(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), fn ($s) => $s !== ''));
        $n = count($items);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $items[0];
        }
        if ($n === 2) {
            return $items[0].' y '.$items[1];
        }
        $last = array_pop($items);
        return implode(', ', $items).', y '.$last;
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
