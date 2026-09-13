<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Member;
use App\Models\Receivable;
use App\Services\Billing\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * La solvencia de un socio frente al gimnasio.
 *
 * Responde a una sola pregunta —«¿puede usar su membresía?»— y es el ÚNICO
 * sitio donde se responde. Sin esto, la condición acabaría copiada en el
 * controlador de rutinas, en el de nutrición, en el de clases y en el estado de
 * la app, y el día que el negocio cambie el criterio habría que acordarse de
 * los cinco.
 *
 * DOS DIMENSIONES DISTINTAS. Que la membresía esté vigente lo decide
 * {@see \App\Services\MembershipService} mirando el plan y su fecha de fin.
 * Que el socio esté al día lo decide esto mirando sus deudas. Un socio puede
 * tener membresía vigente y estar bloqueado por mora, y eso NO es una membresía
 * cancelada ni vencida: no se toca el contrato, no se mueve ninguna fecha y no
 * se borra nada. Es un estado financiero, y se levanta pagando.
 *
 * NADA SE PERSISTE. La mora se deriva del saldo —que a su vez se deriva de los
 * abonos— y de la fecha pactada. Por eso liquidar desbloquea en la misma
 * petición y revertir un pago vuelve a bloquear, sin que nadie tenga que correr
 * un proceso ni acordarse de actualizar una columna.
 */
class MembershipFinancialStanding
{
    public const GOOD = 'good';

    public const OVERDUE = 'overdue';

    /**
     * GOOD u OVERDUE. Solo la deuda de gimnasio pesa: lo que alguien deba en la
     * cafetería se cobra, se reclama y se alerta, pero no le quita el acceso.
     */
    public function standing(?Member $member, ?Carbon $today = null): string
    {
        return $this->overdueReceivables($member, $today)->isEmpty() ? self::GOOD : self::OVERDUE;
    }

    public function isOverdue(?Member $member, ?Carbon $today = null): bool
    {
        return $this->standing($member, $today) === self::OVERDUE;
    }

    /**
     * ¿Puede disfrutar de los beneficios de su membresía?
     *
     * Responde SOLO por la parte financiera. Que además tenga membresía vigente
     * lo comprueba quien llame, con MembershipService: mezclar las dos aquí
     * volvería a confundir «no ha pagado» con «no tiene plan», que son cosas
     * distintas y se resuelven de forma distinta.
     */
    public function canUseMembershipBenefits(?Member $member, ?Carbon $today = null): bool
    {
        return ! $this->isOverdue($member, $today);
    }

    /**
     * Las obligaciones de gimnasio vencidas y con saldo.
     *
     * CUALQUIERA basta para bloquear, no solo la última: quien pagó el plan de
     * este mes pero dejó a medias el del anterior sigue debiendo, y mirar solo
     * la deuda más reciente lo dejaría pasar.
     *
     * @return Collection<int, Receivable>
     */
    public function overdueReceivables(?Member $member, ?Carbon $today = null): Collection
    {
        if (! $this->esSujetoDeBloqueo($member)) {
            return collect();
        }

        $hoy = $today ?? Receivable::businessToday();

        return Receivable::query()
            ->where('member_id', $member->id)
            ->where('debtor_type', DebtorType::MEMBER->value)
            ->ofType(CashShiftType::GYM)
            ->overdue($hoy)
            ->withSum('appliedPayments', 'amount')
            ->orderBy('due_at')
            ->get()
            // `status` dice que queda saldo, pero el dinero manda: se confirma
            // contra los abonos ya sumados, sin una consulta más.
            ->filter(fn (Receivable $r) => $r->balance()->isPositive())
            ->values();
    }

    /**
     * Lo que hay que enseñarle a recepción y a la app: cuánto debe, desde
     * cuándo y hace cuántos días. `due_date` es la fecha MÁS ANTIGUA vencida,
     * que es la que explica el bloqueo.
     *
     * @return array{standing:string, overdue:bool, balance:float, due_date:?string, days_overdue:int, receivables_count:int}
     */
    public function summary(?Member $member, ?Carbon $today = null): array
    {
        $hoy = $today ?? Receivable::businessToday();
        $vencidas = $this->overdueReceivables($member, $hoy);

        $saldo = $vencidas->reduce(
            fn (Money $acc, Receivable $r) => $acc->plus($r->balance()),
            Money::zero(),
        );
        $primera = $vencidas->first();

        return [
            'standing' => $vencidas->isEmpty() ? self::GOOD : self::OVERDUE,
            'overdue' => $vencidas->isNotEmpty(),
            'balance' => $saldo->toFloat(),
            'due_date' => optional($primera?->due_at)->toDateString(),
            'days_overdue' => $primera?->daysOverdue($hoy) ?? 0,
            'receivables_count' => $vencidas->count(),
        ];
    }

    /**
     * Solo un socio real puede quedar bloqueado.
     *
     * Una deuda de recepción o de un administrador existe —hay filas así en
     * producción, con `member_id` nulo— pero no cuelga de ninguna membresía:
     * se cobra y se alerta, no se bloquea. Sin esta guarda el servicio
     * reventaría con un null en cuanto alguien fiara un tinto en mostrador.
     */
    private function esSujetoDeBloqueo(?Member $member): bool
    {
        return $member !== null && $member->exists;
    }
}
