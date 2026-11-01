<?php

namespace App\Services\Voice;

/**
 * El puerto de voz (contrato H6 §I6.1): un espacio de nombres NEUTRO, que no
 * nombra al motor ni a quien lo administra, y al que el motor no llama.
 *
 * EL PUERTO NO TIENE NINGÚN MÉTODO QUE INICIE, REGISTRE NI TRANSFIERA UNA
 * LLAMADA. Solo dice cómo está la configuración ({@see status()}, sin red) y
 * comprueba la clave y el agente con un GET ({@see verifyCredentials()}), que
 * en este hito solo se lanza por consola. Las llamadas las diseña el hito de
 * voz, con su autorización expresa; hasta entonces las cierra además
 * {@see VoiceCallPolicy::CALLS_ENABLED_IN_CODE}.
 *
 * Implementaciones: {@see NullVoiceProvider} (por defecto) y
 * {@see ElevenLabs\ElevenLabsVoiceProvider} (solo con la bandera encendida y
 * todas las piezas). La elige {@see VoiceProviders::current()}.
 */
interface VoiceAgentProvider
{
    /** 'none' | 'elevenlabs' */
    public function id(): string;

    /** Sin red: configuración y piezas que faltan. */
    public function status(): VoiceProviderStatus;

    /** Con red (un GET), SOLO por consola. Nunca lanza: los fallos son resultados. */
    public function verifyCredentials(): VoiceCheckResult;
}
