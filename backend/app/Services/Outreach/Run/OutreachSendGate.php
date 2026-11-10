<?php

namespace App\Services\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;

/**
 * La PUERTA DE ENVÍO del H9 (contrato H9, §3 protección 2, §9.1, D9-14,
 * L9-44): la constante de código que cierra, por encima de toda bandera, el
 * envío AUTOMÁTICO hacia una persona (WhatsApp por plantillas y voz).
 *
 * `SENDS_ENABLED_IN_CODE` es un literal, UNA sola vez en el árbol (la guarda
 * de SEC9 y el mando de OPS9 lo comprueban en el fichero y en ejecución):
 *  - `false`: el modo `live` solo admite el canal asistido y el simulado;
 *    ningún mensaje ni llamada automática sale, diga lo que diga la
 *    configuración. En sombra se anota como aviso (`sends_disabled_in_code`,
 *    L9-16) para enseñar qué pasaría en vivo;
 *  - se gira SOLO en un commit de una línea cuyo mensaje empiece por
 *    `outreach(sends-open):` y cite la autorización escrita del propietario
 *    (§14, fase 4), con W9 desplegado y el visto bueno legal;
 *  - NUNCA se gira en el árbol compartido para probar: la vía permitida se
 *    ensaya en una copia del banco (como `voice(calls-open)` en el H8).
 *
 * Cada canal lleva además su estado real ({@see channelLive()}): el 15-11
 * solo el asistido está implementado; WhatsApp (W9) y voz (V9) tienen sus
 * puertas VACÍAS y responden `channel_not_live` (aviso en sombra, negativa en
 * vivo). Los cuatro cerrojos independientes de un canal que gasta (protección
 * 12) son: la bandera, el modo `live` con esta constante, el presupuesto > 0 y
 * la credencial o aprobación externa; esta clase responde por los dos
 * primeros y {@see automaticSendAllowed()} los junta.
 */
final class OutreachSendGate
{
    /** PROTECCIÓN 2. Se abre solo con un commit `outreach(sends-open): …`. */
    public const SENDS_ENABLED_IN_CODE = false;

    /** Los canales de trabajo con puerta IMPLEMENTADA el 15-11 (W9 y V9 añaden los suyos). */
    public const LIVE_CHANNELS = ['simulated', 'assisted'];

    public static function sendsEnabledInCode(): bool
    {
        return self::SENDS_ENABLED_IN_CODE === true;
    }

    /** ¿Tiene este canal de trabajo su puerta implementada? (`channel_not_live` si no). */
    public static function channelLive(string $jobChannel): bool
    {
        return in_array($jobChannel, self::LIVE_CHANNELS, true);
    }

    /** El canal de trabajo de un canal de paso (`assisted:whatsapp` → `assisted`; los demás, ellos mismos). */
    public static function jobChannelOf(string $stepChannel): string
    {
        return OutreachCatalog::STEP_CHANNELS[$stepChannel]['channel'] ?? $stepChannel;
    }

    /**
     * Las tres banderas de N4 que la escalera espera de este canal de paso
     * (`flags.channelEnabled`, `flags.channelLive`, `flags.sendsEnabledInCode`).
     *
     * @return array{channelEnabled: bool, channelLive: bool, sendsEnabledInCode: bool}
     */
    public static function channelFlags(string $stepChannel): array
    {
        $canal = self::jobChannelOf($stepChannel);

        return [
            'channelEnabled' => OutreachConfig::channelEnabled($canal),
            'channelLive' => self::channelLive($canal),
            'sendsEnabledInCode' => self::sendsEnabledInCode(),
        ];
    }

    /**
     * ¿Puede SALIR AUTOMÁTICAMENTE hacia una persona por este canal de
     * trabajo? Solo los automáticos (`whatsapp_template`, `voice_call`), y
     * solo con la constante abierta, el modo `live`, la bandera del canal y su
     * puerta implementada. El asistido y el simulado no son envíos
     * automáticos: devuelven false (su salida la gobierna la escalera).
     */
    public static function automaticSendAllowed(string $jobChannel): bool
    {
        return in_array($jobChannel, OutreachCatalog::AUTOMATIC_CHANNELS, true)
            && self::sendsEnabledInCode()
            && OutreachConfig::isLive()
            && OutreachConfig::channelEnabled($jobChannel)
            && self::channelLive($jobChannel);
    }
}
