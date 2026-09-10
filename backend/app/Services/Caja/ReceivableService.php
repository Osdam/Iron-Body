<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Services\Audit\FinancialAudit;
use App\Enums\DebtorType;
use App\Exceptions\ReceivableException;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Services\Billing\Money;
use App\Support\Caja\PaymentMethodKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Único sitio donde nace y se abona una cuenta por cobrar.
 *
 * Los invariantes viven AQUÍ, no en el controlador ni en el navegador: si el
 * saldo se comprobara en Angular, bastaría con llamar a la API para dejar una
 * cuenta en negativo.
 *
 *   TOTAL = PAGADO + SALDO, siempre.
 *   El saldo nunca baja de cero.
 *   Un abono no crea venta, ni membresía, ni mueve inventario.
 *
 * CONCURRENCIA. `settle()` bloquea la fila de la cuenta con `lockForUpdate`
 * antes de leer el saldo. Sin eso, dos cajeros con la misma pantalla abierta
 * pueden leer «saldo 50.000» a la vez y abonar 50.000 cada uno: las dos
 * comprobaciones pasan y la cuenta acaba en -50.000. Con el bloqueo, el segundo
 * espera, vuelve a leer y recibe el sobrepago con el saldo ya actualizado.
 *
 * IDEMPOTENCIA. `client_request_id` es único en la base de datos. Un doble clic
 * reenvía el mismo identificador y el segundo intento choca contra el índice;
 * la colisión se atrapa y se devuelve el abono que ya existía. Deshabilitar el
 * botón en el navegador no protege de un reintento de red ni de dos pestañas.
 */
class ReceivableService
{
    public function __construct(private readonly CashShiftService $shifts) {}

    /**
     * Crea una obligación.
     *
     * No mueve dinero ni inventario: eso lo hace quien la origina (una venta a
     * crédito descuenta sus existencias; un plan activa su membresía). Aquí
     * solo queda constancia de lo que se debe.
     */
    /**
     * Abre una cuenta por cobrar.
     *
     * `$debtorType` + `$debtorId` son la identidad financiera: sobreviven a que
     * la persona cambie de nombre y permiten responder «¿quién originó esta
     * deuda?» meses después sin depender de un texto.
     *
     * AQUÍ, Y SOLO AQUÍ, se mantiene la invariante: cuando el deudor es socio,
     * `member_id` es su id; cuando no lo es, queda vacío. `member_id` sigue
     * existiendo porque de ella cuelgan el índice, la relación y el filtrado del
     * entrenador a sus socios; que las dos digan lo mismo se decide en un único
     * sitio para que no puedan discrepar.
     */
    public function create(
        DebtorType $debtorType,
        int $debtorId,
        CashShiftType $type,
        string $concept,
        Money $total,
        ?Admin $actor = null,
        ?Model $source = null,
        ?string $notes = null,
    ): Receivable {
        if (! $total->isPositive()) {
            throw ReceivableException::invalidTotal();
        }

        if (app(DebtorDirectory::class)->resolve($debtorType, $debtorId) === null) {
            throw ReceivableException::unknownDebtor($debtorType);
        }

        $receivable = new Receivable([
            'debtor_type' => $debtorType->value,
            'debtor_id' => $debtorId,
            'member_id' => $debtorType === DebtorType::MEMBER ? $debtorId : null,
            'type' => $type->value,
            'concept' => trim($concept),
            'total_amount' => $total->toDatabase(),
            'status' => Receivable::STATUS_PENDING,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
            'notes' => $notes,
        ]);

        if ($source instanceof Model) {
            $receivable->source()->associate($source);
        }

        // La deuda y su traza se guardan JUNTAS. La auditoría se escribe dentro
        // de la transacción por la misma razón que en ventas y cobros: si la
        // traza no se puede escribir, la deuda no se abre.
        return DB::transaction(function () use ($receivable, $actor) {
            $receivable->save();

            $this->audit()->receivableOpened($receivable, $actor, request());

            return $receivable;
        });
    }

    /**
     * Registra un abono y devuelve la fila resultante.
     *
     * El turno lo resuelve el SERVIDOR a partir del tipo de la cuenta: un
     * crédito de cafetería se cobra en la caja de productos y un plan en la del
     * gimnasio. Si el cliente pudiera elegirlo, podría mandar el dinero a la
     * caja equivocada —o a ninguna— y el arqueo del día dejaría de cuadrar.
     *
     * @param  string|null  $clientRequestId  Identificador de la petición. Si se
     *                                        repite, se devuelve el abono ya
     *                                        registrado en vez de crear otro.
     */
    public function settle(
        Receivable $receivable,
        Money $amount,
        string $method,
        ?Admin $actor = null,
        ?string $clientRequestId = null,
        ?string $reference = null,
        ?string $notes = null,
    ): ReceivablePayment {
        if (! $amount->isPositive()) {
            throw ReceivableException::invalidAmount();
        }

        // Antes de abrir la transacción: si esta petición ya se atendió, se
        // devuelve lo mismo que la primera vez. Es el camino rápido del doble
        // clic; el índice único de abajo es el que de verdad lo garantiza.
        if ($clientRequestId !== null && $yaHecho = $this->findByRequestId($clientRequestId)) {
            return $yaHecho;
        }

        // El turno se exige FUERA de la transacción de saldo: si no hay caja
        // abierta no hay nada que registrar, y conviene fallar antes de tomar
        // ningún bloqueo.
        $shift = $this->shifts->requireOpen($receivable->type);

        try {
            return DB::transaction(function () use (
                $receivable, $amount, $method, $actor, $clientRequestId, $reference, $notes, $shift
            ) {
                // El saldo se lee CON LA FILA BLOQUEADA. Leerlo antes sería leer
                // un saldo que otro cajero puede estar cambiando ahora mismo.
                $fresca = Receivable::whereKey($receivable->id)->lockForUpdate()->firstOrFail();

                if ($fresca->isCancelled()) {
                    throw ReceivableException::cancelled();
                }

                $saldoAntes = $fresca->balance();

                if ($saldoAntes->isZero()) {
                    throw ReceivableException::alreadySettled();
                }

                // Sobrepago: se rechaza, no se convierte en saldo a favor. Un
                // saldo a favor es otro producto financiero y nadie lo ha
                // pedido; inventarlo aquí crearía dinero que no existe.
                if ($amount->greaterThan($saldoAntes)) {
                    throw ReceivableException::overpayment($saldoAntes);
                }

                $saldoDespues = $saldoAntes->minus($amount);

                $abono = ReceivablePayment::create([
                    'receivable_id' => $fresca->id,
                    'amount' => $amount->toDatabase(),
                    'method' => PaymentMethodKind::normalize($method)->value,
                    'cash_shift_id' => $shift->id,
                    'balance_before' => $saldoAntes->toDatabase(),
                    'balance_after' => $saldoDespues->toDatabase(),
                    'client_request_id' => $clientRequestId,
                    'status' => ReceivablePayment::STATUS_APPLIED,
                    'created_by' => $actor?->id,
                    'created_by_name' => $actor?->name,
                    'reference' => $reference,
                    'notes' => $notes,
                ]);

                // El estado es una CONSECUENCIA del saldo, no una decisión: se
                // recalcula en la misma transacción para que no exista un
                // instante en que la cuenta esté saldada y siga diciendo que no.
                $fresca->refresh();
                $fresca->update(['status' => $fresca->statusForBalance()]);

                $this->audit()->receivablePaid($abono, $fresca, $actor, request());

                return $abono;
            });
        } catch (QueryException $e) {
            // Choque contra el índice único de `client_request_id`: el doble
            // clic llegó tan seguido que la primera petición aún no había
            // terminado cuando entró la segunda. No es un error: el abono
            // existe, y eso es exactamente lo que había que conseguir.
            if ($clientRequestId !== null && $yaHecho = $this->findByRequestId($clientRequestId)) {
                return $yaHecho;
            }

            throw $e;
        }
    }

    /**
     * Anula un abono sin borrarlo.
     *
     * El dinero entró y eso no se deshace borrando una fila: se deja constancia
     * de las dos cosas, el abono y su reversa. La cuenta vuelve al estado que
     * le corresponda por su nuevo saldo.
     */
    public function reverse(ReceivablePayment $payment, string $reason, ?Admin $actor = null): ReceivablePayment
    {
        $motivo = trim($reason);
        if ($motivo === '') {
            throw ReceivableException::reversalReasonRequired();
        }

        return DB::transaction(function () use ($payment, $motivo, $actor) {
            // Bajo bloqueo, igual que al abonar: dos supervisores anulando a la
            // vez leerían el mismo estado y lo revertirían dos veces. El
            // segundo espera, relee y se encuentra con que ya está hecho.
            $fresco = ReceivablePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $fresco->isApplied()) {
                throw ReceivableException::alreadyReversed();
            }

            $fresco->update([
                'status' => ReceivablePayment::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $actor?->id,
                'reversal_reason' => $motivo,
            ]);

            $cuenta = Receivable::whereKey($fresco->receivable_id)->lockForUpdate()->firstOrFail();
            $cuenta->update(['status' => $cuenta->statusForBalance()]);

            $this->audit()->receivableReversed($fresco, $cuenta, $motivo, $actor, request());

            // LA MEMBRESÍA NO SE TOCA, ni siquiera al anular el primer pago que
            // la activó. Anular un abono corrige un ERROR DE CAJA —se tecleó
            // 50.000 donde eran 5.000—, no dice que el socio deba dejar de
            // entrenar. Quitarle el acceso sin avisar, por una corrección
            // contable, es un efecto que nadie pidió y que además ocurriría en
            // silencio. Si de verdad hay que darlo de baja, eso es una decisión
            // aparte y tiene su propia pantalla.
            return $fresco;
        });
    }

    /**
     * La auditoría financiera, resuelta al vuelo.
     *
     * No entra por el constructor porque este servicio se instancia desde sitios
     * que no tienen petición HTTP —comandos, jobs, tests— y allí `write()` no
     * escribe nada igualmente: sin actor identificado no hay traza que firmar.
     */
    private function audit(): FinancialAudit
    {
        return app(FinancialAudit::class);
    }

    private function findByRequestId(string $clientRequestId): ?ReceivablePayment
    {
        return ReceivablePayment::where('client_request_id', $clientRequestId)->first();
    }
}
