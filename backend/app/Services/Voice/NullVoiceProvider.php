<?php

namespace App\Services\Voice;

use App\Services\Voice\ElevenLabs\ElevenLabsConfig;

/**
 * El proveedor por defecto (contrato H6 §I6.1): no habla con nadie. Su estado
 * es el de la configuración de ElevenLabs, para que se lea qué falta; su
 * verificación responde `not_configured` sin red y con latencia 0.
 */
final class NullVoiceProvider implements VoiceAgentProvider
{
    public function id(): string
    {
        return VoiceProviderStatus::PROVIDER_NONE;
    }

    public function status(): VoiceProviderStatus
    {
        return ElevenLabsConfig::fromConfig()->status();
    }

    public function verifyCredentials(): VoiceCheckResult
    {
        return new VoiceCheckResult(VoiceCheckResult::OUTCOME_NOT_CONFIGURED, null, null, 0);
    }
}
