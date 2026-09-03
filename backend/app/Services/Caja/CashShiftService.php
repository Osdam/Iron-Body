<?php

namespace App\Services\Caja;

use App\Enums\CashShiftStatus;
use App\Enums\CashShiftType;
use App\Exceptions\CashShiftException;
use App\Models\Admin;
use App\Models\CashShift;
use Illuminate\Support\Facades\DB;

/**
 * Apertura y cierre de turnos de caja, por tipo.
 *
 * Único punto que cambia el estado de un turno, por la misma razón por la que
 * InventoryService es el único que escribe existencias: si el estado se puede
 * cambiar desde dos sitios, tarde o temprano cuentan cosas distintas.
 *
 * Dos cambios de política respecto a la versión de una sola caja:
 *
 *  - APERTURA EN CERO. `opening_amount` ya no se pide ni se acepta: cada turno
 *    empieza contablemente en 0. No se arrastra el esperado del cierre anterior
 *    porque nadie garantiza que ese efectivo siga físicamente en el cajón, y un
 *    arrastre falso propaga el error a todos los turnos siguientes. Si algún día
 *    hace falta una base fija para vueltas, será configuración explícita y
 *    aparte, no una suposición.
 *
 *  - CIERRE SIN CONTEO. `counted_amount` desaparece del cierre cotidiano. El
 *    backend recalcula los totales dentro de la transacción; el cliente no envía
 *    importes. El arqueo físico contra billetes existe, pero como acción
 *    excepcional y con permiso de supervisión: {@see registerDifference()}.
 */
class CashShiftService
{
    public function __construct(private readonly CashShiftTotalsService $totals) {}

    /**
     * Abre un turno del tipo indicado. Falla si ya hay uno abierto DE ESE TIPO;
     * la otra caja puede estar abierta y no estorba.
     *
     * @throws CashShiftException
     */
    public function open(Admin $admin, CashShiftType $type): CashShift
    {
        return DB::transaction(function () use ($admin, $type) {
            // Bloqueo sobre los turnos abiertos de este tipo: sin él, dos
            // aperturas simultáneas pasarían las dos la comprobación. El índice
            // único parcial es la red de seguridad; esto da el error legible.
            $abierto = CashShift::openOfType($type)->lockForUpdate()->first();
            if ($abierto !== null) {
                throw CashShiftException::alreadyOpen($abierto->opened_by_name);
            }

            return CashShift::create([
                'type' => $type->value,
                'status' => CashShiftStatus::OPEN->value,
                'opened_by' => $admin->id,
                'opened_by_name' => $admin->name,
                'opened_at' => now(),
                'opening_amount' => 0,
                'opening_policy' => 'zero',
            ]);
        });
    }

    /**
     * Cierra el turno abierto del tipo indicado y congela el arqueo.
     *
     * Los totales se recalculan AQUÍ, con el turno bloqueado. Congelarlos es
     * deliberado: recalcularlos después daría otro número si se corrige una
     * venta, y el cierre dejaría de ser auditable.
     *
     * `$canManage` habilita cerrar un turno ajeno (supervisión). Sin él, solo
     * puede cerrar quien abrió: que cualquiera cierre la caja de otro borra la
     * responsabilidad sobre el descuadre.
     *
     * @throws CashShiftException
     */
    public function close(
        Admin $admin,
        CashShiftType $type,
        ?string $note = null,
        bool $canManage = false,
        ?string $forcedReason = null,
    ): CashShift {
        return DB::transaction(function () use ($admin, $type, $note, $canManage, $forcedReason) {
            $shift = CashShift::openOfType($type)->lockForUpdate()->first();
            if ($shift === null) {
                throw CashShiftException::noOpenShift();
            }

            $esSuyo = (int) $shift->opened_by === (int) $admin->id;
            if (! $esSuyo && ! $canManage) {
                throw CashShiftException::notOwner();
            }
            // Supervisar no exime de explicarse: un cierre forzado sin motivo
            // deja un descuadre sin responsable.
            if (! $esSuyo && blank($forcedReason)) {
                throw CashShiftException::forcedReasonRequired();
            }

            $t = $this->totals->for($shift);

            $shift->update([
                'status' => CashShiftStatus::CLOSED->value,
                'closed_by' => $admin->id,
                'closed_by_name' => $admin->name,
                'closed_at' => now(),
                'sales_total' => $t['gross_total'],
                'cash_sales_total' => $t['cash_total'],
                'transfer_total' => $t['transfer_total'],
                'card_total' => $t['card_total'],
                'wompi_total' => $t['wompi_total'],
                'other_total' => $t['other_total'],
                'operations_count' => $t['operations_count'],
                'expected_amount' => $t['expected_cash'],
                // counted_amount y difference quedan NULL: nadie contó billetes.
                // Se rellenan solo si un supervisor registra el arqueo físico.
                'closing_notes' => $note,
                'auto_observation' => $this->totals->observation($shift, $t),
                'forced' => ! $esSuyo,
                'forced_reason' => $esSuyo ? null : $forcedReason,
            ]);

            return $shift->fresh();
        });
    }

    /**
     * Arqueo físico: registra cuánto efectivo había de verdad en el cajón.
     *
     * Acción EXCEPCIONAL y separada del cierre cotidiano. El sistema no puede
     * saber cuánto dinero hay físicamente, así que pedirlo cada día convertía
     * una comprobación puntual en un trámite que se rellena de cualquier manera.
     *
     * Se aplica sobre un turno YA CERRADO y solo con permiso de supervisión.
     * `expected_amount` NO se recalcula: es el que se congeló al cerrar.
     *
     * @throws CashShiftException
     */
    public function registerDifference(Admin $admin, CashShift $shift, float $counted, string $reason): CashShift
    {
        return DB::transaction(function () use ($admin, $shift, $counted, $reason) {
            $fresco = CashShift::whereKey($shift->id)->lockForUpdate()->first();
            if ($fresco === null || $fresco->isOpen()) {
                throw CashShiftException::noOpenShift();
            }

            $contado = round($counted, 2);
            $esperado = (float) $fresco->expected_amount;

            $fresco->update([
                'counted_amount' => $contado,
                // Positivo = sobra dinero; negativo = falta. Se guarda con signo
                // en vez de en valor absoluto: la dirección del descuadre es
                // justamente lo que hay que poder revisar después.
                'difference' => round($contado - $esperado, 2),
                'closing_notes' => trim(($fresco->closing_notes ? $fresco->closing_notes."\n" : '')
                    .sprintf('[%s] Arqueo físico por %s: %s', now()->format('Y-m-d H:i'), $admin->name, $reason)),
            ]);

            return $fresco->fresh();
        });
    }

    /**
     * El turno abierto de un tipo, exigiéndolo.
     *
     * Lo usan el cobro de productos y el cobro presencial del gimnasio: sin
     * turno no se registra dinero presencial, porque no tendría dónde cuadrar
     * ni a quién atribuirse.
     *
     * @throws CashShiftException
     */
    public function requireOpen(CashShiftType $type): CashShift
    {
        $shift = CashShift::currentOfType($type);
        if ($shift === null) {
            throw CashShiftException::noOpenShift();
        }

        return $shift;
    }
}
