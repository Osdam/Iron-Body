<?php

namespace App\Rules;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza fechas de nacimiento incompatibles con el registro.
 *
 * Tres casos, deliberadamente distintos:
 *
 *  - **Fecha ausente**: NO falla aquí. Es legítima — el dato procede del OCR
 *    del documento y puede no leerse. El flujo la deriva a revisión manual
 *    (`identity_status = needs_manual_review`) en vez de romper el registro.
 *    Lo que sí se garantiza aguas abajo es que una fecha ausente nunca haga
 *    que el miembro quede registrado como adulto por omisión.
 *
 *  - **Fecha en el futuro**: FALLA. Antes se dejaba pasar, y producía una
 *    "edad" de 0 años que, según el operador que la leyera, podía colarse como
 *    válida o marcar la mayoría de edad al revés. Un nacimiento futuro no es
 *    una edad desconocida: es un dato corrupto, y se rechaza como tal.
 *
 *  - **Edad por debajo del mínimo**: FALLA, con el mínimo VIGENTE
 *    ({@see Member::minRegistrationAge()}, configurable por entorno).
 *
 * La edad se calcula por fecha exacta (no por año calendario): si hoy aún no ha
 * cumplido la edad mínima, se bloquea; si ya la cumplió, pasa.
 *
 * Aplica SOLO a registros nuevos: ninguna cuenta existente se revalida.
 */
class MinimumRegistrationAge implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        try {
            $birthDate = CarbonImmutable::parse($value);
        } catch (\Throwable) {
            // Formato no parseable: lo reporta la regla `date`. No duplicamos
            // el mensaje ni bloqueamos por edad con un dato que no existe.
            return;
        }

        if ($birthDate->isFuture()) {
            $fail('La fecha de nacimiento no puede estar en el futuro.');

            return;
        }

        $minimum = Member::minRegistrationAge();

        if ($birthDate->diffInYears(CarbonImmutable::now()) < $minimum) {
            $fail('El registro no está disponible para menores de '.$minimum.' años.');
        }
    }
}
