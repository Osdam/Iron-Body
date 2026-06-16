<?php

namespace App\Rules;

use App\Models\Member;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rechaza fechas de nacimiento que correspondan a una edad por debajo de la
 * edad mínima de registro ([Member::MIN_REGISTRATION_AGE]). La edad se calcula
 * por fecha exacta (no solo por año): si hoy aún no ha cumplido la edad mínima,
 * se bloquea; si ya la cumplió, pasa.
 *
 * Una fecha nula, vacía o no parseable NO falla aquí: la valida la regla `date`
 * y el flujo decide pedir confirmación manual. Esta regla solo actúa cuando hay
 * una fecha confiable que indica edad insuficiente.
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
            // Fecha no parseable: la regla `date` reporta el formato; no bloqueamos por edad.
            return;
        }

        $now = CarbonImmutable::now();
        if ($birthDate->isFuture()) {
            return;
        }

        if ($birthDate->diffInYears($now) < Member::MIN_REGISTRATION_AGE) {
            $fail('El registro no está disponible para menores de '.Member::MIN_REGISTRATION_AGE.' años.');
        }
    }
}
