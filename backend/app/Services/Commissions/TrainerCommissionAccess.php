<?php

namespace App\Services\Commissions;

use App\Models\EmployeeAccess;
use App\Models\Receivable;
use App\Models\Trainer;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * EL PISO PAGADO ABRE LA PUERTA AL ENTRENADOR.
 *
 * Mientras su piso esté al día puede entrar a trabajar; cuando se le acaba,
 * no. Es lo que convierte el cobro en algo que se reclama solo.
 *
 * NO SE INVENTA UNA PUERTA NUEVA. El acceso se escribe como un acceso de
 * EMPLEADO —el mecanismo que ya existe, que el CRM enseña y que el terminal
 * aplica sin internet— con su fecha de fin. Dos sistemas distintos decidiendo
 * sobre la misma puerta es como se acaba sin saber cuál manda.
 *
 * SE RECALCULA ENTERO, NO SE VA PARCHEANDO. Cada vez que algo toca el dinero
 * de un piso —se cobra, se abona desde Cuentas por cobrar, se anula un abono,
 * se anula la deuda— se vuelven a mirar TODOS sus cobros pagados y se escribe
 * la fecha de fin que resulte. Ir sumando días a cada evento es como se
 * termina regalando acceso: basta con que una anulación no reste lo que una
 * cobro sumó.
 *
 * LO QUE SE CONCEDIÓ A MANO NO SE TOCA. Un acceso de empleado escrito desde la
 * ficha lleva `source = 'manual'` y esta clase lo deja en paz: si el gimnasio
 * decidió que ese entrenador entra, el piso no se lo quita.
 */
class TrainerCommissionAccess
{
    /** Marca de los accesos que gobierna un piso. */
    public const SOURCE = 'commission';

    /**
     * Recalcula el acceso de un entrenador a partir de sus pisos pagados.
     *
     * @return EmployeeAccess|null La fila resultante, o null si no hay nada
     *                             que escribir (sin usuario, o sin pisos).
     */
    public function sync(Trainer $trainer): ?EmployeeAccess
    {
        $user = $this->userOf($trainer);

        // SIN FICHA DE PERSONA NO HAY PUERTA QUE ABRIR. La puerta reconoce
        // socios: un entrenador que no existe como tal no puede entrar aunque
        // pague, y eso hay que poder verlo —no fallar en silencio—. Quien lo
        // llama avisa con esto.
        if (! $user) {
            return null;
        }

        $existente = EmployeeAccess::where('user_id', $user->id)->first();

        // Concedido a mano: manda el gimnasio, no el piso.
        if ($existente && $existente->source !== self::SOURCE) {
            return $existente;
        }

        $hasta = $this->paidThrough($trainer);

        if ($hasta === null) {
            // Sin ningún piso pagado vigente: se apaga, pero no se borra. El
            // histórico de cuándo entró gratis tiene que seguir ahí.
            $existente?->update(['active' => false]);

            return $existente;
        }

        $desde = $this->paidFrom($trainer);

        return EmployeeAccess::updateOrCreate(['user_id' => $user->id], [
            'position' => $existente?->position ?: 'Entrenador',
            'source' => self::SOURCE,
            'active' => true,
            'starts_on' => $desde?->toDateString(),
            'ends_on' => $hasta->toDateString(),
            // Las franjas que alguien le haya puesto se respetan: el piso dice
            // HASTA CUÁNDO entra, no a qué horas.
            'access_days' => $existente?->access_days,
            'access_windows' => $existente?->access_windows,
            'granted_by_name' => $existente?->granted_by_name ?? 'Piso al día',
            'revoked_at' => null,
            'revoked_by_name' => null,
        ]);
    }

    /**
     * Recalcula a partir de una deuda, si resulta ser la de un piso.
     *
     * Es la puerta de entrada para todo lo que mueve dinero FUERA de este
     * módulo: un abono registrado en Cuentas por cobrar, una anulación, una
     * reapertura. Si la deuda no es de un piso, no hace nada.
     */
    public function syncFromReceivable(Receivable $receivable): void
    {
        $cobro = TrainerCommissionCharge::where('receivable_id', $receivable->id)
            ->with('agreement.trainer')
            ->first();

        $entrenador = $cobro?->agreement?->trainer;

        if ($entrenador) {
            $this->sync($entrenador);
        }
    }

    /**
     * Hasta qué día tiene pagado. Null si no tiene ningún tramo pagado que
     * llegue a hoy o más allá.
     *
     * Se mira el tramo MÁS LEJANO entre los pagados, no el último cobrado: si
     * alguien adelanta noviembre y después paga octubre, su acceso llega a
     * noviembre, no a octubre.
     */
    public function paidThrough(Trainer $trainer, ?CarbonImmutable $hoy = null): ?CarbonImmutable
    {
        $hoy ??= MembershipPeriod::today();
        $fin = null;

        foreach ($this->paidCharges($trainer) as $cobro) {
            $hasta = $this->day($cobro->covers_to);
            if ($hasta === null || $hasta->lessThan($hoy)) {
                continue;
            }
            if ($fin === null || $hasta->greaterThan($fin)) {
                $fin = $hasta;
            }
        }

        return $fin;
    }

    /** El comienzo del tramo pagado más antiguo que sigue vigente. */
    private function paidFrom(Trainer $trainer, ?CarbonImmutable $hoy = null): ?CarbonImmutable
    {
        $hoy ??= MembershipPeriod::today();
        $inicio = null;

        foreach ($this->paidCharges($trainer) as $cobro) {
            $hasta = $this->day($cobro->covers_to);
            $desde = $this->day($cobro->covers_from);
            if ($hasta === null || $desde === null || $hasta->lessThan($hoy)) {
                continue;
            }
            if ($inicio === null || $desde->lessThan($inicio)) {
                $inicio = $desde;
            }
        }

        return $inicio;
    }

    /**
     * Los cobros de ese entrenador que están PAGADOS del todo.
     *
     * Pagado es saldo cero, no «status = paid»: el estado es un resumen y el
     * dinero es la verdad. Un abono anulado deja el estado atrás.
     *
     * @return Collection<int, TrainerCommissionCharge>
     */
    private function paidCharges(Trainer $trainer): Collection
    {
        return TrainerCommissionCharge::query()
            ->whereHas('agreement', fn ($q) => $q->where('trainer_id', $trainer->id))
            ->whereNotNull('covers_to')
            ->with('receivable.payments')
            ->get()
            ->filter(function (TrainerCommissionCharge $c): bool {
                $deuda = $c->receivable;

                return $deuda && ! $deuda->isCancelled() && $deuda->balance()->isZero();
            });
    }

    /**
     * La ficha de persona del entrenador, si la tiene.
     *
     * El puente es `Trainer → Identity → Member → User`, y puede romperse en
     * cualquier eslabón: hay entrenadores cargados como ficha profesional y
     * nada más. Eso no es un error que haya que tapar —es que esa persona no
     * existe para la puerta— y por eso se devuelve null y quien llama lo dice.
     */
    public function userOf(Trainer $trainer): ?User
    {
        $socio = $trainer->identity?->member;

        return $socio?->user;
    }

    private function day(mixed $fecha): ?CarbonImmutable
    {
        if ($fecha === null || $fecha === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(substr((string) $fecha, 0, 10), MembershipPeriod::TZ)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
