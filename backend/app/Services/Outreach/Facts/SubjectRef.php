<?php

namespace App\Services\Outreach\Facts;

use InvalidArgumentException;
use RuntimeException;

/**
 * El SEUDÓNIMO de una persona fuera del servidor (contrato H9, §3.10, D9-13 y
 * L9-4): `subject_ref` = HMAC-SHA256 de la clave interna ({@see SubjectKey})
 * con una subclave PROPIA derivada de la `APP_KEY`, etiqueta
 * {@see LABEL}. Es la derivación de la firma del H5 y de las huellas de
 * teléfono (`PhoneFingerprint`, `VoicePhone`): la `APP_KEY` es la CLAVE y la
 * etiqueta el DATO.
 *
 *   k   = HMAC-SHA256(clave = APP_KEY cruda, mensaje = 'outreach-subject/1')   (32 bytes)
 *   ref = HMAC-SHA256(clave = k, mensaje = subject_key)                        (64 hex)
 *
 * Con otra etiqueta que la de los canarios (`ultron-canary-phone/1`), la de voz
 * (`voice-phone`) y las firmas de las cadenas: un valor de un dominio no sirve
 * para buscar en otro. No es enumerable sin la clave (D9-13: el historial se
 * pide por `subjectRef`).
 *
 * Rotar la `APP_KEY` deja obsoletos los `subject_ref` guardados (L9-4): OPS9 lo
 * documenta; se rederivan en lote, nunca a mano.
 */
final class SubjectRef
{
    public const LABEL = 'outreach-subject/1';

    public const PATTERN = '/^[0-9a-f]{64}$/D';

    /**
     * @throws InvalidArgumentException si la clave no tiene la forma de {@see SubjectKey}
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function of(string $subjectKey): string
    {
        return self::withKey($subjectKey, self::appKey());
    }

    /**
     * Lo mismo con la clave explícita (los bytes de la `APP_KEY`, ya
     * decodificada): para las pruebas y los fixtures.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException con una clave vacía
     */
    public static function withKey(string $subjectKey, #[\SensitiveParameter] string $appKeyRaw): string
    {
        if (! SubjectKey::isValid($subjectKey)) {
            throw new InvalidArgumentException('El seudónimo necesita una clave de persona m:<id> o l:<id>.');
        }

        return hash_hmac('sha256', $subjectKey, self::subkey($appKeyRaw));
    }

    /** k = HMAC-SHA256(APP_KEY, etiqueta), 32 bytes. */
    public static function subkey(#[\SensitiveParameter] string $appKeyRaw): string
    {
        if ($appKeyRaw === '') {
            throw new RuntimeException('Sin clave no se calcula el seudónimo de una persona.');
        }

        return hash_hmac('sha256', self::LABEL, $appKeyRaw, true);
    }

    public static function isValid(mixed $ref): bool
    {
        return is_string($ref) && preg_match(self::PATTERN, $ref) === 1;
    }

    /** La `APP_KEY` como la lee Laravel (`EncryptionServiceProvider::parseKey`). */
    public static function appKey(): string
    {
        $clave = config('app.key');
        if (is_string($clave) && str_starts_with($clave, 'base64:')) {
            $clave = base64_decode(substr($clave, 7), true);
        }
        if (! is_string($clave) || $clave === '') {
            throw new RuntimeException('Sin APP_KEY no se calcula el seudónimo de una persona.');
        }

        return $clave;
    }
}
