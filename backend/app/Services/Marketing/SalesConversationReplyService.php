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
    /** Respuesta sugerida para una intención. null = no responder. */
    public function replyFor(string $intent, array $context = []): ?string
    {
        return match ($intent) {
            SalesIntents::PRICING_QUESTION =>
                'No te paso un plan cualquiera. Para recomendarte bien, dime: '
                .'¿quieres bajar grasa, ganar masa muscular, mejorar condición o volver a entrenar?',

            SalesIntents::PAYMENT_LINK_REQUEST =>
                'Claro, te envío el link seguro por acá. Apenas Wompi confirme el pago, '
                .'tu membresía queda registrada en el sistema.',

            SalesIntents::PRICE_OBJECTION =>
                'Te entiendo. Si estás comparando solo por precio puede parecer así, pero la idea '
                .'es que entrenes en un lugar serio y con estructura. ¿Tu idea es empezar este mes '
                .'o apenas estás mirando opciones?',

            SalesIntents::HIGH_INTENT_CLOSE =>
                'Perfecto. Te puedo dejar el link seguro de pago y apenas se confirme, '
                .'tu acceso queda listo.',

            SalesIntents::LOCATION_QUESTION =>
                'Estamos en Iron Body Neiva. Te comparto la ubicación y horarios para que te '
                .'quede fácil llegar. ¿Quieres que te oriente con un plan también?',

            SalesIntents::SCHEDULE_QUESTION =>
                'Con gusto te cuento los horarios de Iron Body Neiva. ¿Buscas entrenar en la '
                .'mañana, tarde o noche? Así te oriento mejor.',

            SalesIntents::MEDICAL_RISK_ESCALATION =>
                'Prefiero que esto lo revise una persona del equipo para orientarte bien y no '
                .'darte una respuesta irresponsable.',

            SalesIntents::FRAUD_OR_PAYMENT_CLAIM =>
                'Para revisar tu caso con cuidado, lo va a atender una persona del equipo. '
                .'Tu membresía solo se activa cuando el pago queda confirmado en el sistema.',

            SalesIntents::DO_NOT_CONTACT_REQUEST =>
                null, // respetamos: no insistimos.

            default =>
                '¡Hola! 💪 Soy del equipo de Iron Body Neiva. Cuéntame qué buscas y te ayudo: '
                .'¿planes, horarios o ubicación?',
        };
    }

    /**
     * Respuesta DETERMINISTA de precio. Si el plan está identificado, incluye
     * nombre + precio REAL (Plan::price, formateado COP) + beneficios + cierre
     * suave. Si NO hay plan claro, NO inventa precio: pide objetivo/plan. El
     * precio nunca lo decide el modelo: sale del backend.
     */
    public function pricingReply(?Plan $plan): string
    {
        if ($plan === null) {
            return (string) $this->replyFor(SalesIntents::PRICING_QUESTION);
        }

        $price    = $this->formatCop((float) $plan->price);
        $benefits = $plan->benefitsArray();
        $line     = '';
        if (! empty($benefits)) {
            $top  = array_slice($benefits, 0, 3);
            $line = ' Incluye: '.implode(', ', $top).'.';
        }

        return "El plan {$plan->name} tiene un valor de {$price}.{$line} "
            .'¿Quieres que te envíe el link seguro de pago?';
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
