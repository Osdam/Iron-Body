<?php

namespace App\Services\Telephony;

/**
 * La autenticación de las server tools (contrato H8, D8-10 y D8-16): el
 * Bearer con el secreto de las herramientas (`ELEVENLABS_TOOL_SECRET`), en
 * tiempo constante. El ÚNICO lector de ese secreto en la telefonía (D8-5),
 * en el momento de usarlo y sin guardarlo.
 *
 * FALLA CERRADO: sin secreto configurado no vale ninguna cabecera (nunca
 * `hash_equals('', '')`), y una cabecera vacía o sin el esquema `Bearer`
 * tampoco. El secreto del gestor de ElevenLabs tiene que valer exactamente
 * `Bearer <ELEVENLABS_TOOL_SECRET>`.
 */
final class VoiceToolAuth
{
    public const HEADER = 'Authorization';

    public static function check(?string $authorization): bool
    {
        $secreto = trim((string) config('voice.elevenlabs.tool_secret'));
        if ($secreto === '' || ! is_string($authorization)) {
            return false;
        }
        $recibido = trim($authorization);
        if (! str_starts_with($recibido, 'Bearer ') || strlen($recibido) <= 7) {
            return false;
        }

        return hash_equals('Bearer '.$secreto, $recibido);
    }
}
