<?php

namespace App\Services\Telephony;

use App\Models\Voice\VoiceCall;

/**
 * Los destinos de transferencia APROBADOS de una llamada (contrato H8, D8-6,
 * D8-14; Enmienda 5, E5-1): los COMPILADOS en el agente que la SELLÓ, tal como
 * los da {@see VoicePublishedConfig} (alias y huella `voice-phone`, nunca el
 * número). La guarda compara huellas.
 *
 * Los destinos los fija el AGENTE (es inmutable):
 * valen los de la entrada vigente de la parte de la llamada si apunta al
 * MISMO agente del sello, sea esa entrada u otra posterior (una vuelta atrás a
 * ese agente lleva los mismos destinos). Si la parte apunta ya a otro agente,
 * o la llamada no tiene sello, no se sabe cuáles eran y ninguno vale (fallo
 * cerrado: la guarda trata toda transferencia como
 * `voice_transfer_not_approved` y `solicitar_humano` dice que no hay nadie).
 * La regla vive en {@see VoicePublishedConfig::partForCall()}: la usa también
 * el catálogo de planes publicado (PT-14).
 */
final class VoiceTransferDirectory
{
    /** @return list<string>|null las huellas aprobadas para esta llamada; null = no se sabe (ninguna vale) */
    public static function approvedHashesFor(VoiceCall $call): ?array
    {
        return VoicePublishedConfig::partForCall($call)?->transferDestinationHashes();
    }

    /** @return list<string> los alias aprobados para esta llamada (sin números) */
    public static function aliasesFor(VoiceCall $call): array
    {
        return VoicePublishedConfig::partForCall($call)?->transferAliases() ?? [];
    }

    /**
     * Parte → cuántos destinos aprobados tiene su entrada vigente (null =
     * ninguno se puede leer). Quien ya leyó la cadena la pasa (P8-1).
     *
     * @param  array<string, VoicePublishedPart>|null  $parts  la salida de {@see VoicePublishedConfig::read()}
     * @return array<string, int|null>
     */
    public static function countsByPart(?array $parts = null): array
    {
        $out = [];
        foreach ($parts ?? VoicePublishedConfig::read() as $nombre => $parte) {
            $out[$nombre] = $parte->ok() && $parte->transferDestinationHashes() !== null ? count($parte->transferDestinationHashes()) : null;
        }

        return $out;
    }
}
