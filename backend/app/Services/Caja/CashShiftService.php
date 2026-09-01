<?php

namespace App\Services\Caja;

use App\Enums\CashShiftStatus;
use App\Exceptions\CashShiftException;
use App\Models\Admin;
use App\Models\CashShift;
use Illuminate\Support\Facades\DB;

/**
 * Apertura y cierre de turnos de caja.
 *
 * Único punto que cambia el estado de un turno, por la misma razón por la que
 * InventoryService es el único que escribe existencias: si el estado se puede
 * cambiar desde dos sitios, tarde o temprano cuentan cosas distintas.
 */
class CashShiftService
{
    /**
     * Abre un turno. Falla si ya hay uno abierto.
     *
     * @throws CashShiftException
     */
    public function open(Admin $admin, float $openingAmount, ?string $notes = null): CashShift
    {
        return DB::transaction(function () use ($admin, $openingAmount, $notes) {
            // Bloqueo de lectura sobre los turnos abiertos: sin él, dos
            // aperturas simultáneas pasarían las dos la comprobación. El índice
            // único parcial es la red de seguridad; esto da el error legible.
            $open = CashShift::open()->lockForUpdate()->first();
            if ($open !== null) {
                throw CashShiftException::alreadyOpen($open->opened_by_name);
            }

            return CashShift::create([
                'status' => CashShiftStatus::OPEN->value,
                'opened_by' => $admin->id,
                'opened_by_name' => $admin->name,
                'opened_at' => now(),
                'opening_amount' => round($openingAmount, 2),
                'opening_notes' => $notes,
            ]);
        });
    }

    /**
     * Cierra el turno abierto y congela el arqueo.
     *
     * `$canManage` habilita el cierre de un turno ajeno (supervisión). Sin él,
     * solo puede cerrar quien abrió: que cualquiera cierre la caja de otro
     * borra la responsabilidad sobre el descuadre.
     *
     * @throws CashShiftException
     */
    public function close(
        Admin $admin,
        float $countedAmount,
        ?string $notes = null,
        bool $canManage = false,
        ?string $forcedReason = null,
    ): CashShift {
        return DB::transaction(function () use ($admin, $countedAmount, $notes, $canManage, $forcedReason) {
            $shift = CashShift::open()->lockForUpdate()->first();
            if ($shift === null) {
                throw CashShiftException::noOpenShift();
            }

            $isOwner = (int) $shift->opened_by === (int) $admin->id;
            if (! $isOwner && ! $canManage) {
                throw CashShiftException::notOwner();
            }

            $totals = $shift->computeTotals();
            $counted = round($countedAmount, 2);

            $shift->update([
                'status' => CashShiftStatus::CLOSED->value,
                'closed_by' => $admin->id,
                'closed_by_name' => $admin->name,
                'closed_at' => now(),
                'sales_total' => $totals['sales_total'],
                'cash_sales_total' => $totals['cash_sales_total'],
                'expected_amount' => $totals['expected_amount'],
                'counted_amount' => $counted,
                // Positivo = sobra dinero; negativo = falta. Se guarda tal cual
                // en vez de en valor absoluto: la dirección del descuadre es
                // justamente lo que hay que poder revisar después.
                'difference' => round($counted - $totals['expected_amount'], 2),
                'closing_notes' => $notes,
                'forced' => ! $isOwner,
                'forced_reason' => $isOwner ? null : $forcedReason,
            ]);

            return $shift->fresh();
        });
    }

    /**
     * El turno abierto, exigiéndolo.
     *
     * Lo usa el cobro: sin turno no se registra una venta, porque ese dinero no
     * tendría dónde cuadrar ni a quién atribuirse.
     *
     * @throws CashShiftException
     */
    public function requireOpen(): CashShift
    {
        $shift = CashShift::current();
        if ($shift === null) {
            throw CashShiftException::noOpenShift();
        }

        return $shift;
    }
}
