<?php

namespace App\Services\Outreach\Facts;

use InvalidArgumentException;
use RuntimeException;

/**
 * El SEUDÓNIMO DE UN TELÉFONO (decisión del lead L9-29, sobre Q-8 de B9-facts):
 * el tope diario de contactos se aplica también POR TELÉFONO, para que dos
 * personas que comparten número (una familia, un número reciclado) no reciban
 * dos contactos el mismo día. La foto lo expone como `phone_ref`; el teléfono
 * nunca sale del adaptador.
 *
 *   k         = HMAC-SHA256(clave = APP_KEY cruda, mensaje = 'outreach-phone/1')   (32 bytes)
 *   phone_ref = HMAC-SHA256(clave = k, mensaje = número normalizado)             (64 hex)
 *
 * El número es el de {@see PhoneKey::normalize()} (solo dígitos, con el 57 de un
 * celular colombiano: la misma identidad que usa la puerta de WhatsApp y la
 * regla de los hermanos), así que `3001234567` y `+57 300 123 4567` dan el
 * mismo `phone_ref`. Lo que esa normalización no reconoce (un fijo, un número
 * corto) no tiene seudónimo: desconocido.
 *
 * Etiqueta propia, distinta de `outreach-subject/1` ({@see SubjectRef}), de
 * `ultron-canary-phone/1` (canarios) y de `voice-phone` (voz): un valor de un
 * dominio no sirve para buscar en otro. Rotar la `APP_KEY` lo deja obsoleto,
 * como `subject_ref` (L9-4).
 */
final class PhoneRef
{
    public const LABEL = 'outreach-phone/1';

    /** La forma de un número normalizado ({@see PhoneKey::normalize()}). */
    public const NORMALIZED_PATTERN = '/^[0-9]{11,15}$/D';

    /**
     * El seudónimo de un teléfono tal como esté escrito, o null si no es un número.
     *
     * @throws RuntimeException sin `APP_KEY`
     */
    public static function fromRaw(#[\SensitiveParameter] ?string $phone): ?string
    {
        $normal = PhoneKey::normalize($phone);

        return $normal === null ? null : self::withKey($normal, SubjectRef::appKey());
    }

    /**
     * @throws InvalidArgumentException si no es un número normalizado
     * @throws RuntimeException con una clave vacía
     */
    public static function withKey(#[\SensitiveParameter] string $normalized, #[\SensitiveParameter] string $appKeyRaw): string
    {
        if (preg_match(self::NORMALIZED_PATTERN, $normalized) !== 1) {
            // Sin el valor en el mensaje: puede acabar en un log.
            throw new InvalidArgumentException('El seudónimo de un teléfono necesita el número normalizado.');
        }

        return hash_hmac('sha256', $normalized, self::subkey($appKeyRaw));
    }

    /** k = HMAC-SHA256(APP_KEY, etiqueta), 32 bytes. */
    public static function subkey(#[\SensitiveParameter] string $appKeyRaw): string
    {
        if ($appKeyRaw === '') {
            throw new RuntimeException('Sin clave no se calcula el seudónimo de un teléfono.');
        }

        return hash_hmac('sha256', self::LABEL, $appKeyRaw, true);
    }

    public static function isValid(mixed $ref): bool
    {
        return is_string($ref) && preg_match('/^[0-9a-f]{64}$/D', $ref) === 1;
    }
}
