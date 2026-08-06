<?php

namespace App\Services\Observability;

/**
 * Lo que NUNCA debe llegar a un archivo de log.
 *
 * Los logs del canal se leen a diario para operar, se rotan, se copian y algún
 * día se le muestran a alguien que no debería ver el teléfono completo de un
 * prospecto ni un token de Meta. Esta clase es el único lugar donde se decide
 * qué se enmascara, para que no dependa de que cada `Log::info` se acuerde.
 */
class LogRedactor
{
    /**
     * Claves cuyo valor no se escribe jamás, sin importar de dónde vengan.
     * Se comparan en minúsculas y por coincidencia parcial: `meta_access_token`
     * y `x-hub-signature-256` caen igual que `token` y `signature`.
     */
    private const SECRET_KEY_FRAGMENTS = [
        'token', 'secret', 'password', 'passwd', 'authorization', 'api_key', 'apikey',
        'signature', 'bearer', 'credential', 'private_key', 'session',
    ];

    /** Claves que contienen un teléfono y se enmascaran en vez de borrarse. */
    private const PHONE_KEY_FRAGMENTS = [
        'phone', 'wa_id', 'recipient', 'msisdn', 'from', 'to',
    ];

    /** Profundidad máxima: un payload anidado no debe convertirse en un bucle. */
    private const MAX_DEPTH = 6;

    /**
     * Limpia recursivamente un contexto de log.
     *
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    public static function scrub(array $context, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'max_depth'];
        }

        $clean = [];
        foreach ($context as $key => $value) {
            $lower = mb_strtolower((string) $key);

            if (self::matches($lower, self::SECRET_KEY_FRAGMENTS)) {
                // No se enmascara parcialmente: un secreto parcial sigue siendo pista.
                $clean[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::scrub($value, $depth + 1);

                continue;
            }

            if (self::matches($lower, self::PHONE_KEY_FRAGMENTS) && is_scalar($value)) {
                $clean[$key] = self::maskPhone((string) $value);

                continue;
            }

            $clean[$key] = self::normalizeScalar($value);
        }

        return $clean;
    }

    /**
     * Enmascara un teléfono conservando lo justo para reconocer una conversación
     * en soporte: indicativo y los últimos cuatro dígitos. `573143455483` →
     * `57******5483`. Un valor que no parece teléfono se devuelve intacto.
     */
    public static function maskPhone(string $value): string
    {
        $digits = preg_replace('/[^0-9]/', '', $value) ?? '';
        if (strlen($digits) < 8) {
            return $value; // no es un teléfono: un id corto, un estado, etc.
        }

        $prefix = substr($digits, 0, 2);
        $suffix = substr($digits, -4);

        return $prefix.str_repeat('*', max(0, strlen($digits) - 6)).$suffix;
    }

    /**
     * Acota texto libre: un mensaje de prospecto puede ser larguísimo y no
     * tiene por qué vivir entero en el log.
     */
    public static function preview(?string $text, int $limit = 160): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $clean = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen($clean) > $limit ? mb_substr($clean, 0, $limit).'…' : $clean;
    }

    private static function matches(string $key, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_object($value)) {
            return class_basename($value);
        }

        return $value;
    }
}
