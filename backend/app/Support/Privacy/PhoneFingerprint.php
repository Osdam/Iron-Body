<?php

namespace App\Support\Privacy;

use InvalidArgumentException;
use RuntimeException;

/**
 * La HUELLA de un teléfono en E.164: un HMAC-SHA256 con una subclave propia
 * derivada de la `APP_KEY`, para poder reconocer un número sin guardarlo
 * (Ley 1581). No se puede invertir sin la clave y, por la forma de los
 * teléfonos, tampoco con ella sale el número: solo se COMPARA.
 *
 * La derivación es la de la firma de las publicaciones del Hito 5 (la
 * `APP_KEY` es la CLAVE y la etiqueta, el DATO), con su propia etiqueta
 * versionada para que esta huella no se pueda cruzar con ninguna otra:
 *
 *   k  = HMAC-SHA256(clave = APP_KEY cruda, mensaje = LABEL)   (32 bytes)
 *   fp = HMAC-SHA256(clave = k, mensaje = E.164)               (64 hex en minúsculas)
 *
 * NO NORMALIZA: recibe un E.164 ya validado (`+` y de 8 a 15 dígitos, sin
 * espacios) y rechaza cualquier otra cosa sin repetir el valor en el mensaje.
 * Normalizar es de quien llama, que sabe de qué canal y país se trata.
 *
 * Rotar la `APP_KEY` deja inservibles todas las huellas: los números se
 * vuelven a registrar (contrato H6 §I13).
 *
 * Clase neutra: no sabe para qué se usa la huella ni quién la guarda.
 */
final class PhoneFingerprint
{
    public const LABEL = 'ultron-canary-phone/1';

    /** E.164 estricto. Con `D`, `$` es el final de verdad: un salto de línea al final no pasa. */
    public const E164_PATTERN = '/^\+[1-9][0-9]{7,14}$/D';

    /**
     * Huella de un E.164 con la `APP_KEY` de la aplicación.
     *
     * @throws InvalidArgumentException si `$e164` no es un E.164 estricto
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function of(#[\SensitiveParameter] string $e164): string
    {
        self::assertE164($e164);

        return self::withKey($e164, self::appKey());
    }

    /**
     * Lo mismo con la clave explícita (pruebas y fixture). `$appKeyRaw` son
     * los bytes de la `APP_KEY`: sin el prefijo `base64:`, ya decodificada.
     *
     * @throws InvalidArgumentException si `$e164` no es un E.164 estricto
     * @throws RuntimeException con una clave vacía
     */
    public static function withKey(#[\SensitiveParameter] string $e164, #[\SensitiveParameter] string $appKeyRaw): string
    {
        self::assertE164($e164);
        if ($appKeyRaw === '') {
            throw new RuntimeException('Sin clave no se calcula la huella de un teléfono.');
        }

        return hash_hmac('sha256', $e164, hash_hmac('sha256', self::LABEL, $appKeyRaw, true));
    }

    /** La `APP_KEY` como la lee Laravel (`EncryptionServiceProvider::parseKey`). */
    private static function appKey(): string
    {
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7));
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se calcula la huella de un teléfono.');
        }

        return $clave;
    }

    private static function assertE164(string $e164): void
    {
        if (preg_match(self::E164_PATTERN, $e164) !== 1) {
            // Sin el valor en el mensaje: puede acabar en un log.
            throw new InvalidArgumentException('La huella necesita un teléfono en E.164.');
        }
    }
}
