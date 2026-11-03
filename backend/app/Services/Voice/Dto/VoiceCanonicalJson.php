<?php

namespace App\Services\Voice\Dto;

use InvalidArgumentException;

/**
 * El JSON CANÓNICO de la voz (contrato H8, D8-6 y D8-8): el mismo contenido da
 * siempre los mismos bytes. Pura: sin configuración, reloj ni red.
 *
 *  - Las claves de cada objeto se ordenan por bytes (`SORT_STRING`), de forma
 *    recursiva; las listas conservan su orden.
 *  - Se codifica sin escapar tildes ni barras.
 *  - Los decimales se rechazan: `0.1` no tiene una única escritura y una
 *    huella que dependa de cómo se imprime un float no prueba nada.
 *
 * Son EXACTAMENTE las reglas de la huella del Studio (`StudioContentHasher`,
 * que este espacio neutro no puede nombrar): una prueba de paridad fija que
 * las dos dan los mismos bytes, para que quien compila (S8) y quien despliega
 * (B8) calculen la misma huella sin compartir código.
 */
final class VoiceCanonicalJson
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /** La profundidad máxima de lo que se firma: nada de la voz pasa de 12 niveles. */
    public const MAX_DEPTH = 32;

    /**
     * @throws InvalidArgumentException con un decimal, un objeto, un recurso o más de MAX_DEPTH niveles
     */
    public static function encode(mixed $value): string
    {
        return json_encode(self::canonicalize($value, '$', 0), self::FLAGS);
    }

    /** sha256 (64 hexadecimales en minúsculas) del JSON canónico. */
    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function canonicalize(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('La huella no admite más de '.self::MAX_DEPTH." niveles ({$path}).");
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return $value;
        }
        if (is_float($value)) {
            throw new InvalidArgumentException("La huella no admite decimales ({$path}).");
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('La huella no admite valores de tipo '.get_debug_type($value)." ({$path}).");
        }

        if (array_is_list($value)) {
            $lista = [];
            foreach ($value as $i => $item) {
                $lista[] = self::canonicalize($item, "{$path}[{$i}]", $depth + 1);
            }

            return $lista;
        }

        $objeto = [];
        foreach ($value as $clave => $item) {
            $objeto[$clave] = self::canonicalize($item, "{$path}.{$clave}", $depth + 1);
        }
        ksort($objeto, SORT_STRING);

        return $objeto;
    }
}
