<?php

namespace App\Services\Payments;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Qué periodo de membresía compra un pago, y cómo queda la del socio.
 *
 * La regla vivía copiada en PaymentController y en PaymentMembershipActivator,
 * y usaba la fecha del cobro como fecha de inicio. Eso obligaba a quien pagaba
 * hoy para empezar el lunes a fechar el cobro el lunes: la membresía quedaba
 * bien, pero el dinero aparecía en los reportes el lunes aunque entrara hoy en
 * la caja. Ahora son dos datos: el cobro es de cuando entra el dinero y el
 * inicio es `payments.starts_on`.
 *
 * LAS REGLAS
 *
 *   1. Nunca empieza antes de pagarse. El inicio efectivo es el mayor entre el
 *      pedido y el día del cobro. Un pago pendiente que se confirma tarde no
 *      regala los días que pasaron sin pagar.
 *   2. Renovación anticipada: si el socio sigue vigente el día pedido, el
 *      periodo nuevo se ENCADENA al final del actual. No pierde los días que le
 *      quedaban ni se solapan dos periodos pagados.
 *   3. Si la vigente ya habrá terminado ese día (o no hay), el periodo empieza
 *      el día pedido. Eso incluye dejar un hueco sin cobertura cuando se pide
 *      a propósito empezar después de vencer.
 *
 * El periodo que resulta se congela en el pago (`period_start`/`period_end`):
 * `users` solo guarda el periodo actual y lo sobrescribe, así que sin esto el
 * perfil no podría contar qué cubrió cada pago.
 */
class MembershipPeriod
{
    /** Zona del negocio: «hoy» es hoy en Neiva, no en UTC. */
    public const TZ = Member::BUSINESS_TZ;

    /**
     * Cuánto se puede programar hacia delante. Tres meses cubren «empiezo
     * cuando vuelva de viaje» y dejan fuera los errores de digitación de año.
     */
    public const MAX_START_AHEAD_DAYS = 90;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Motivo por el que no se acepta un inicio pedido desde el CRM, o null.
     *
     * Hacia atrás no: arrancar en el pasado le quita al socio días que pagó,
     * que es justo el error que esto viene a evitar.
     */
    public static function startError(?string $startsOn): ?string
    {
        if ($startsOn === null || $startsOn === '') {
            return null;
        }

        try {
            $fecha = CarbonImmutable::createFromFormat('!Y-m-d', $startsOn, self::TZ);
        } catch (\Throwable) {
            $fecha = false;
        }
        if (! $fecha) {
            return 'La fecha de inicio no es válida.';
        }

        $hoy = self::today();
        if ($fecha->lessThan($hoy)) {
            return 'La membresía no puede empezar antes de hoy: el socio perdería días que pagó.';
        }
        if ($fecha->greaterThan($hoy->addDays(self::MAX_START_AHEAD_DAYS))) {
            return 'Solo se puede programar el inicio hasta '.self::MAX_START_AHEAD_DAYS.' días después de hoy.';
        }

        return null;
    }

    /**
     * Aplica un pago ya cobrado a la membresía de su socio.
     *
     * @return array{start: string, end: string, chained: bool}|null  null si el pago no compra membresía
     */
    public function apply(Payment $payment): ?array
    {
        if (! $payment->plan_id) {
            return null;
        }

        /** @var User|null $user */
        $user = User::find($payment->user_id);
        /** @var Plan|null $plan */
        $plan = Plan::find($payment->plan_id);
        $dias = (int) ($plan?->duration_days ?? 0);

        if (! $user || ! $plan || $dias <= 0) {
            return null;
        }

        $cobro = $this->paidDay($payment);
        $pedido = $payment->starts_on
            ? CarbonImmutable::parse($payment->starts_on->format('Y-m-d'), self::TZ)->startOfDay()
            : $cobro;
        // Regla 1: nunca antes del cobro.
        $inicio = $pedido->lessThan($cobro) ? $cobro : $pedido;

        $finActual = $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, self::TZ)->startOfDay()
            : null;

        // Regla 2: sigue vigente ese día → se encadena.
        $encadena = $finActual !== null && $finActual->greaterThan($inicio);
        $base = $encadena ? $finActual : $inicio;
        $fin = $base->addDays($dias);

        // Regla 3: la vigente no llega a ese día → el periodo nuevo manda.
        if (! $finActual || $finActual->lessThan($inicio) || ! $user->membership_start_date) {
            $user->membership_start_date = $inicio->toDateString();
        }

        $user->membership_end_date = $fin->toDateString();
        $user->plan = $plan->name;
        $user->status = 'active';
        $user->save();

        $payment->forceFill([
            'starts_on' => $inicio->toDateString(),
            'period_start' => $base->toDateString(),
            'period_end' => $fin->toDateString(),
        ])->save();

        return [
            'start' => $base->toDateString(),
            'end' => $fin->toDateString(),
            'chained' => $encadena,
        ];
    }

    /** Día del negocio en que entró el dinero. */
    private function paidDay(Payment $payment): CarbonImmutable
    {
        if (! $payment->paid_at) {
            return self::today();
        }

        return CarbonImmutable::parse($payment->paid_at)->setTimezone(self::TZ)->startOfDay();
    }
}
