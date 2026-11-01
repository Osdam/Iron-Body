<?php

namespace App\Rules;

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
 * LA CLASE COMPLETA (H7, AS-5 del H5): la de `lab-invisibles` del
 * laboratorio ({@see InvisibleCharacters}): formato `\p{Cf}`, etiquetas
 * U+E0000–U+E007F, U+2028/U+2029 y controles; menos los selectores de
 * variación y el ZWJ que une un emoji compuesto, que forman emojis
 * legítimos. Antes solo miraba anchura cero, bidi y BOM, y dejaba pasar el
 * guion blando, U+2060, U+061C, U+180E o las etiquetas hasta cadenas que no
 * se pueden editar ni borrar (`ultron_studio_publications.reason`).
 */
class PlainText implements ValidationRule
{
    /** Lo que se enseña con UTF-8 inválido o un valor que no es texto. */
    public const MESSAGE_INVALID = 'El texto contiene caracteres no permitidos.';

    public function __construct(private readonly bool $multiline = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail(self::MESSAGE_INVALID);

            return;
        }

        $motivo = InvisibleCharacters::violation($value, $this->multiline);
        if ($motivo === null) {
            return;
        }

        $fail(self::message($motivo, $this->multiline));
    }

    /**
     * El 422, con el carácter que lo provoca para que se pueda localizar (un
     * invisible no se ve en el campo). Nunca repite el texto.
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
