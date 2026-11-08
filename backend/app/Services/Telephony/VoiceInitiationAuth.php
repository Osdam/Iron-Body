<?php

namespace App\Services\Telephony;

use App\Services\Voice\ElevenLabs\ElevenLabsAgentMapper;

/**
 * La autenticación del webhook de iniciación de las entrantes (contrato H8,
 * D8-11 y D8-16): el secreto de iniciación (`ELEVENLABS_INITIATION_SECRET`)
 * en la cabecera fija `X-Voice-Initiation-Secret`, la misma que el agente
 * compilado manda por referencia (`ElevenLabsAgentMapper::INITIATION_AUTH_HEADER`).
 * En tiempo constante. El ÚNICO lector de ese secreto en la telefonía (D8-5).
 *
 * FALLA CERRADO: sin secreto configurado, o con la cabecera ausente o vacía,
 * no vale (nunca `hash_equals('', '')`). Es DISTINTO del de las herramientas
 * y del del webhook post-llamada: el de otro no abre esta puerta.
 */
final class VoiceInitiationAuth
{
    public const HEADER = ElevenLabsAgentMapper::INITIATION_AUTH_HEADER;

    public static function check(?string $header): bool
    {
        $secreto = trim((string) config('voice.elevenlabs.initiation_secret'));
        if ($secreto === '' || ! is_string($header) || trim($header) === '') {
            return false;
        }

        return hash_equals($secreto, trim($header));
    }
}
