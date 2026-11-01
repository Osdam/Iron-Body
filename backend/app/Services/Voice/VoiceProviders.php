<?php

namespace App\Services\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsConfig;
use App\Services\Voice\ElevenLabs\ElevenLabsVoiceProvider;

/**
 * La fábrica del puerto de voz (contrato H6 §I6.1). Sin contenedor ni
 * proveedores de servicio: nadie puede cambiar el proveedor registrando otra
 * clase, y el `AppServiceProvider` no se toca.
 */
final class VoiceProviders
{
    /** Siempre ElevenLabsConfig::fromConfig()->status(). */
    public static function status(): VoiceProviderStatus
    {
        return ElevenLabsConfig::fromConfig()->status();
    }

    /**
     * ElevenLabsVoiceProvider solo si enabled === true y missing() === []; si
     * no, NullVoiceProvider. Las dos condiciones juntas son exactamente el
     * estado `ELEVENLABS_UNVERIFIED`.
     */
    public static function current(): VoiceAgentProvider
    {
        $config = ElevenLabsConfig::fromConfig();

        return $config->enabled() === true && $config->missing() === []
            ? new ElevenLabsVoiceProvider
            : new NullVoiceProvider;
    }
}
