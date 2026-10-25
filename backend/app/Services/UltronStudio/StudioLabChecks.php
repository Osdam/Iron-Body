<?php

namespace App\Services\UltronStudio;

/**
 * Las pruebas deterministas DEL STUDIO sobre la respuesta del actor (contrato
 * H4 §A4). Puras: ni base, ni configuración, ni reloj. Las del motor van
 * aparte, en {@see StudioLabEngineChecks}, que es el único fichero del
 * laboratorio que nombra el motor.
 *
 * | id                  | nivel    | cuándo                                                          |
 * |---------------------|----------|-----------------------------------------------------------------|
 * | `lab-invisibles`    | blocking | lleva caracteres de control o invisibles (Enmiendas 4.2 y 6.2)  |
 * | `lab-vacia`         | blocking | la respuesta queda vacía tras recortarla                        |
 * | `lab-longitud`      | warning  | tiene más caracteres que `persona.maxChars` del objetivo        |
 * | `lab-emojis`        | warning  | lleva algún emoji y `persona.emojis` es `false`                 |
 * | `lab-regla-<S-NNN>` | warning  | contiene una frase de una afirmación `forbid text contains`     |
 *
 * `lab-regla-<S-NNN>` es la verificación «lab» que G dejó anunciada: las
 * frases de las reglas del Studio solo se pueden comprobar sobre un texto.
 * Es un AVISO, no un bloqueo (decisión 1 de G: el motor no aplica las reglas
 * del Studio). La respuesta se canonicaliza como las frases de G (espacios
 * colapsados y minúsculas solo en A–Z y ÁÉÍÓÚÜÑ) y la afirmación solo cuenta
 * si su «si» se da en el objetivo (una variable que el objetivo no tiene, o un
 * «si» que no se cumple, no se comprueba, como en G).
 *
 * Los emojis se cuentan con RANGOS EXPLÍCITOS, los mismos que la guardia de
 * estilo del motor (una prueba los compara): el PCRE de producción no conoce
 * `\p{Extended_Pictographic}`.
 *
 * `lab-invisibles` va PRIMERO (Enmienda 4.2): un control bidi o un espacio de
 * anchura cero esconde el texto a las demás pruebas y a las guardas del motor
 * («des\u{200B}cuento» no es «descuento»), y quien emite el veredicto lo
 * vería distinto de lo evaluado (`\u{202E}000.08$\u{202C}` se pinta
 * «$80.000»). La respuesta se guarda tal cual y el CRM la interpola: el
 * hallazgo bloqueante es lo que impide que pase por limpia. La clase es la
 * del filtro de entrada, menos el ZWJ (Enmienda 6.2). Este fichero no lleva
 * ningún invisible literal.
 *
 * Cada hallazgo: `{id, source: studio, level, title, detail, quote?}`, con
 * `quote` de 120 caracteres como mucho, sacado del texto del actor.
 */
final class StudioLabChecks
{
    public const SOURCE = 'studio';

    public const LEVEL_BLOCKING = 'blocking';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_INFO = 'info';

    public const LEVELS = [self::LEVEL_BLOCKING, self::LEVEL_WARNING, self::LEVEL_INFO];

    public const ID_INVISIBLE = 'lab-invisibles';

    public const ID_EMPTY = 'lab-vacia';

    public const ID_LENGTH = 'lab-longitud';

    public const ID_EMOJIS = 'lab-emojis';

    public const ID_RULE_PREFIX = 'lab-regla-';

    public const QUOTE_MAX = 120;

    /**
     * Pictogramas por rangos: los de `ComposerStyleGuard::PICTO` del motor,
     * copiados porque este fichero no puede nombrar el motor. Una prueba los
     * compara por reflexión para que no deriven.
     */
    public const PICTO = '[\x{1F000}-\x{1F1E5}\x{1F200}-\x{1F3FA}\x{1F400}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}\x{203C}\x{2049}\x{2139}\x{24C2}]';

    /**
     * Un emoji como lo cuenta la guardia de estilo: una bandera (dos
     * indicadores regionales) o un pictograma con su selector y su tono, y lo
     * que se le una con ZWJ.
     */
    public const EMOJI_PATTERN = '/(?:[\x{1F1E6}-\x{1F1FF}]{2}|'.self::PICTO.'\x{FE0F}?(?:[\x{1F3FB}-\x{1F3FF}])?(?:\x{200D}'.self::PICTO.'\x{FE0F}?(?:[\x{1F3FB}-\x{1F3FF}])?)*)/u';

    /**
     * Lo que dispara `lab-invisibles` (Enmienda 6.2, I-2 del reviewer): la
     * MISMA clase que el filtro de entrada
     * ({@see StudioLabPersonalData::INVISIBLE_CLASS}: `\p{Cf}` —bidi, LRM/RLM,
     * anchura cero, guion suave, separadores invisibles como U+2063…—, las
     * etiquetas U+E0000–U+E007F, U+2028/U+2029 y los controles C0/C1 salvo
     * \t \n \r) MENOS el ZWJ U+200D, que usan los emojis compuestos (U+1F3CB
     * U+FE0F U+200D U+2640). La lista explícita de la Enmienda 4.2 dejaba
     * pasar, p. ej., U+2063 o U+0008 en medio de «descuento». `\p{Cf}` compila
     * en el PCRE 10.39 de producción (sonda de la Enmienda 3.5).
     */
    public const INVISIBLE_PATTERN = '/(?!\x{200D})'.StudioLabPersonalData::INVISIBLE_CLASS.'/u';

    /**
     * Los espacios de `String.prototype.trim()`, como `StudioRuleCoherence`
     * (G): recortan la respuesta y se colapsan dentro de ella.
     */
    public const JS_TRIM = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Las únicas mayúsculas que pasan a minúsculas, como en G. */
    public const LOWER = [
        'A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd', 'E' => 'e', 'F' => 'f', 'G' => 'g', 'H' => 'h', 'I' => 'i',
        'J' => 'j', 'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n', 'O' => 'o', 'P' => 'p', 'Q' => 'q', 'R' => 'r',
        'S' => 's', 'T' => 't', 'U' => 'u', 'V' => 'v', 'W' => 'w', 'X' => 'x', 'Y' => 'y', 'Z' => 'z',
        'Á' => 'á', 'É' => 'é', 'Í' => 'í', 'Ó' => 'ó', 'Ú' => 'ú', 'Ü' => 'ü', 'Ñ' => 'ñ',
    ];

    /**
     * Las pruebas del Studio sobre `$reply`, en el orden de la tabla. El
     * objetivo es la instantánea de la ejecución: `{configs, edgeIds, rules}`
     * (las reglas, las que recibió el actor).
     *
     * @param  array<string,mixed>  $target
     * @return list<array{id:string,source:string,level:string,title:string,detail:string,quote?:string}>
     */
    public static function check(string $reply, array $target): array
    {
        $configs = is_array($target['configs'] ?? null) ? $target['configs'] : [];
        $persona = is_array($configs['persona'] ?? null) ? $configs['persona'] : [];
        $limpia = self::trim($reply);
        $out = [];

        // Sin cita: repetiría los invisibles en lo que se pinta.
        if (preg_match(self::INVISIBLE_PATTERN, $reply) === 1) {
            $out[] = self::finding(self::ID_INVISIBLE, self::LEVEL_BLOCKING, 'Caracteres invisibles en la respuesta', 'La respuesta contiene caracteres de control o invisibles que pueden ocultar texto a los filtros');
        }

        if ($limpia === '') {
            $out[] = self::finding(self::ID_EMPTY, self::LEVEL_BLOCKING, 'La respuesta está vacía', 'El actor no escribió nada: no hay mensaje que enviar.');
        }

        $maximo = self::integer($persona['maxChars'] ?? null);
        $largo = mb_strlen($limpia, 'UTF-8');
        if ($maximo !== null && $largo > $maximo) {
            $out[] = self::finding(self::ID_LENGTH, self::LEVEL_WARNING, 'La respuesta es más larga de lo que permite la versión', "Tiene {$largo} caracteres y la longitud máxima es {$maximo}.");
        }

        if (($persona['emojis'] ?? null) === false && preg_match(self::EMOJI_PATTERN, $reply, $m) === 1) {
            $out[] = self::finding(self::ID_EMOJIS, self::LEVEL_WARNING, 'La respuesta lleva emojis y la versión no los usa', '«Usa emojis» es «no» en la versión.', $m[0]);
        }

        $canonica = self::canonical($reply);
        $aristas = array_values(array_filter(is_array($target['edgeIds'] ?? null) ? $target['edgeIds'] : [], 'is_string'));
        foreach (is_array($target['rules'] ?? null) ? $target['rules'] : [] as $regla) {
            if (! is_array($regla) || ! is_string($regla['code'] ?? null) || ! str_starts_with($regla['code'], 'S-') || ! is_array($regla['conditions'] ?? null)) {
                continue;
            }
            if (! self::whenHolds($regla['conditions']['when'] ?? [], $configs, $aristas)) {
                continue;
            }
            $halladas = [];
            foreach (is_array($regla['conditions']['then'] ?? null) ? $regla['conditions']['then'] : [] as $a) {
                if (! is_array($a) || ($a['kind'] ?? null) !== 'forbid' || ($a['target'] ?? null) !== 'text' || ($a['op'] ?? null) !== 'contains') {
                    continue;
                }
                foreach (is_array($a['phrases'] ?? null) ? $a['phrases'] : [] as $frase) {
                    if (is_string($frase) && $frase !== '' && str_contains($canonica, $frase) && ! in_array($frase, $halladas, true)) {
                        $halladas[] = $frase;
                    }
                }
            }
            if ($halladas !== []) {
                $lista = implode(', ', array_map(static fn (string $f): string => "«{$f}»", $halladas));
                $out[] = self::finding(
                    self::ID_RULE_PREFIX.$regla['code'],
                    self::LEVEL_WARNING,
                    "La respuesta contiene una frase que prohíbe {$regla['code']}",
                    "{$regla['code']} no deja que el texto contenga {$lista}. Es un aviso: el motor no aplica las reglas del Studio.",
                    $halladas[0],
                );
            }
        }

        return $out;
    }

    /**
     * La respuesta canonicalizada como una frase de G: recortada, espacios
     * colapsados a uno y minúsculas solo en A–Z y ÁÉÍÓÚÜÑ.
     */
    public static function canonical(string $text): string
    {
        return strtr((string) preg_replace('/['.self::JS_TRIM.']+/u', ' ', self::trim($text)), self::LOWER);
    }

    /**
     * Un hallazgo con su forma del contrato; `quote`, solo si hay cita.
     *
     * @return array{id:string,source:string,level:string,title:string,detail:string,quote?:string}
     */
    public static function finding(string $id, string $level, string $title, string $detail, ?string $quote = null, string $source = self::SOURCE): array
    {
        $out = ['id' => $id, 'source' => $source, 'level' => $level, 'title' => $title, 'detail' => $detail];
        if ($quote !== null && $quote !== '') {
            $out['quote'] = self::quote($quote);
        }

        return $out;
    }

    /** Una cita de 120 caracteres como mucho. */
    public static function quote(string $text): string
    {
        return mb_strlen($text, 'UTF-8') > self::QUOTE_MAX ? mb_substr($text, 0, self::QUOTE_MAX - 1, 'UTF-8').'…' : $text;
    }

    private static function trim(string $text): string
    {
        return (string) preg_replace('/^['.self::JS_TRIM.']+|['.self::JS_TRIM.']+$/u', '', $text);
    }

    private static function integer(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor;
        }
        if (is_float($valor) && is_finite($valor) && floor($valor) === $valor) {
            return (int) $valor;
        }

        return null;
    }

    /**
     * ¿Se da el «si» de una regla en el objetivo? Las condiciones llegan en la
     * forma canónica de G. Una variable que el objetivo no tiene, o una forma
     * que no se entiende, no se da: lo que no se puede comprobar no avisa.
     *
     * @param  list<string>  $aristas
     * @param  array<string,mixed>  $configs
     */
    private static function whenHolds(mixed $when, array $configs, array $aristas): bool
    {
        if (! is_array($when)) {
            return false;
        }
        foreach ($when as $p) {
            if (! is_array($p) || ! self::predicateHolds($p, $configs, $aristas)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $p
     * @param  array<string,mixed>  $configs
     * @param  list<string>  $aristas
     */
    private static function predicateHolds(array $p, array $configs, array $aristas): bool
    {
        if (($p['target'] ?? null) === 'edge') {
            $presente = in_array($p['edge'] ?? null, $aristas, true);

            return match ($p['op'] ?? null) {
                'present' => $presente,
                'absent' => ! $presente,
                default => false,
            };
        }
        if (($p['target'] ?? null) !== 'config') {
            return false;
        }

        $capa = $configs[$p['layer'] ?? ''] ?? null;
        if (! is_array($capa) || ! is_string($p['key'] ?? null) || ! array_key_exists($p['key'], $capa)) {
            return false;
        }
        $actual = $capa[$p['key']];
        if (is_float($actual) && is_finite($actual) && floor($actual) === $actual) {
            $actual = (int) $actual;
        }

        return match ($p['op'] ?? null) {
            'eq' => $actual === ($p['value'] ?? null),
            'in' => in_array($actual, (array) ($p['values'] ?? []), true),
            'not_in' => ! in_array($actual, (array) ($p['values'] ?? []), true),
            'lte' => is_int($actual) && is_int($p['value'] ?? null) && $actual <= $p['value'],
            'gte' => is_int($actual) && is_int($p['value'] ?? null) && $actual >= $p['value'],
            'contains' => is_array($actual) && in_array($p['value'] ?? null, $actual, true),
            'not_contains' => is_array($actual) && ! in_array($p['value'] ?? null, $actual, true),
            default => false,
        };
    }
}
