<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use Illuminate\Database\Eloquent\Model;

/**
 * GUARDARRAÍL DE CONCILIACIÓN — última barrera antes de llamar a Factus.
 *
 * Compara el total del comprobante con el total BRUTO congelado del origen
 * (pago o venta). Si difieren más allá de la tolerancia, la emisión NO ocurre.
 *
 * Existe porque emitir una factura por un importe distinto al recaudado es un
 * problema fiscal real (IVA declarado que nunca se percibió) y, a diferencia de
 * casi todo lo demás, no tiene vuelta atrás: el documento queda en la DIAN.
 *
 * Es puramente defensivo: no calcula ni modifica ningún importe. Por eso puede
 * desplegarse de forma aislada, antes que cualquier cambio de cobro.
 */
class InvoiceReconciler
{
    /**
     * @return array{ok: bool, source_amount: float|null, difference: float, reason: string|null}
     */
    public function check(ElectronicInvoice $invoice, ?Model $source): array
    {
        $invoiceTotal = (float) $invoice->total;

        if (! config('billing.reconciliation_guard.enabled', true)) {
            return [
                'ok' => true,
                'source_amount' => null,
                'difference' => 0.0,
                'reason' => null,
                'skipped' => true,
            ];
        }

        $sourceAmount = $source !== null
            ? InvoicingService::sourceGrossAmount($source)
            : ($invoice->source_amount_snapshot !== null ? (float) $invoice->source_amount_snapshot : null);

        // Sin origen verificable no se puede conciliar. No se bloquea la emisión
        // por eso (hay 6 facturas históricas cuyo Payment fue eliminado), pero se
        // deja constancia explícita de que no se verificó.
        if ($sourceAmount === null) {
            return [
                'ok' => true,
                'source_amount' => null,
                'difference' => 0.0,
                'reason' => null,
                'skipped' => true,
            ];
        }

        $difference = round(abs($invoiceTotal - $sourceAmount), 2);
        $tolerance = (float) config('billing.reconciliation_guard.tolerance', 1);

        if ($difference > $tolerance) {
            return [
                'ok' => false,
                'source_amount' => $sourceAmount,
                'difference' => $difference,
                'skipped' => false,
                'reason' => sprintf(
                    'Descuadre pago/factura: el origen registra %s y el comprobante totaliza %s '
                    .'(diferencia %s, tolerancia %s). No se emitió a Factus. '
                    .'Corrige el importe del origen o regenera el comprobante antes de reintentar.',
                    number_format($sourceAmount, 2, ',', '.'),
                    number_format($invoiceTotal, 2, ',', '.'),
                    number_format($difference, 2, ',', '.'),
                    number_format($tolerance, 2, ',', '.'),
                ),
            ];
        }

        return [
            'ok' => true,
            'source_amount' => $sourceAmount,
            'difference' => $difference,
            'reason' => null,
            'skipped' => false,
        ];
    }
}
