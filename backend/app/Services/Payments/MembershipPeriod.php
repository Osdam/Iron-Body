<?php

namespace App\Services\Payments;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipFreeze;
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
 *   1. El inicio es el que se pide, y sin pedido el del día del cobro. Una
 *      fecha hacia atrás se respeta —«pagó el día 1 y lo registramos el 8»—
 *      dentro de la ventana que fija {@see startError()}.
 *
 *      CON UNA EXCEPCIÓN: un pago que estuvo PENDIENTE y se confirmó días
 *      después no puede empezar antes de que entrara el dinero. Ahí la fecha
 *      pedida se escribió cuando aún no había pagado, y respetarla le regalaría
 *      los días que pasó sin pagar. Se distinguen por sus propias marcas: en un
 *      cobro registrado con el dinero delante, cobro y creación son del mismo
 *      día; en uno que esperó, no.
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

    /**
     * Cuánto se puede fechar hacia ATRÁS.
     *
     * Noventa días cubren lo que de verdad pasa —«pagó el día 1 y lo estamos
     * registrando el 8», «se nos quedó sin registrar el cobro de la semana
     * pasada»— y siguen dejando fuera el error de digitación de año, que es el
     * que de verdad hace daño: un 2025 por un 2026 pone la membresía a vencer
     * hace meses y el socio se queda en la puerta.
     */
    public const MAX_START_BEHIND_DAYS = 90;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /**
     * Motivo por el que no se acepta un inicio pedido desde el CRM, o null.
     *
     * HACIA ATRÁS SÍ, dentro de una ventana. Antes se rechazaba cualquier fecha
     * pasada para que nadie fechara una membresía antes de pagarla y le robara
     * días al socio. Pero el mostrador tiene el caso contrario todos los meses:
     * el socio pagó el día 1 y el cobro se registra el 8, y forzar el inicio a
     * hoy le regala siete días que no compró y descuadra su vencimiento con el
     * del resto de su historial.
     *
     * Así que se permite, acotado: {@see MAX_START_BEHIND_DAYS} hacia atrás y
     * {@see MAX_START_AHEAD_DAYS} hacia delante. Lo que queda fuera de esa
     * ventana casi siempre es un año mal escrito.
     *
     * EL DINERO NO SE MUEVE. Esta fecha solo dice desde cuándo vale la
     * membresía; el cobro se fecha cuando entra el dinero y entra en la caja
     * abierta en ese momento. Son dos datos distintos a propósito.
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
        if ($fecha->lessThan($hoy->subDays(self::MAX_START_BEHIND_DAYS))) {
            return 'Solo se puede fechar el inicio hasta '.self::MAX_START_BEHIND_DAYS.' días atrás. Revisa el año.';
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

        // PAGAR DESCONGELA. Durante una pausa la app le muestra la membresía
        // vencida —es lo que la hace bloquear sin tocar la app—, así que el
        // socio puede renovar desde ahí. Si el pago se aplicara encima del
        // congelamiento, los días guardados se quedarían colgados y al
        // reanudarse pisarían el periodo recién comprado. Se reanuda primero:
        // recupera sus días y el cobro se encadena detrás, como cualquier otra
        // renovación anticipada.
        if ($user && app(MembershipFreeze::class)->isFrozen($user)) {
            app(MembershipFreeze::class)->resume($user, null, 'payment');
            $user->refresh();
        }
        /** @var Plan|null $plan */
        $plan = Plan::find($payment->plan_id);
        $dias = (int) ($plan?->duration_days ?? 0);

        if (! $user || ! $plan || $dias <= 0) {
            return null;
        }

        $cobro = $this->paidDay($payment);
        // Regla 1: manda la fecha pedida; sin ella, el día del cobro. Ya viene
        // acotada por startError(), que es quien decide hasta dónde hacia atrás.
        $inicio = $payment->starts_on
            ? CarbonImmutable::parse($payment->starts_on->format('Y-m-d'), self::TZ)->startOfDay()
            : $cobro;

        // La excepción: lo que esperó a confirmarse no empieza antes de pagarse.
        if ($inicio->lessThan($cobro) && $this->confirmadoMasTarde($payment)) {
            $inicio = $cobro;
        }

        $finActual = $user->membership_end_date
            ? CarbonImmutable::parse($user->membership_end_date, self::TZ)->startOfDay()
            : null;

        // ── MEJORA DE PLAN ──────────────────────────────────────────────
        //
        // Se mejora lo que le queda del periodo que YA compró: cambia el plan
        // y el vencimiento se queda donde estaba. Sin esta salida, el camino
        // normal le encadenaría la duración del plan nuevo al final de lo que
        // tiene y le regalaría un mes entero por pagar una diferencia.
        //
        // Lo que se cobró es la diferencia de precio; lo decide PlanUpgrades,
        // que es quien sabe de qué plan venía.
        if ($payment->upgraded_from_plan_id) {
            $user->plan = $plan->name;
            $user->status = 'active';
            $user->save();

            return [
                'start' => $payment->period_start?->toDateString() ?? $inicio->toDateString(),
                'end' => $finActual?->toDateString(),
                'chained' => false,
                'upgrade' => true,
            ];
        }

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

        // Regla 1, otra vez: el inicio es el pedido, y sin pedido el del cobro.
        $inicio = $payment->starts_on
            ? $this->day($payment->starts_on->format('Y-m-d'))
            : $this->paidDay($payment);
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

    /**
     * ¿Este cobro estuvo esperando?
     *
     * Un pago registrado con el dinero delante nace cobrado: su día de cobro y
     * el de creación son el mismo. Uno que se dejó pendiente y se confirmó
     * después lleva las dos fechas separadas, y es la única señal que distingue
     * «registro hoy lo que se pagó el día 1» de «esto llevaba una semana sin
     * pagarse». Sin ella, las dos cosas se escriben igual en la base.
     */
    private function confirmadoMasTarde(Payment $payment): bool
    {
        if (! $payment->paid_at || ! $payment->created_at) {
            return false;
        }

        $creado = CarbonImmutable::parse($payment->created_at)->setTimezone(self::TZ)->startOfDay();

        return $this->paidDay($payment)->greaterThan($creado);
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
