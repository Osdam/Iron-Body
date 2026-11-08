<?php

namespace App\Services\Telephony;

use App\Services\Voice\VoiceProviders;
use App\Services\Voice\VoiceProviderStatus;

/**
 * El estado de ElevenLabs para la política de salientes (contrato H8, D8-12):
 * `VERIFIED` exige una verificación `ok` de menos de 30 días.
 *
 * LA EVIDENCIA de esa verificación la guarda el Studio
 * (`ultron-studio:integrations-snapshot --verify-voice`, en su tabla de
 * instantáneas) y la telefonía no puede leerla (TelephonyIsolationTest: del
 * Studio, solo los canarios y su cadena). Hasta que el lead enumere un
 * adaptador de solo lectura para esa evidencia, aquí NUNCA sale `VERIFIED`:
 * con la bandera y todas las piezas el estado es `ELEVENLABS_UNVERIFIED` y la
 * política dice `elevenlabs_unverified`. Falla cerrado (ninguna saliente).
 */
final class VoiceProviderState
{
    public static function current(): string
    {
        $estado = VoiceProviders::status()->state;

        return $estado === VoiceProviderStatus::STATE_UNVERIFIED && self::verifiedRecently() ? VoiceProviderStatus::STATE_VERIFIED : $estado;
    }

    /** ¿Lo verificó alguien hace menos de 30 días? Sin adaptador enumerado, no se sabe: no. */
    public static function verifiedRecently(): bool
    {
        return false;
    }
}
