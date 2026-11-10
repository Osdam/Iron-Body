<?php

namespace App\Services\Outreach\Core;

use InvalidArgumentException;
use JsonException;

/**
 * El JSON CANÓNICO del H9 (contrato H9, D9-5 «Canonicalización», D9-6 y
 * D9-11.3): el mismo contenido da siempre los mismos bytes. PURA: sin
 * configuración, base, red, log ni reloj.
 *
 *  - Las claves de cada objeto se ordenan por bytes (`SORT_STRING`), de forma
 *    recursiva; las listas conservan su orden (en una condición, el orden de
 *    los hijos es parte de la explicación).
 *  - Se codifica sin escapar tildes ni barras y sin espacios.
 *  - Los decimales se RECHAZAN: `0.1` no tiene una única escritura. Los hechos
 *    `decimal` viajan como enteros en centésimas (DF-T4 de los fixtures).
 *
 * Son EXACTAMENTE los bytes de `StudioContentHasher::canonicalJson()` (el
 * contrato manda esa huella; este espacio no puede nombrar el Studio, D9-2):
 * una prueba de paridad lo fija, como `VoiceCanonicalJson` en el H8.
 *
 * {@see normalizeNumber()} es la normalización de los números que llegan del
 * usuario (D9-5 «números normalizados»): `3.0` → 3, `1e1` → 10, `-0.0` → 0.
 */
final class CanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** Nada de lo que se firma o se huella en el H9 pasa de 12 niveles; esto es un tope de seguridad. */
    public const MAX_DEPTH = 32;

    public const NUMBER_OK = 'ok';

    public const NUMBER_NOT_NUMBER = 'not_number';

    public const NUMBER_NOT_FINITE = 'not_finite';

    public const NUMBER_NOT_INTEGER = 'not_integer';

    /**
     * @throws InvalidArgumentException con un decimal, un objeto, un recurso, más de MAX_DEPTH niveles o texto que no es UTF-8
     */
    public static function encode(mixed $value): string
    {
        return self::write($value, '$', 0);
    }

    /** sha256 (64 hexadecimales en minúsculas) del JSON canónico. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    /**
     * Un número del usuario como ENTERO canónico: `[estado, entero|null]`. Los
     * booleanos y el texto no son números; un decimal entero se convierte
     * (`3.0`, `1e1`, `-0.0`); uno con parte fraccionaria o no finito, no.
     *
     * @return array{0: string, 1: int|null}
     */
    public static function normalizeNumber(mixed $value): array
    {
        if (is_int($value)) {
            return [self::NUMBER_OK, $value];
        }
        if (! is_float($value)) {
            return [self::NUMBER_NOT_NUMBER, null];
        }
        if (! is_finite($value)) {
            return [self::NUMBER_NOT_FINITE, null];
        }
        if (floor($value) !== $value || abs($value) > 9.007199254740991E15) {
            return [self::NUMBER_NOT_INTEGER, null];
        }

        return [self::NUMBER_OK, (int) $value];
    }

    private static function write(mixed $value, string $path, int $depth): string
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('El JSON canónico no admite más de '.self::MAX_DEPTH." niveles ({$path}).");
        }
        if ($value === null || is_bool($value) || is_int($value)) {
            return json_encode($value, self::FLAGS);
        }
        if (is_string($value)) {
            return self::string($value, $path);
        }
        if (is_float($value)) {
            throw new InvalidArgumentException("El JSON canónico no admite decimales ({$path}).");
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('El JSON canónico no admite valores de tipo '.get_debug_type($value)." ({$path}).");
        }

        if (array_is_list($value)) {
            $partes = [];
            foreach ($value as $i => $item) {
                $partes[] = self::write($item, "{$path}[{$i}]", $depth + 1);
            }

            return '['.implode(',', $partes).']';
        }

        $claves = array_map('strval', array_keys($value));
        sort($claves, SORT_STRING);
        $partes = [];
        foreach ($claves as $clave) {
            $partes[] = self::string($clave, $path).':'.self::write($value[$clave], "{$path}.{$clave}", $depth + 1);
        }

        return '{'.implode(',', $partes).'}';
    }

    private static function string(string $value, string $path): string
    {
        try {
            return json_encode($value, self::FLAGS);
        } catch (JsonException) {
            throw new InvalidArgumentException("El JSON canónico solo admite texto UTF-8 ({$path}).");
        }
    }
}
