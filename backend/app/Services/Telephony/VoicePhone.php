<?php

namespace App\Services\Telephony;

use InvalidArgumentException;
use RuntimeException;

/**
 * La huella de voz de un teléfono (contrato H8, D8-17): HMAC-SHA256 del E.164
 * con su PROPIA subclave de la `APP_KEY`, etiqueta `voice-phone`, distinta de
 * la de los canarios del H6 (`ultron-canary-phone/1`): una huella de un dominio
 * no sirve para buscar en el otro. `voice_calls.remote_hash`,
 * `voice_contacts.phone_hash`, `voice_exclusions.phone_hash` y
 * `voice_rne_checks.phone_hash` son esta huella.
 *
 * NO NORMALIZA (DF-P15): recibe un E.164 estricto y lanza con cualquier otra
 * cosa; normalizar es de quien llama (`StudioCanaryPhone::parse()` para los
 * números que escribe una persona). Los mensajes no llevan el valor.
 */
final class VoicePhone
{
    public const LABEL = 'voice-phone';

    /** E.164 estricto; con `D`, un salto de línea final no pasa. */
    public const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    public static function isE164(mixed $valor): bool
    {
        return is_string($valor) && preg_match(self::E164_PATTERN, $valor) === 1;
    }

    /**
     * @throws InvalidArgumentException si no es un E.164 estricto
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function hash(#[\SensitiveParameter] string $e164): string
    {
        return self::hashWithKey($e164, self::appKey());
    }

    /**
     * Lo mismo con la clave explícita (los bytes de la `APP_KEY`, ya
     * decodificada): para las pruebas y los fixtures.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException con una clave vacía
     */
    public static function hashWithKey(#[\SensitiveParameter] string $e164, #[\SensitiveParameter] string $appKeyRaw): string
    {
        self::assertE164($e164);
        if ($appKeyRaw === '') {
            throw new RuntimeException('Sin clave no se calcula la huella de voz de un teléfono.');
        }

        return hash_hmac('sha256', $e164, hash_hmac('sha256', self::LABEL, $appKeyRaw, true));
    }

    /** Los 4 últimos dígitos (D8-17). @throws InvalidArgumentException */
    public static function last4(#[\SensitiveParameter] string $e164): string
    {
        self::assertE164($e164);

        return substr($e164, -4);
    }

    /** La `APP_KEY` como la lee Laravel (`EncryptionServiceProvider::parseKey`). */
    public static function appKey(): string
    {
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7), true);
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se calcula la huella de voz de un teléfono.');
        }

        return $clave;
    }

    private static function assertE164(string $e164): void
    {
        if (! self::isE164($e164)) {
            throw new InvalidArgumentException('La huella de voz necesita un teléfono en E.164.');
        }
    }
}
