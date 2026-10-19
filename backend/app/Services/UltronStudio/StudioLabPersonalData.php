<?php

namespace App\Services\UltronStudio;

/**
 * El filtro de lo que el personal escribe y viaja a un proveedor externo
 * (contrato H4 §A2): RECHAZA, no redacta. Redactar sería admitir que llegan
 * datos personales; los escenarios son sintéticos y se escriben así.
 *
 * Rechaza:
 *  - correos;
 *  - secuencias de 7 o más dígitos, aunque vayan separadas por espacios,
 *    puntos o guiones, también en racha (teléfonos, cédulas). Cuenta
 *    cualquier dígito decimal (`\p{Nd}`), también los de anchura completa;
 *  - el prefijo `+57`;
 *  - los caracteres de formato `\p{Cf}` (bidi, ZWSP, ZWJ, U+061C, U+2060,
 *    U+180E, U+00AD…), el bloque de etiquetas U+E0000–E007F entero y
 *    U+2028/U+2029;
 *  - los controles, salvo el tabulador y los saltos de línea, y el UTF-8
 *    inválido (falla cerrado: una expresión regular no se evalúa sobre él).
 *
 * Deja pasar lo que parece un precio y no un documento: «80 mil», «2x1»,
 * «$80.000» (5 dígitos). Un precio de 7 cifras («1.200.000») se rechaza: no
 * se distingue de una cédula, y en un escenario el precio va con el marcador.
 *
 * PCRE DE PRODUCCIÓN (10.39). Solo categorías generales (`\p{Cf}`, `\p{Nd}`,
 * `\p{L}`) y rangos explícitos, que esa versión admite; ninguna propiedad
 * como `\p{Extended_Pictographic}`, que no compila allí (memoria
 * `pcre-produccion-sin-propiedades-emoji`). Hay que sondear estas
 * expresiones en el servidor tras desplegar. Sin `ext-intl` (producción no
 * la tiene): no se normaliza nada antes de mirar.
 *
 * Los nombres propios no se pueden detectar: el editor lleva un aviso fijo.
 */
final class StudioLabPersonalData
{
    /** El mensaje literal del 422 (contrato §A2). */
    public const MESSAGE = 'Parece un dato personal (correo, teléfono o documento) o un carácter invisible: los escenarios son sintéticos y se envían a un proveedor externo';

    public const REASON_INVALID_UTF8 = 'invalid_utf8';

    public const REASON_INVISIBLE = 'invisible';

    public const REASON_EMAIL = 'email';

    public const REASON_PHONE_PREFIX = 'phone_prefix';

    public const REASON_DIGITS = 'digits';

    /** Formato (bidi, anchura cero, guion blando…), el bloque de etiquetas entero, separadores de línea y párrafo, y controles salvo \t \n \r. */
    public const INVISIBLE_PATTERN = '/[\p{Cf}\x{E0000}-\x{E007F}\x{2028}\x{2029}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u';

    /** Una dirección de correo (`algo@dominio.algo`), con la arroba normal o la de anchura completa. */
    public const EMAIL_PATTERN = '/[\p{L}\p{N}._%+\-]+[@\x{FF20}\x{FE6B}][\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u';

    /** El prefijo de Colombia, con el signo normal o el de anchura completa y espacios en medio. */
    public const PHONE_PREFIX_PATTERN = '/[+\x{FF0B}\x{FE62}]\h*[5\x{FF15}][7\x{FF17}]/u';

    /**
     * 7 dígitos o más, con separadores (espacios, también el NBSP, puntos o
     * guiones) entre ellos, aunque vayan en racha: «300 - 123 - 4567».
     */
    public const DIGITS_PATTERN = '/\p{Nd}(?:[\h.\-\x{2010}-\x{2015}]*\p{Nd}){6,}/u';

    /** ¿Pasa el filtro? */
    public static function passes(string $text): bool
    {
        return self::violation($text) === null;
    }

    /**
     * Por qué no pasa (uno de los `REASON_*`, en este orden de comprobación),
     * o null si pasa. El motivo es para las pruebas y los registros: al
     * personal solo se le enseña {@see MESSAGE}.
     */
    public static function violation(string $text): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return self::REASON_INVALID_UTF8;
        }

        foreach ([
            self::REASON_INVISIBLE => self::INVISIBLE_PATTERN,
            self::REASON_EMAIL => self::EMAIL_PATTERN,
            self::REASON_PHONE_PREFIX => self::PHONE_PREFIX_PATTERN,
            self::REASON_DIGITS => self::DIGITS_PATTERN,
        ] as $motivo => $patron) {
            $r = preg_match($patron, $text);
            // false: la expresión no se pudo evaluar. Falla cerrado.
            if ($r === false) {
                return self::REASON_INVALID_UTF8;
            }
            if ($r === 1) {
                return $motivo;
            }
        }

        return null;
    }

    /**
     * Las rutas (`turns.0.text`, `criteria.2.text`…) de las cadenas de
     * `$value` que no pasan, en el orden en que aparecen. Las claves también
     * cuentan: una clave con un dato personal también viaja.
     *
     * @return list<string>
     */
    public static function scan(mixed $value, string $path = ''): array
    {
        if (is_string($value)) {
            return self::passes($value) ? [] : [$path];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $clave => $item) {
            $ruta = $path === '' ? (string) $clave : "{$path}.{$clave}";
            if (is_string($clave) && ! self::passes($clave)) {
                $out[] = $ruta;

                continue;
            }
            foreach (self::scan($item, $ruta) as $mala) {
                $out[] = $mala;
            }
        }

        return $out;
    }
}
