<?php

namespace App\Services\Payments;

use App\Http\Controllers\Api\PaymentController;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\MembershipTransfers;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Recalcula la membresía de un socio desde sus pagos COBRADOS.
 *
 * POR QUÉ NO BASTA CON `MembershipPeriod::revert()`. Hasta el 14 de septiembre
 * de 2026 la regla del periodo vivía copiada dentro de PaymentController y no
 * guardaba nada en el pago: ni `starts_on`, ni `period_start`, ni `period_end`.
 * Extendía `users.membership_end_date` y ahí se acababa el rastro. Anular un
 * cobro de esa época no devolvía los días —el agujero que ya está tapado— y
 * tampoco dejó un periodo congelado que devolver después. `revert()` no tiene
 * de dónde agarrarse: para esos socios la única fuente que queda son los pagos
 * que SIGUEN cobrados.
 *
 * QUÉ HACE. Vuelve a aplicar, en orden, los pagos vigentes del socio con las
 * reglas de MembershipPeriod, partiendo de cero. Lo que sobraba —el periodo de
 * un cobro anulado— sencillamente no entra, porque ese pago ya no está cobrado.
 *
 * LA MEDIANOCHE. Un `paid_at` a las 00:00:00 exactas es una FECHA que alguien
 * guardó sin hora, no un cobro de medianoche. Leerla como instante y pasarla a
 * la zona del negocio la corre al día anterior, que es justo lo que hace el CRM
 * al pintarla. Aquí se lee como el día del calendario que es, para que el
 * recálculo respete la fecha que escribió quien cobró.
 *
 * QUÉ NO TOCA. Un socio cuya vigencia no salió de sus pagos —importaciones del
 * sistema anterior (`MIGR-*`), planes que ya no están en el catálogo— no se
 * puede reconstruir desde los pagos sin inventar. Esos se reportan y se dejan.
 */
class MembershipRebuilder
{
    public const TZ = MembershipPeriod::TZ;

    /** Hoy en la zona del negocio. Una sola definición de «hoy» para todos. */
    public static function today(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    /**
     * Qué membresía le corresponde al socio según sus pagos vigentes.
     *
     * @return array{start: ?string, end: ?string, applied: int, skipped: ?string}
     */
    public function preview(User $user): array
    {
        $pagos = $this->paidPlanPayments($user);

        if ($motivo = $this->refusal($user, $pagos)) {
            return ['start' => null, 'end' => null, 'applied' => 0, 'skipped' => $motivo];
        }

        $inicio = null;
        $cursor = null;
        $aplicados = 0;

        foreach ($pagos as $pago) {
            if ($pago->upgraded_from_plan_id) {
                // Una mejora reemplaza el periodo de su pago origen; no suma
                // otra duración encima del mensual que ya se contó.
                if ($pago->upgrade_snapshot) {
                    $cursor = $this->day($pago->period_end->toDateString());
                }
                $aplicados++;

                continue;
            }
            // UN BENEFICIO AÑADIDO NO ENTRA EN LA VIGENCIA. El plan día y las
            // clases sueltas son derechos aparte, con su propio periodo
            // congelado en su cobro: encadenarlos aquí volvería a inflar el
            // vencimiento del socio, que es justo lo que esta reconstrucción
            // tiene que poder corregir. Ver `plans.benefit_only`.
            if ($this->esBeneficio($pago)) {
                $aplicados++;

                continue;
            }

            $dias = $this->durationOf($pago);
            if ($dias <= 0) {
                continue;
            }

            $cobro = $this->paidDay($pago);
            $pedido = $pago->starts_on
                ? $this->day($pago->starts_on->format('Y-m-d'))
                : $cobro;
            // Regla 1: nunca antes del cobro.
            $arranque = $pedido->lessThan($cobro) ? $cobro : $pedido;
            // Regla 2: si la anterior sigue viva ese día, se encadena.
            $base = ($cursor !== null && $cursor->greaterThan($arranque)) ? $cursor : $arranque;

            $inicio ??= $arranque;
            $cursor = $base->addDays($dias);
            $aplicados++;
        }

        return [
            'start' => $inicio?->toDateString(),
            'end' => $cursor?->toDateString(),
            'applied' => $aplicados,
            'skipped' => null,
        ];
    }

    /**
     * ESCRIBE SOLO LA ETIQUETA, sin tocar ninguna fecha.
     *
     * Existe porque las dos reparaciones son independientes y una de ellas se
     * puede hacer siempre. La vigencia no se puede recalcular para quien vino
     * del sistema anterior —sus pagos `MIGR-*` no congelaron periodo y
     * rehacerla sería inventarla—, pero la ETIQUETA sí: su nombre correcto está
     * en su propio historial, es el plan de su último cobro de membresía de
     * verdad.
     *
     * Y hace falta justo para esa gente. Son los socios de siempre, los que
     * venían del sistema anterior, y a ellos el plan día les reescribió la
     * etiqueta igual que a todos: `rebuild()` se negaba antes de llegar aquí, y
     * se quedaban con «PLAN DÍA» como membresía y con sus reglas de acceso —una
     * entrada— gobernando el año que tienen pagado.
     *
     * No inventa nada: no toca fechas, importes, cobros ni historial. Devuelve
     * el nombre anterior y el nuevo, o null si no había nada que corregir.
     *
     * @return array{from: ?string, to: string}|null
     */
    public function rebuildLabel(User $user): ?array
    {
        $correcta = $this->labelFix($user);
        if ($correcta === null || $correcta === $user->plan) {
            return null;
        }

        $antes = $user->plan;
        $user->plan = $correcta;
        $user->save();

        return ['from' => $antes, 'to' => $correcta];
    }

    /**
     * Escribe el recálculo. Devuelve null si no había nada que cambiar o si el
     * socio no se puede reconstruir.
     *
     * @return array{start: ?string, end: ?string, previous_start: ?string, previous_end: ?string, applied: int}|null
     */
    public function rebuild(User $user): ?array
    {
        $nuevo = $this->preview($user);
        if ($nuevo['skipped'] !== null) {
            return null;
        }

        $antesInicio = $user->membership_start_date;
        $antesFin = $user->membership_end_date;
        $antesPlan = $user->plan;
        $etiqueta = $this->labelFix($user);

        if ($antesInicio === $nuevo['start'] && $antesFin === $nuevo['end']
            && ($etiqueta === null || $etiqueta === $antesPlan)) {
            return null;
        }

        $user->membership_start_date = $nuevo['start'];
        $user->membership_end_date = $nuevo['end'];
        // Sin pagos vigentes no queda membresía que nombrar. La ficha, los
        // pagos y el historial NO se tocan: esto corrige una fecha, no borra a
        // nadie.
        if ($nuevo['end'] === null) {
            $user->plan = null;
        } elseif ($etiqueta !== null) {
            // LA ETIQUETA, cuando la reescribió un beneficio añadido. Ver
            // etiquetaCorrecta(): es el otro rastro que dejó el fallo, y sin
            // corregirlo la ficha sigue enseñando «PLAN DÍA» como la membresía
            // de alguien que tiene una anualidad.
            $user->plan = $etiqueta;
        }
        $user->save();

        return [
            'start' => $nuevo['start'],
            'end' => $nuevo['end'],
            'previous_start' => $antesInicio,
            'previous_end' => $antesFin,
            'plan' => $user->plan,
            'previous_plan' => $antesPlan,
            'applied' => $nuevo['applied'],
        ];
    }

    /**
     * Pagos que hoy siguen comprando membresía, en el orden en que la
     * compraron.
     *
     * @return Collection<int, Payment>
     */
    public function paidPlanPayments(User $user): Collection
    {
        return Payment::query()
            ->where('user_id', $user->id)
            ->whereNotNull('plan_id')
            ->whereRaw(
                'LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES
            )
            ->orderByRaw('COALESCE(paid_at, created_at)')
            ->orderBy('id')
            ->get()
            ->loadMissing('plan');
    }

    /**
     * Por qué este socio no se puede reconstruir desde sus pagos, o null.
     *
     * @param  Collection<int, Payment>  $pagos
     */
    private function refusal(User $user, Collection $pagos): ?string
    {
        // TRASPASOS DE MEMBRESÍA vivos: la vigencia de la titular terminó ayer
        // aunque su cobro diga otra cosa, y la del beneficiario no sale de
        // ningún pago suyo. Reconstruir a cualquiera de los dos desde sus
        // pagos le devolvería a la titular lo que cedió, o le quitaría al
        // beneficiario lo que recibió (TRANSFERENCIA-DE-MEMBRESIAS.md §4).
        if ($traspaso = MembershipTransfers::involvedInLive((int) $user->id)) {
            return "tiene un traspaso de membresía vivo (#{$traspaso->id}): su vigencia no sale solo de sus pagos";
        }

        // SIN NINGÚN PAGO DE PLAN NO HAY NADA QUE REHACER, y esto no es un
        // detalle: el gimnasio otorga vigencias por fuera de la caja —demos de
        // tester, cortesías del personal, cuentas de revisión, accesos de un
        // año o de cinco— y ninguna nace de un pago. Reconstruirlas «desde los
        // pagos» daría cero días y les quitaría el acceso a gente que lo tiene
        // concedido a propósito. Se distingue de «compró y se le anuló todo»
        // mirando si existe algún pago con plan en CUALQUIER estado.
        $comproAlgunaVez = Payment::where('user_id', $user->id)->whereNotNull('plan_id')->exists();
        if (! $comproAlgunaVez) {
            return 'no tiene ningún pago de plan: su vigencia se otorgó por fuera de la caja (demo, cortesía, importación)';
        }

        // Migrados: la vigencia la copió el importador TAL CUAL del sistema
        // anterior, no la compró ningún pago de Iron Body.
        $migrados = Payment::where('user_id', $user->id)->where('reference', 'like', 'MIGR-%')->exists();
        if ($migrados) {
            return 'tiene pagos migrados (MIGR-*): su vigencia vino del sistema anterior';
        }

        foreach ($pagos as $pago) {
            if ($pago->upgraded_from_plan_id) {
                // Legacy: solo cambió el plan, sin días nuevos. Las nuevas
                // necesitan el pago origen para reconstruir sin inventar la
                // membresía concedida fuera de caja.
                if (! $pago->upgrade_snapshot) {
                    continue;
                }
                $origen = $pagos->firstWhere('id', $pago->upgrade_snapshot['source_payment_id'] ?? null);
                if (! $origen || ! $pago->period_start || ! $pago->period_end) {
                    return "la mejora #{$pago->id} no tiene un periodo de pago origen cobrado que permita reconstruirla";
                }

                continue;
            }
            if ($this->durationOf($pago) <= 0) {
                return "el pago #{$pago->id} apunta al plan {$pago->plan_id}, que no existe o no tiene duración";
            }
        }

        return null;
    }

    /**
     * Cuántos días compró un pago: los que se le congelaron, y si no, los que
     * dura su plan hoy.
     */
    /**
     * EL NOMBRE QUE DEBERÍA TENER LA ETIQUETA, o null si no hay nada que decir.
     *
     * `users.plan` lo reescribía CADA cobro, también el de un beneficio añadido:
     * una socia con una anualidad vigente que compró un plan día se quedó con
     * «PLAN DÍA» como membresía, y de ahí salían sus reglas de acceso.
     *
     * Solo se repara ese caso concreto y demostrable: la etiqueta nombra un plan
     * marcado como BENEFICIO AÑADIDO y el socio tiene un cobro de un plan que no
     * lo es. Entonces su membresía es ésa —la del último cobro de membresía de
     * verdad— y se le devuelve el nombre.
     *
     * Si la etiqueta nombra un plan normal no se toca NADA: pudo ponerla un
     * traslado o una edición a mano, que son cambios legítimos, y adivinar ahí
     * sería reescribir decisiones de otra persona.
     */
    public function labelFix(User $user): ?string
    {
        $etiqueta = trim((string) ($user->plan ?? ''));
        if ($etiqueta === '') {
            return null;
        }

        $pagos = $this->paidPlanPayments($user);

        $nombraBeneficio = $pagos->contains(
            fn (Payment $p): bool => (bool) ($p->plan?->benefit_only)
                && mb_strtolower(trim((string) $p->plan?->name)) === mb_strtolower($etiqueta)
        );
        if (! $nombraBeneficio) {
            return null;
        }

        $membresia = $pagos
            ->filter(fn (Payment $p): bool => $p->plan !== null && ! (bool) $p->plan->benefit_only)
            ->last();

        return $membresia?->plan?->name;
    }

    /** ¿Este cobro compró un beneficio añadido en vez de membresía? */
    private function esBeneficio(Payment $pago): bool
    {
        return (bool) (Plan::find($pago->plan_id)?->benefit_only);
    }

    private function durationOf(Payment $pago): int
    {
        if ($pago->period_start && $pago->period_end) {
            return (int) $this->day($pago->period_start->format('Y-m-d'))
                ->diffInDays($this->day($pago->period_end->format('Y-m-d')));
        }

        return (int) (Plan::find($pago->plan_id)?->duration_days ?? 0);
    }

    /**
     * Día del negocio en que entró el dinero. Una medianoche UTC exacta se
     * trata como fecha suelta (ver la nota de la clase) y no se convierte.
     */
    private function paidDay(Payment $pago): CarbonImmutable
    {
        $crudo = $pago->getRawOriginal('paid_at');
        if (! $crudo) {
            return MembershipPeriod::today();
        }

        $instante = CarbonImmutable::parse($crudo);
        if ($instante->format('H:i:s') === '00:00:00') {
            return $this->day($instante->format('Y-m-d'));
        }

        return $instante->setTimezone(self::TZ)->startOfDay();
    }

    private function day(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha, self::TZ)->startOfDay();
    }
}
