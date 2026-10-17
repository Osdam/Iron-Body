<?php

namespace App\Support\Caja;

use App\Rules\PlainText;

/**
 * Texto que llega de fuera (el servicio local de impresión, la pantalla de
 * equipos) y acaba guardado y enseñado en el CRM.
 *
 * Dos riesgos distintos, dos defensas:
 *
 *  · UTF-8 INVÁLIDO. Una expresión con `/u` sobre bytes inválidos devuelve
 *    `false`, y `not_regex` lo lee como «no coincide»: el filtro de controles
 *    se saltaba con un cuerpo form-urlencoded (revisión de seguridad M-1). En
 *    PostgreSQL esos bytes revientan el UPDATE (22021) y la consulta, con sus
 *    bytes crudos, acaba en laravel.log. {@see allUtf8()} lo corta en la puerta.
 *  · CONTROLES E INVISIBLES. C0/C1/DEL mueven la terminal de quien lee el log
 *    o descolocan la pantalla; los de dirección (RLO…) y los de anchura cero
 *    hacen que un texto se lea distinto de lo que es. {@see sanitize()} los
 *    quita antes de guardar (M-2) y {@see PlainText} los rechaza en nombres.
 */
final class UntrustedText
{
    /** C0, DEL y C1. */
    public const CONTROLS = '\x{0000}-\x{001F}\x{007F}-\x{009F}';

    /** Anchura cero, marcas y empotrados de dirección, aislados y BOM. */
    public const INVISIBLE = '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}';

    /** ¿Todas las cadenas —claves incluidas— son UTF-8 válido? */
    public static function allUtf8(mixed $input): bool
    {
        if (is_string($input)) {
            return mb_check_encoding($input, 'UTF-8');
        }

        if (is_array($input)) {
            foreach ($input as $clave => $valor) {
                if (is_string($clave) && ! mb_check_encoding($clave, 'UTF-8')) {
                    return false;
                }
                if (! self::allUtf8($valor)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** UTF-8 válido, sin controles ni invisibles. */
    public static function isPlain(string $texto): bool
    {
        return mb_check_encoding($texto, 'UTF-8')
            && preg_match('/['.self::CONTROLS.self::INVISIBLE.']/u', $texto) === 0;
    }

    /**
     * Lo que se GUARDA de un texto libre: controles → espacio, invisibles
     * fuera, espacios colapsados, recortado a `$max` puntos de código. Un
     * mensaje raro o largo no puede impedir que quede constancia del resultado,
     * así que se limpia en vez de rechazarse. Vacío → null.
     */
    public static function sanitize(?string $texto, int $max): ?string
    {
        if ($texto === null) {
            return null;
        }

        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'UTF-8');
        }

        $texto = (string) preg_replace('/['.self::CONTROLS.']/u', ' ', $texto);
        $texto = (string) preg_replace('/['.self::INVISIBLE.']/u', '', $texto);
        $texto = trim((string) preg_replace('/\s{2,}/u', ' ', $texto));
        $texto = trim(mb_substr($texto, 0, $max));

        return $texto === '' ? null : $texto;
    }
}
