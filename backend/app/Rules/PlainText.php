<?php

namespace App\Rules;

use App\Support\Caja\UntrustedText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un nombre (de equipo, de impresora, de versión) que se guarda y se enseña:
 * UTF-8 válido, sin caracteres de control ni invisibles.
 *
 * FALLA CERRADA. Sustituye a `not_regex:/[controles]/u`, que con UTF-8
 * inválido daba por buena la entrada (preg_match devuelve false y `not_regex`
 * lo lee como «no coincide»). Revisión de seguridad M-1.
 */
class PlainText implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null) {
            return;
        }

        if (! is_string($value) || ! UntrustedText::isPlain($value)) {
            $fail('El texto contiene caracteres no permitidos.');
        }
    }
}
