<?php

namespace App\Support\Text;

/**
 * Los caracteres invisibles y de control que no se guardan en un texto que
 * una persona va a leer después (AS-5 del H5; contrato H7, paquete SEC7).
 *
 * LA CLASE ES LA DEL LABORATORIO, sin inventar otra. {@see INVISIBLE_CLASS} es,
 * carácter por carácter, `StudioLabPersonalData::INVISIBLE_CLASS` (la de
 * `lab-invisibles`), y una prueba las compara para que no deriven:
 *  - `\p{Cf}`, los caracteres de formato: bidi (U+202A–U+202E, U+2066–U+2069,
 *    LRM/RLM, U+061C), anchura cero (U+200B, U+200C), guion blando U+00AD,
 *    U+180E, U+2060–U+2064, U+206A–U+206F, BOM U+FEFF, U+FFF9–U+FFFB…;
 *  - el bloque de etiquetas U+E0000–U+E007F entero: el «contrabando ASCII»
 *    que un modelo lee como texto y una persona no ve;
 *  - U+2028 y U+2029, separadores de línea y de párrafo;
 *  - los controles C0, DEL y C1, salvo el tabulador y los saltos de línea.
 *
 * MENOS LO QUE FORMA UN EMOJI:
 *  - Los selectores de variación U+FE00–U+FE0F no están en la clase (son
 *    `Mn`, no `Cf`): el corazón rojo (U+2764 U+FE0F) pasa siempre.
 *  - El ZWJ U+200D sí es `Cf`, y solo pasa DENTRO de un emoji compuesto:
 *    precedido por un pictograma, por U+FE0F o por un tono de piel
 *    (U+1F3FB–U+1F3FF), y seguido de un pictograma (la familia U+1F468
 *    U+200D U+1F469 U+200D U+1F467; la levantadora U+1F3CB U+FE0F U+200D
 *    U+2640 U+FE0F). Un ZWJ suelto no tiene uso legítimo en este CRM y es un
 *    invisible como cualquier otro: «des» + ZWJ + «cuento» no es «descuento».
 *    Antes de H7 `PlainText` lo rechazaba siempre, y fuera de un emoji lo
 *    sigue rechazando. Los pictogramas son los de `StudioLabChecks::PICTO`
 *    (los de la guardia de estilo del motor), copiados con su prueba de
 *    deriva porque este fichero no depende del Studio.
 *
 * LO QUE NO CUBRE, porque la clase del laboratorio tampoco: los rellenos
 * coreanos (U+115F, U+1160, U+3164, U+FFA0), U+034F, U+17B4–U+17B5,
 * U+180B–U+180F, el suplemento de selectores U+E0100–U+E01EF y los espacios
 * visibles (NBSP, U+202F…). Ampliarla es cambiar las dos clases a la vez
 * (informe SEC7 del H7).
 *
 * PCRE DE PRODUCCIÓN (10.39): solo `\p{Cf}`, rangos explícitos y miradas
 * atrás de un carácter por rama, que esa versión admite; sin `ext-intl`. UTF-8
 * inválido: falla cerrado (una expresión con `/u` no se evalúa sobre él).
 *
 * Este fichero no lleva ningún invisible literal.
 */
final class InvisibleCharacters
{
    /** El motivo de {@see violation()} para un texto que no es UTF-8 válido. */
    public const INVALID_UTF8 = 'invalid_utf8';

    /** La clase de `lab-invisibles`, idéntica a `StudioLabPersonalData::INVISIBLE_CLASS`. */
    public const INVISIBLE_CLASS = '[\p{Cf}\x{E0000}-\x{E007F}\x{2028}\x{2029}\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]';

    /** Pictogramas por rangos, idénticos a `StudioLabChecks::PICTO`. */
    public const PICTO = '[\x{1F000}-\x{1F1E5}\x{1F200}-\x{1F3FA}\x{1F400}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}\x{203C}\x{2049}\x{2139}\x{24C2}]';

    /** Lo que, además de un pictograma, puede ir justo antes del ZWJ de un emoji. */
    public const EMOJI_BEFORE_ZWJ = '[\x{FE0F}\x{1F3FB}-\x{1F3FF}]';

    /**
     * Un carácter prohibido en un texto de varias líneas: uno de la clase que
     * no sea el ZWJ, o un ZWJ que no une dos piezas de un emoji.
     */
    public const FORBIDDEN = '(?:(?!\x{200D})'.self::INVISIBLE_CLASS
        .'|(?<!'.self::PICTO.'|'.self::EMOJI_BEFORE_ZWJ.')\x{200D}'
        .'|\x{200D}(?!'.self::PICTO.'))';

    /** Lo mismo en una sola línea: también el tabulador y los saltos de línea. */
    public const FORBIDDEN_SINGLE_LINE = '(?:'.self::FORBIDDEN.'|[\x{0009}\x{000A}\x{000D}])';

    /** Tope de puntos de código distintos que {@see clean()} devuelve (para el log). */
    public const CODEPOINTS_MAX = 16;

    /** ¿UTF-8 válido y sin ningún carácter prohibido? */
    public static function isPlain(string $text, bool $multiline = false): bool
    {
        return self::violation($text, $multiline) === null;
    }

    /**
     * Por qué no es texto plano: el primer carácter prohibido como `U+XXXX`,
     * {@see INVALID_UTF8}, o null si lo es.
     */
    public static function violation(string $text, bool $multiline = false): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return self::INVALID_UTF8;
        }

        $r = preg_match(self::pattern($multiline), $text, $m);
        if ($r === false) {
            // La expresión no se pudo evaluar: falla cerrado.
            return self::INVALID_UTF8;
        }

        return $r === 1 ? self::codepoint($m[0]) : null;
    }

    /**
     * Lo que se GUARDA de un texto que no se puede rechazar (la API interna
     * que usa n8n: un rechazo rompería el flujo). Con la semántica de varias
     * líneas, porque el tabulador y los saltos no están en la clase:
     *  - UTF-8 inválido → cada secuencia mala pasa a `?`;
     *  - U+2028 y U+2029 → salto de línea (es lo que significan);
     *  - controles C0, DEL y C1 → espacio («controles → espacio», como
     *    `UntrustedText::sanitize()`);
     *  - el resto (formato, etiquetas, ZWJ suelto) → fuera.
     * Si quitó algo, recorta los bordes que hayan quedado al descubierto. Si
     * no había nada que quitar, devuelve el texto tal cual.
     *
     * @return array{text:string,removed:int,codepoints:list<string>,invalidUtf8:bool}
     */
    public static function clean(string $text): array
    {
        $invalido = ! mb_check_encoding($text, 'UTF-8');
        if ($invalido) {
            $text = mb_scrub($text, 'UTF-8');
        }

        $vistos = [];
        $limpio = preg_replace_callback('/'.self::FORBIDDEN.'/u', static function (array $m) use (&$vistos): string {
            $vistos[self::codepoint($m[0])] = true;

            return match (true) {
                $m[0] === "\u{2028}", $m[0] === "\u{2029}" => "\n",
                preg_match('/^[\x{0000}-\x{001F}\x{007F}-\x{009F}]$/u', $m[0]) === 1 => ' ',
                default => '',
            };
        }, $text, -1, $quitados);

        // Tras `mb_scrub` el texto es UTF-8 válido; si aun así la expresión
        // fallara, no se guarda nada que no se haya podido mirar.
        if ($limpio === null) {
            return ['text' => '', 'removed' => mb_strlen($text, 'UTF-8'), 'codepoints' => [], 'invalidUtf8' => $invalido];
        }

        if ($quitados === 0 && ! $invalido) {
            return ['text' => $text, 'removed' => 0, 'codepoints' => [], 'invalidUtf8' => false];
        }

        $puntos = array_keys($vistos);
        sort($puntos, SORT_STRING);

        return [
            'text' => $quitados > 0 ? trim($limpio) : $limpio,
            'removed' => $quitados,
            'codepoints' => array_slice($puntos, 0, self::CODEPOINTS_MAX),
            'invalidUtf8' => $invalido,
        ];
    }

    private static function pattern(bool $multiline): string
    {
        return '/'.($multiline ? self::FORBIDDEN : self::FORBIDDEN_SINGLE_LINE).'/u';
    }

    private static function codepoint(string $char): string
    {
        return sprintf('U+%04X', mb_ord($char, 'UTF-8'));
    }
}
