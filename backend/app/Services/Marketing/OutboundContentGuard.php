<?php

namespace App\Services\Marketing;

use App\Models\MarketingMessage;
use App\Services\Observability\ChannelLog;

/**
 * Último filtro para el texto que escribe una MÁQUINA y no compuso Laravel.
 *
 * El agujero que tapa es concreto. `SalesAgentDecisionValidator` revisa lo que
 * dice el modelo, pero vive DENTRO de los responders: solo corre cuando el
 * cerebro local produce la respuesta. Un cuerpo que llega ya escrito por
 * `POST /api/internal/marketing/send-message` —hoy el camino previsto para
 * n8n— no lo atravesaba: entraba directo al dispatcher y salía a Meta con un
 * precio inventado, una promesa prohibida o un intento de activar una
 * membresía, sin que nada lo mirara.
 *
 * POR AUTOR, NO POR CANAL. Esta es la parte que no es obvia:
 *
 *  - `ai` y `system`  → se filtra. Todo texto de máquina pasa por las mismas
 *    reglas, venga del modelo o de una automatización externa.
 *  - `human`          → NO se filtra. Un asesor PUEDE citar un precio: es su
 *    trabajo. Aplicarle la expresión regular de precios le rompería la
 *    herramienta para protegerlo de sí mismo, que no es de lo que va esto.
 *
 * Tampoco se aplica al texto que compone Laravel a partir de `Plan::price`
 * —`pricingReply()`, `paymentLinkMessage()`—: ese precio es la verdad de la
 * base de datos, no una invención del modelo. Por eso el guard vive en la
 * frontera de ENTRADA del texto ajeno (el endpoint interno) y no dentro de
 * {@see MarketingMessageDispatcher}, que seguiría siendo un despachador y
 * pasaría a ser, además, un censor de sus propios mensajes legítimos.
 *
 * No duplica ninguna regla: pregunta a {@see SalesAgentDecisionSchema}, que es
 * donde ya vivían los catálogos y adonde se movió el patrón de precios.
 */
class OutboundContentGuard
{
    /** Autores cuyo texto lo escribe una máquina. */
    public const MACHINE_SENDERS = [
        MarketingMessage::SENDER_AI,
        MarketingMessage::SENDER_SYSTEM,
    ];

    public const CODE_FORBIDDEN_ACTION = 'machine_reply_forbidden_action';

    public const CODE_UNSAFE_CLAIM = 'machine_reply_unsafe_claim';

    public const CODE_INVENTED_PRICE = 'machine_reply_invented_price';

    /** ¿Este autor está sujeto al filtro? */
    public function appliesTo(string $senderType): bool
    {
        return in_array($senderType, self::MACHINE_SENDERS, true);
    }

    /**
     * Revisa el texto sin lanzar. Útil para diagnosticar y para las pruebas.
     *
     * El orden es por gravedad, no por comodidad: primero el intento de hacer
     * algo prohibido (activar una membresía, aprobar un pago), después la
     * promesa que no podemos sostener, y al final el precio inventado. Si un
     * mensaje viola tres reglas, el motivo que se reporta es el más grave.
     *
     * @return array{safe:bool, code:?string, signal:?string, risk_flag:?string}
     */
    public function inspect(string $body, string $senderType): array
    {
        $ok = ['safe' => true, 'code' => null, 'signal' => null, 'risk_flag' => null];

        if (! $this->appliesTo($senderType)) {
            return $ok;
        }

        if (trim($body) === '') {
            return $ok; // un envío vacío lo rechaza la validación del endpoint.
        }

        if ($signal = SalesAgentDecisionSchema::forbiddenSignalIn($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_FORBIDDEN_ACTION,
                'signal' => $signal,
                // Mismo nombre que usa el validador, para poder cruzar ambos en el log.
                'risk_flag' => 'forbidden_action',
            ];
        }

        if ($signal = SalesAgentDecisionSchema::unsafeSignalIn($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_UNSAFE_CLAIM,
                'signal' => $signal,
                'risk_flag' => 'unsafe_claim',
            ];
        }

        if (SalesAgentDecisionSchema::containsPrice($body)) {
            return [
                'safe' => false,
                'code' => self::CODE_INVENTED_PRICE,
                // La señal NO es el precio encontrado: sería meter el contenido
                // del mensaje en el log. Basta con saber qué regla saltó.
                'signal' => null,
                'risk_flag' => 'price_in_reply',
            ];
        }

        return $ok;
    }

    /**
     * Deja pasar el texto o lo detiene.
     *
     * @throws SalesGuardrailException cuando el texto de máquina no puede salir.
     */
    public function assertSafe(string $body, string $senderType, array $context = []): void
    {
        $result = $this->inspect($body, $senderType);

        if ($result['safe']) {
            return;
        }

        // Nunca se registra el cuerpo: la señal sale de NUESTRO catálogo y la
        // longitud basta para reproducir el caso sin publicar lo que iba a
        // leer una persona.
        ChannelLog::warning('outbound.guard.blocked', array_merge($context, [
            'code' => $result['code'],
            'risk_flag' => $result['risk_flag'],
            'signal' => $result['signal'],
            'sender_type' => $senderType,
            'body_length' => mb_strlen($body),
        ]));

        throw SalesGuardrailException::make(
            $result['code'],
            $this->explain($result['code']),
            escalate: true,
        );
    }

    /**
     * Qué se le dice a quien llamó (n8n o una automatización), no al cliente.
     * Explica la regla y cómo cumplirla; un «contenido no permitido» obligaría
     * a leer este archivo para saber qué cambiar.
     */
    private function explain(string $code): string
    {
        return match ($code) {
            self::CODE_FORBIDDEN_ACTION => 'El mensaje automático menciona una acción que el agente no puede ejecutar '
                .'(activar membresía, aprobar pago o tocar facturación). No se envía. Esas acciones las resuelve una persona.',
            self::CODE_UNSAFE_CLAIM => 'El mensaje automático promete resultados o da un diagnóstico. No se envía. '
                .'Reformúlalo sin garantías ni valoraciones clínicas.',
            self::CODE_INVENTED_PRICE => 'El mensaje automático incluye una cifra que parece un precio. No se envía. '
                .'Los precios los pone el backend desde el plan activo: manda el texto sin la cifra.',
            default => 'El mensaje automático no cumple las reglas de contenido y no se envía.',
        };
    }
}
