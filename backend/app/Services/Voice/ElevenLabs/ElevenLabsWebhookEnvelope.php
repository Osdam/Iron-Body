<?php

namespace App\Services\Voice\ElevenLabs;

/**
 * El sobre del webhook post-llamada (contrato H6 §I6.4): de todo el cuerpo
 * solo se reconoce el `type`. NO CONSERVA NADA MÁS: ni la transcripción, ni
 * el audio, ni los ids, ni los teléfonos. Pura y sin ruta pública en este
 * hito.
 */
final class ElevenLabsWebhookEnvelope
{
    public const TYPES = ['post_call_transcription', 'post_call_audio', 'call_initiation_failure'];

    /** El `type` si es uno de TYPES; null con JSON no válido, sin `type` o con otro valor. No conserva NADA más (ni la transcripción). */
    public static function type(string $rawBody): ?string
    {
        $cuerpo = json_decode($rawBody, true);
        if (! is_array($cuerpo) || array_is_list($cuerpo)) {
            return null;
        }
        $tipo = $cuerpo['type'] ?? null;
        unset($cuerpo);

        return is_string($tipo) && in_array($tipo, self::TYPES, true) ? $tipo : null;
    }
}
