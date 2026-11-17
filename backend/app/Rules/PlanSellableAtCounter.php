<?php

namespace App\Rules;

use App\Models\Plan;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * El plan que se va a COBRAR EN LA CAJA DE GIMNASIO tiene que poder cobrarse
 * ahí: activo y marcado «Se cobra en recepción» ({@see Plan::isSellableAtCounter()}).
 *
 * Existe porque el mostrador solo rechazaba los archivados: un plan inactivo
 * sin archivar se seguía cobrando por la API, y desactivar un plan no lo sacaba
 * de la venta.
 *
 * Va junto a `exists:plans,id` y {@see PlanNotArchived} en cada alta de cobro
 * del mostrador (cobro, plan a plazos). Con un plan ARCHIVADO no actúa: esa
 * negativa la da PlanNotArchived con su mensaje exacto, y dos errores por la
 * misma causa solo confunden a quien está cobrando.
 *
 * NO mira `sellable`, el precio ni la duración: recepción cobra planes que no
 * se ofrecen fuera (el plan 24, de 1 peso) y planes de cero días.
 */
class PlanSellableAtCounter implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return;
        }

        $plan = Plan::query()->find((int) $value);
        if ($plan === null || $plan->isArchived()) {
            return;
        }

        if (($motivo = self::failure($plan)) !== null) {
            $fail($motivo);
        }
    }

    /**
     * Por qué no se cobra en el mostrador, o null si se puede. Archivado da
     * null: lo explica PlanNotArchived.
     */
    public static function failure(Plan $plan): ?string
    {
        if ($plan->isArchived() || $plan->isSellableAtCounter()) {
            return null;
        }

        return ! $plan->active ? self::inactiveMessage($plan) : self::notAtCounterMessage($plan);
    }

    public static function inactiveMessage(Plan $plan): string
    {
        return 'El plan «'.$plan->name.'» está inactivo: no se vende por ninguna vía. Si hay que volver a cobrarlo, actívalo en Planes.';
    }

    public static function notAtCounterMessage(Plan $plan): string
    {
        return 'El plan «'.$plan->name.'» no se cobra en la Caja de Gimnasio. Si hay que cobrarlo aquí, márcalo en Planes como «Se cobra en recepción».';
    }
}
