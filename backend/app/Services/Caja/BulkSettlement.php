<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Exceptions\CashShiftException;
use App\Exceptions\ReceivableException;
use App\Models\Admin;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SALDAR TODO LO QUE DEBE UNA PERSONA, de una vez.
 *
 * El motivo por el que existe: alguien se llevó fiadas cinco aguas en una
 * semana. Son cinco obligaciones, y cobrarlas obligaba a abrir cinco fichas y
 * registrar cinco abonos a mano. Quien está en el mostrador con el billete
 * delante no debería tener que hacer eso, y hacerlo cinco veces es cinco veces
 * la oportunidad de equivocarse o de dejarse una a medias.
 *
 * LO QUE ESTO NO HACE, y es deliberado:
 *
 *  · NO fusiona las deudas. Cada una recibe SU abono, con su saldo antes y
 *    después y su traza. Después de cobrar sigue constando qué se llevó cada
 *    día: si se hiciera un solo apunte por 10.000, el historial ya no podría
 *    decir cuál de las aguas se pagó.
 *  · NO inventa saldo a favor. Si el dinero que entra supera lo que se debe, se
 *    rechaza: un sobrante es otro producto financiero y nadie lo ha pedido.
 *  · NO mezcla cajas. Cada obligación entra en el turno de SU caja —productos o
 *    gimnasio— y eso lo decide el servidor, nunca el cliente.
 *
 * EL ORDEN ES FIFO: primero la deuda más antigua. Con un abono parcial es la
 * única repartición defendible ante quien paga: se cierra lo que lleva más
 * tiempo abierto, no lo que más conviene.
 *
 * TODO O NADA. Si a mitad del barrido falla algo —una caja sin abrir, una deuda
 * que otro cajero acaba de cobrar— se deshace el barrido completo. Un cobro
 * aplicado a la mitad de las deudas es peor que ninguno: el dinero ya cambió de
 * manos y el sistema diría otra cosa.
 */
final class BulkSettlement
{
    public function __construct(
        private readonly DebtorAccounts $accounts,
        private readonly ReceivableService $receivables,
        private readonly CashShiftService $shifts,
    ) {}

    /**
     * Aplica un pago a todas las deudas vivas de una persona, de la más antigua
     * a la más nueva.
     *
     * @param  Money|null  $amount  null = todo lo que deba
     * @param  list<int>  $onlyIds  para cobrar solo algunas líneas
     * @return array{
     *     applied: Money,
     *     payments: list<ReceivablePayment>,
     *     settled_ids: list<int>,
     *     remaining: Money,
     *     debts_touched: int,
     * }
     *
     * @throws ReceivableException|CashShiftException
     */
    public function settle(
        DebtorType $debtorType,
        int $debtorId,
        string $method,
        ?Money $amount = null,
        ?Admin $actor = null,
        ?CashShiftType $cash = null,
        array $onlyIds = [],
        ?string $clientRequestId = null,
        ?string $reference = null,
        ?string $notes = null,
    ): array {
        // EL REINTENTO VA PRIMERO. Si esta misma petición ya se atendió, se
        // devuelve lo que hizo entonces. Sin esto, el segundo clic llegaba
        // cuando ya no quedaba deuda y respondía «esta cuenta ya está saldada»,
        // que en pantalla se lee como un error cuando en realidad el cobro sí
        // se hizo. El dinero no se toca dos veces.
        if ($clientRequestId !== null && $yaHecho = $this->replay($clientRequestId)) {
            return $yaHecho;
        }

        $deudas = $this->accounts->openDebts($debtorType, $debtorId, $cash, $onlyIds);

        if ($deudas->isEmpty()) {
            throw ReceivableException::alreadySettled();
        }

        $total = $this->sumBalances($deudas);
        $aAplicar = $amount ?? $total;

        if (! $aAplicar->isPositive()) {
            throw ReceivableException::invalidAmount();
        }

        // El sobrepago se rechaza ANTES de tocar nada, y con el saldo real en el
        // mensaje: así el mostrador sabe cuánto es «todo».
        if ($aAplicar->greaterThan($total)) {
            throw ReceivableException::overpayment($total);
        }

        // Las cajas que hacen falta se comprueban TODAS antes de empezar. Si se
        // comprobara al vuelo, con la de productos abierta y la del gimnasio
        // cerrada se cobrarían las aguas y reventaría a mitad del barrido.
        $this->requireShiftsFor($deudas, $aAplicar);

        $abonos = [];
        $saldadas = [];
        $restante = $aAplicar;

        DB::transaction(function () use ($deudas, &$abonos, &$saldadas, &$restante, $method, $actor, $clientRequestId, $reference, $notes): void {
            foreach ($deudas as $deuda) {
                if (! $restante->isPositive()) {
                    break;
                }

                $saldo = $deuda->balance();
                // La última deuda del barrido puede quedarse a medias: se le
                // abona lo que queda del dinero, no su saldo entero.
                $parte = $restante->greaterThan($saldo) ? $saldo : $restante;

                $abono = $this->receivables->settle(
                    receivable: $deuda,
                    amount: $parte,
                    method: $method,
                    actor: $actor,
                    // Idempotencia POR LÍNEA, derivada de la del barrido: el
                    // índice único de `client_request_id` es de una sola fila,
                    // así que repetir el mismo id para cinco abonos los haría
                    // chocar entre sí. Con el sufijo, un reintento del barrido
                    // completo devuelve los abonos que ya existían en vez de
                    // cobrar otra vez.
                    clientRequestId: $clientRequestId ? $clientRequestId.':'.$deuda->id : null,
                    reference: $reference,
                    notes: $notes,
                );

                $abonos[] = $abono;
                $restante = $restante->minus($parte);

                if ($parte->equals($saldo)) {
                    $saldadas[] = $deuda->id;
                }
            }
        });

        return [
            'applied' => $aAplicar->minus($restante),
            'payments' => $abonos,
            'settled_ids' => $saldadas,
            'remaining' => $total->minus($aAplicar->minus($restante)),
            'debts_touched' => count($abonos),
            'replayed' => false,
        ];
    }

    /**
     * Lo que hizo un barrido anterior con este mismo identificador.
     *
     * Los abonos del barrido se marcan con `<id>:<deuda>`, así que reconocerlo
     * es buscar por ese prefijo. Se devuelve lo aplicado entonces y el saldo que
     * queda AHORA: es la respuesta honesta a un reintento —«esto ya se cobró, y
     * así está la cuenta»— sin cobrar de nuevo.
     *
     * @return array<string, mixed>|null
     */
    private function replay(string $clientRequestId): ?array
    {
        $abonos = ReceivablePayment::query()
            ->where('client_request_id', 'like', $clientRequestId.':%')
            ->where('status', ReceivablePayment::STATUS_APPLIED)
            ->orderBy('id')
            ->get();

        if ($abonos->isEmpty()) {
            return null;
        }

        $aplicado = $abonos->reduce(
            fn (Money $acc, ReceivablePayment $a) => $acc->plus(Money::fromAmount($a->amount)),
            Money::zero(),
        );

        $cuentas = Receivable::whereKey($abonos->pluck('receivable_id')->unique()->all())->get();

        return [
            'applied' => $aplicado,
            'payments' => $abonos->all(),
            'settled_ids' => $cuentas->filter(fn (Receivable $r) => $r->balance()->isZero())
                ->pluck('id')->values()->all(),
            'remaining' => $cuentas->reduce(
                fn (Money $acc, Receivable $r) => $acc->plus($r->balance()),
                Money::zero(),
            ),
            'debts_touched' => $abonos->count(),
            'replayed' => true,
        ];
    }

    /**
     * Lo que se va a cobrar ANTES de cobrarlo: qué líneas cubre el dinero y qué
     * queda debiendo.
     *
     * Existe para que el mostrador pueda enseñárselo a quien paga —«con 10.000
     * te quedan cubiertas estas tres y sobra saldo en la del jueves»— sin
     * escribir nada. Lo calcula el SERVIDOR con las mismas reglas del cobro: si
     * lo calculara el navegador, la previsión y el cobro podrían no coincidir.
     *
     * @return array<string, mixed>
     */
    public function preview(
        DebtorType $debtorType,
        int $debtorId,
        ?Money $amount = null,
        ?CashShiftType $cash = null,
        array $onlyIds = [],
    ): array {
        $deudas = $this->accounts->openDebts($debtorType, $debtorId, $cash, $onlyIds);
        $total = $this->sumBalances($deudas);
        $aAplicar = $amount ?? $total;

        $restante = $aAplicar->greaterThan($total) ? $total : $aAplicar;
        $lineas = [];

        foreach ($deudas as $deuda) {
            $saldo = $deuda->balance();
            $parte = $restante->isPositive()
                ? ($restante->greaterThan($saldo) ? $saldo : $restante)
                : Money::zero();
            $restante = $restante->minus($parte);

            $lineas[] = [
                'id' => $deuda->id,
                'concept' => $deuda->concept,
                'created_at' => optional($deuda->created_at)->toIso8601String(),
                'balance' => $saldo->toFloat(),
                'applies' => $parte->toFloat(),
                'left' => $saldo->minus($parte)->toFloat(),
                'settles' => $parte->equals($saldo) && $parte->isPositive(),
                'type' => $deuda->type->value,
                'type_label' => $deuda->type->label(),
            ];
        }

        return [
            'outstanding' => $total->toFloat(),
            'amount' => $aAplicar->greaterThan($total) ? $total->toFloat() : $aAplicar->toFloat(),
            'overpayment' => $aAplicar->greaterThan($total),
            'remaining' => $total->minus($aAplicar->greaterThan($total) ? $total : $aAplicar)->toFloat(),
            'lines' => $lineas,
            'cash_types' => $deudas->map(fn (Receivable $r) => $r->type->value)->unique()->values()->all(),
        ];
    }

    /**
     * Exige turno abierto en cada caja que el barrido va a tocar.
     *
     * Solo las que de verdad se van a tocar: con 2.000 en la mano para alguien
     * que debe un agua y medio plan, el dinero se queda en la primera deuda y no
     * hay motivo para exigir que esté abierta también la otra caja.
     *
     * @param  Collection<int, Receivable>  $deudas
     *
     * @throws CashShiftException
     */
    private function requireShiftsFor(Collection $deudas, Money $aAplicar): void
    {
        $restante = $aAplicar;
        $cajas = [];

        foreach ($deudas as $deuda) {
            if (! $restante->isPositive()) {
                break;
            }
            $cajas[$deuda->type->value] = $deuda->type;
            $saldo = $deuda->balance();
            $restante = $restante->minus($restante->greaterThan($saldo) ? $saldo : $restante);
        }

        foreach ($cajas as $caja) {
            $this->shifts->requireOpen($caja);
        }
    }

    /** @param  Collection<int, Receivable>  $deudas */
    private function sumBalances(Collection $deudas): Money
    {
        return $deudas->reduce(
            fn (Money $acc, Receivable $r) => $acc->plus($r->balance()),
            Money::zero(),
        );
    }
}
