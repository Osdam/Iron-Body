<?php

namespace App\Rules;

use App\Models\Plan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El plan que se va a COBRAR no puede estar archivado.
 *
 * Va junto a `exists:plans,id` en cada alta de cobro (mostrador, plan a
 * plazos). Un id que no existe lo rechaza `exists`; esto solo mira el archivo.
 * Un plan archivado sigue existiendo para su historia y no se vende por
 * ninguna vía: si hay que volver a venderlo, se reactiva en Planes.
 */
class PlanNotArchived implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $plan = Plan::query()->find((int) $value);
        if ($plan !== null && $plan->isArchived()) {
            $fail(self::message($plan));
        }
    }

    public static function message(Plan $plan): string
    {
        return 'El plan «'.$plan->name.'» está archivado: ya no se vende. Si hay que volver a venderlo, reactívalo en Planes.';
    }
}
