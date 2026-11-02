<?php

namespace App\Rules;

use App\Support\Caja\UntrustedText;
use App\Support\Text\InvisibleCharacters;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un nombre (de equipo, de impresora, de versión) que se guarda y se enseña:
 * UTF-8 válido, sin caracteres de control ni invisibles.
 *
 * `new PlainText(multiline: true)`: lo mismo para un texto de varias líneas
 * (notas, motivos): admite tabulador y saltos de línea, nada más.
 *
 * FALLA CERRADA. Sustituye a `not_regex:/[controles]/u`, que con UTF-8
 * inválido daba por buena la entrada (preg_match devuelve false y `not_regex`
 * lo lee como «no coincide»). Revisión de seguridad M-1.
 *
 * DOS LISTAS (H7; M-1 de la revisión final del H7):
 *  - POR DEFECTO, LA DE SIEMPRE ({@see UntrustedText::isPlain()}): controles
 *    C0, DEL y C1; anchura cero U+200B–U+200F (el ZWJ también, siempre);
 *    bidi U+202A–U+202E y U+2066–U+2069; y BOM. Con su mensaje de siempre
 *    ({@see MESSAGE_INVALID}). Es la de la agenda de valoraciones y la Caja,
 *    en producción desde antes del H7: ampliarla allí bloquearía reenviar una
 *    nota ya guardada con un guion blando o un U+2028, y el CRM enseña en esos
 *    formularios su propio mensaje, no el del servidor. Ampliarla fuera del
 *    Studio es una decisión aparte (informe SEC7 del H7, addendum M-1).
 *  - `strict: true`, LA CLASE COMPLETA (H7, AS-5 del H5): la de
 *    `lab-invisibles` del laboratorio ({@see InvisibleCharacters}): formato
 *    `\p{Cf}`, etiquetas U+E0000–U+E007F, U+2028/U+2029 y controles; menos
 *    los selectores de variación y el ZWJ que une un emoji compuesto, que
 *    forman emojis legítimos. El 422 nombra el carácter ({@see message()}).
 *    La usan el Studio y su laboratorio, donde un texto acaba en cadenas que
 *    no se pueden editar ni borrar (`ultron_studio_publications.reason`).
 */
class PlainText implements ValidationRule
{
    /** Lo que se enseña con UTF-8 inválido, un valor que no es texto o, en la lista de siempre, cualquier carácter prohibido. */
    public const MESSAGE_INVALID = 'El texto contiene caracteres no permitidos.';

    public function __construct(
        private readonly bool $multiline = false,
        private readonly bool $strict = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail(self::MESSAGE_INVALID);

            return;
        }

        if (! $this->strict) {
            if (! UntrustedText::isPlain($value, $this->multiline)) {
                $fail(self::MESSAGE_INVALID);
            }

            return;
        }

        $motivo = InvisibleCharacters::violation($value, $this->multiline);
        if ($motivo === null) {
            return;
        }

        $fail(self::message($motivo, $this->multiline));
    }

    /**
     * El 422 de `strict: true`, con el carácter que lo provoca para que se
     * pueda localizar (un invisible no se ve en el campo). Nunca repite el texto.
     */
    public static function message(string $motivo, bool $multiline = false): string
    {
        if ($motivo === InvisibleCharacters::INVALID_UTF8) {
            return self::MESSAGE_INVALID;
        }

        if (! $multiline && in_array($motivo, ['U+0009', 'U+000A', 'U+000D'], true)) {
            return "El texto tiene que ir en una sola línea, sin tabuladores ni saltos de línea ({$motivo}).";
        }

        return "El texto contiene un carácter invisible o de control ({$motivo}) que no se puede guardar. Suele colarse al copiar y pegar: escríbelo de nuevo sin pegarlo.";
    }
}
