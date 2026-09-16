<?php

namespace App\Services\Payments;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
 *   4. Se devuelve lo que se dio. Si el cobro deja de estarlo (anulado,
 *      devuelto), sus días salen de la membresía y los periodos que se le
 *      encadenaron detrás se recalculan — ver `revert()`.
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

    /**
     * Deshace el periodo que un pago le dio a la membresía, al dejar de estar
     * cobrado (anulado, devuelto, revertido a pendiente).
     *
     * POR QUÉ EXISTE. `apply()` era de ida y no de vuelta: anular un cobro le
     * cambiaba el estado en `payments` y nada más, así que los días que había
     * sumado seguían en `users.membership_end_date` para siempre. En producción
     * eso le dio 60 días de un plan de 30 a un socio: se le cobró, se anuló el
     * cobro, se volvió a cobrar, y el segundo periodo se ENCADENÓ al final del
     * primero —que ya no existía— en vez de empezar el día que pagó.
     *
     * CÓMO SE DEVUELVE. El pago congeló el periodo que compró
     * (`period_start`/`period_end`), así que se sabe exactamente cuánto puso.
     * Si es el último, la membresía vuelve a terminar donde él empezó. Si
     * después se encadenaron otros, esos periodos se RECALCULAN corriéndolos
     * hacia atrás con las mismas reglas de `apply()` —incluida la 1: ninguno
     * puede empezar antes del día en que se pagó—, y la membresía termina donde
     * acabe el último. Sin eso, devolver los días le quitaría al socio días que
     * sí pagó.
     *
     * CUÁNDO NO TOCA NADA. Si la cadena de periodos no cuadra con la fecha que
     * hoy tiene el socio (importaciones del sistema anterior, ajustes manuales,
     * pagos viejos sin periodo congelado), se deja igual y se registra: mover a
     * ciegas la fecha de vencimiento de alguien que está entrenando es peor que
     * dejar el caso para revisión.
     *
     * @return array{end: string, previous_end: string, days: int}|null  null si no hubo nada que devolver
     */
    public function revert(Payment $payment): ?array
    {
        if (! $payment->plan_id || ! $payment->period_start || ! $payment->period_end) {
            return null; // nunca activó membresía (o es anterior al periodo congelado)
        }

        /** @var User|null $user */
        $user = User::find($payment->user_id);
        if (! $user || ! $user->membership_end_date) {
            return null;
        }

        $inicio = $this->day($payment->period_start->format('Y-m-d'));
        $fin = $this->day($payment->period_end->format('Y-m-d'));
        $finActual = $this->day($user->membership_end_date);

        if ($fin->lessThanOrEqualTo($inicio) || $finActual->lessThanOrEqualTo($inicio)) {
            return null; // periodo vacío, o la membresía ya no lo incluye
        }

        // Los periodos cobrados que vinieron DESPUÉS de este. Se recalculan en
        // orden: cada uno arranca donde termine el anterior de la cadena.
        $posteriores = $this->chainAfter($payment, $fin);

        // La cadena tiene que explicar la fecha de hoy. Si no llega hasta ella,
        // el vencimiento vigente lo puso otra cosa y no es nuestro para moverlo.
        $cierre = $posteriores->reduce(
            fn (CarbonImmutable $cursor, Payment $p) => $this->day($p->period_end->format('Y-m-d')),
            $fin
        );
        if (! $cierre->equalTo($finActual)) {
            Log::warning('Anulación de pago: la cadena de periodos no explica el vencimiento vigente; se deja intacto', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'period' => [$inicio->toDateString(), $fin->toDateString()],
                'membership_end_date' => $finActual->toDateString(),
                'chain_end' => $cierre->toDateString(),
            ]);

            return null;
        }

        $cursor = $inicio;
        foreach ($posteriores as $posterior) {
            $cursor = $this->reflow($posterior, $cursor);
        }

        $user->membership_end_date = $cursor->toDateString();
        // El socio se queda sin cobertura: el plan deja de ser suyo, pero la
        // ficha NO se toca más — anular un cobro no borra a nadie.
        if ($cursor->lessThanOrEqualTo($this->day($user->membership_start_date ?: $inicio->toDateString()))) {
            $user->membership_start_date = $cursor->toDateString();
        }
        $user->save();

        $payment->forceFill(['period_start' => null, 'period_end' => null])->save();

        return [
            'end' => $cursor->toDateString(),
            'previous_end' => $finActual->toDateString(),
            'days' => (int) $cursor->diffInDays($finActual),
        ];
    }

    /**
     * Pagos COBRADOS del mismo socio cuyo periodo empieza en o después del fin
     * del que se anula, en orden. Son los candidatos a correrse hacia atrás.
     *
     * @return Collection<int, Payment>
     */
    private function chainAfter(Payment $payment, CarbonImmutable $fin): Collection
    {
        return Payment::query()
            ->where('user_id', $payment->user_id)
            ->whereKeyNot($payment->getKey())
            ->whereNotNull('plan_id')
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES)
            ->whereDate('period_start', '>=', $fin->toDateString())
            ->orderBy('period_start')
            ->orderBy('id')
            ->get();
    }

    /**
     * Recoloca un periodo ya cobrado detrás de `$cursor`, conservando los días
     * que se vendieron, y devuelve su nuevo fin.
     */
    private function reflow(Payment $payment, CarbonImmutable $cursor): CarbonImmutable
    {
        $dias = (int) $this->day($payment->period_start->format('Y-m-d'))
            ->diffInDays($this->day($payment->period_end->format('Y-m-d')));

        $cobro = $this->paidDay($payment);
        $pedido = $payment->starts_on
            ? $this->day($payment->starts_on->format('Y-m-d'))
            : $cobro;
        // Regla 1, otra vez: ningún periodo empieza antes de haberse pagado.
        $inicio = $pedido->lessThan($cobro) ? $cobro : $pedido;
        $base = $cursor->greaterThan($inicio) ? $cursor : $inicio;
        $fin = $base->addDays($dias);

        $payment->forceFill([
            'period_start' => $base->toDateString(),
            'period_end' => $fin->toDateString(),
        ])->save();

        return $fin;
    }

    /** Un día del calendario del negocio, sin hora. */
    private function day(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, self::TZ)->startOfDay();
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
