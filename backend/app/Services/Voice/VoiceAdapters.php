<?php

namespace App\Services\Voice;

use App\Services\Twilio\TwilioVoiceCarrier;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\ElevenLabs\ElevenLabsCallStarter;
use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\ElevenLabs\ElevenLabsEventVerifier;
use App\Services\Voice\ElevenLabs\ElevenLabsLabSessionIssuer;

/**
 * La fábrica de las interfaces de D8-3 (contrato H8), como
 * {@see VoiceProviders} lo es del puerto del H6: sin contenedor ni proveedores
 * de servicio, así que nadie cambia una implementación registrando otra clase.
 * Por defecto, todo es nulo y nada sale a la red.
 *
 *  - Desplegador (y su lector del número, E4-8), iniciador y emisor del
 *    laboratorio: el de ElevenLabs solo con la bandera encendida y todas las
 *    piezas (`ELEVENLABS_UNVERIFIED`); si no, el nulo. Cada adaptador lo vuelve a comprobar antes de salir, y el
 *    iniciador además exige `CALLS_ENABLED_IN_CODE`.
 *  - Verificador del webhook: el de ElevenLabs si hay secreto del webhook
 *    (no necesita red ni la bandera: un evento que llega con la voz ya
 *    apagada se puede comprobar); si no, el nulo, que nunca acepta.
 *  - Operador: el de Twilio con la credencial de la subcuenta completa, sin
 *    mirar `TWILIO_VOICE_ENABLED`: cortar y conciliar tienen que poder hacerse
 *    con la voz ya apagada (D8-20 nivel 0b). Nunca inicia llamadas.
 */
final class VoiceAdapters
{
    public static function deployer(): VoiceAgentDeployer
    {
        return self::elevenLabsReady() ? new ElevenLabsAgentDeployer : new NullVoiceAgentDeployer;
    }

    /** E4-8: quien lee a qué agente apunta el número (el desplegador de ElevenLabs, solo su GET), o el nulo. */
    public static function numberObserver(): VoiceNumberObserver
    {
        return self::elevenLabsReady() ? new ElevenLabsAgentDeployer : new NullVoiceNumberObserver;
    }

    public static function callStarter(): VoiceCallStarter
    {
        return self::elevenLabsReady() ? new ElevenLabsCallStarter : new NullVoiceCallStarter;
    }

    public static function eventVerifier(): VoiceEventVerifier
    {
        return in_array('webhook_secret', ElevenLabsConfig::fromConfig()->missing(), true)
            ? new NullVoiceEventVerifier
            : new ElevenLabsEventVerifier;
    }

    public static function labSessionIssuer(): VoiceLabSessionIssuer
    {
        return self::elevenLabsReady() ? new ElevenLabsLabSessionIssuer : new NullVoiceLabSessionIssuer;
    }

    public static function carrier(): TelephonyCarrier
    {
        return TwilioVoiceCarrier::configured() ? new TwilioVoiceCarrier : new NullTelephonyCarrier;
    }

    /** Exactamente el estado `ELEVENLABS_UNVERIFIED`: bandera encendida y todas las piezas. */
    private static function elevenLabsReady(): bool
    {
        return ElevenLabsConfig::fromConfig()->status()->state === VoiceProviderStatus::STATE_UNVERIFIED;
    }
}
