<?php

namespace App\Services\Caja;

use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receipt;
use App\Models\ReceiptSource;
use App\Models\ReceivablePayment;

/**
 * La forma en que el CRM recibe un recibo.
 *
 * Separa lo CONGELADO de lo VIVO a propósito:
 *
 *  · `snapshot` es lo que se imprimió y lo que se reimprime. No cambia.
 *  · `live` es lo que la operación es HOY: si se anuló después, si ya tiene
 *    factura electrónica. Se consulta en cada lectura y no se imprime como si
 *    fuera parte del recibo original; el CRM decide cómo enseñarlo.
 *
 * Mezclarlos sería o reescribir un recibo emitido o imprimir hoy un estado de
 * hace un mes.
 */
final class ReceiptPresenter
{
    public function __construct(
        private readonly ReceiptPrints $prints,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(Receipt $recibo, bool $withPrints = false): array
    {
        $recibo->loadMissing(['sources', 'prints']);

        $data = [
            'uuid' => $recibo->uuid,
            'number' => $recibo->number,
            'cash_type' => $recibo->cash_type,
            'kind' => $recibo->kind,
            'operation_ref' => $recibo->operation_ref,
            'operation_at' => optional($recibo->operation_at)->toIso8601String(),
            'issued_at' => optional($recibo->issued_at)->toIso8601String(),
            'issued_late' => (bool) $recibo->issued_late,
            'print_state' => $recibo->printState($recibo->prints),
            'snapshot' => $recibo->snapshot,
            'live' => $this->live($recibo),
        ];

        if ($withPrints) {
            $data['prints'] = $this->prints->recent($recibo);
        }

        return $data;
    }

    /**
     * El estado actual de la operación y su factura electrónica, si la tiene.
     *
     * @return array{operation_status: string|null, invoice: array{status: string, full_number: string|null}|null}
     */
    public function live(Receipt $recibo): array
    {
        $fuentes = $recibo->relationLoaded('sources') ? $recibo->sources : $recibo->sources()->get();
        $tipo = $fuentes->first()?->source_type;
        $ids = $fuentes->pluck('source_id')->map(fn ($id) => (int) $id)->all();

        return match ($tipo) {
            ReceiptSource::TYPE_PAYMENT => $this->fromInvoiceable(
                Payment::query()->with('electronicInvoice')->find($ids[0] ?? 0),
            ),
            ReceiptSource::TYPE_PRODUCT_SALE => $this->fromInvoiceable(
                ProductSale::query()->with('electronicInvoice')->find($ids[0] ?? 0),
            ),
            ReceiptSource::TYPE_RECEIVABLE_PAYMENT => [
                'operation_status' => $this->receivableStatus($ids),
                // Un abono no se factura por sí mismo: no hay factura que enseñar.
                'invoice' => null,
            ],
            default => ['operation_status' => null, 'invoice' => null],
        };
    }

    /** @return array{operation_status: string|null, invoice: array{status: string, full_number: string|null}|null} */
    private function fromInvoiceable(Payment|ProductSale|null $operacion): array
    {
        if ($operacion === null) {
            return ['operation_status' => null, 'invoice' => null];
        }

        $resumen = $operacion->invoice_summary;

        return [
            'operation_status' => $operacion->status,
            'invoice' => $resumen === null ? null : [
                'status' => $resumen['status'],
                'full_number' => $resumen['full_number'],
            ],
        ];
    }

    /**
     * `applied` si todos siguen aplicados, `reversed` si se revirtieron todos y
     * `partially_reversed` si un cobro de varias deudas perdió alguno.
     *
     * @param  list<int>  $ids
     */
    private function receivableStatus(array $ids): ?string
    {
        $estados = ReceivablePayment::whereKey($ids)->pluck('status')->unique()->values()->all();

        return match (true) {
            $estados === [] => null,
            $estados === [ReceivablePayment::STATUS_APPLIED] => ReceivablePayment::STATUS_APPLIED,
            $estados === [ReceivablePayment::STATUS_REVERSED] => ReceivablePayment::STATUS_REVERSED,
            default => 'partially_reversed',
        };
    }
}
