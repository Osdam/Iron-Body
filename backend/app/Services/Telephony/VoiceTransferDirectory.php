<?php

namespace App\Services\Telephony;

/**
 * Los destinos de transferencia APROBADOS de la publicación vigente (contrato
 * H8, D8-6, D8-14): su alias (para `solicitar_humano`) y la huella
 * `voice-phone` de su número (para la guarda posterior).
 *
 * Viven en el perfil de voz aprobado (Studio, S8) y la telefonía no puede
 * leerlo (TelephonyIsolationTest). Hasta que el lead enumere cómo llegan
 * (un adaptador de solo lectura o el sello de la cadena), el directorio está
 * VACÍO y falla cerrado: `solicitar_humano` dice que no hay nadie (el agente
 * no transfiere) y cualquier `transfer_to_number` que aparezca en una
 * transcripción es `voice_transfer_not_approved` (abre el freno).
 */
final class VoiceTransferDirectory
{
    /** @return list<string>|null las huellas aprobadas; null = no se sabe (ninguna vale) */
    public static function approvedHashes(): ?array
    {
        return null;
    }

    /** @return list<string> los alias aprobados (sin números) */
    public static function aliases(): array
    {
        return [];
    }
}
