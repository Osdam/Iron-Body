<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\CashShift;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receipt;
use App\Models\ReceiptPrint;
use App\Models\ReceiptSource;
use App\Models\ReceivablePayment;
use Illuminate\Support\Collection;

/**
 * ¿Qué tirillas faltan en este turno?
 *
 * Una fila por operación del informe de cierre, con el MISMO `row_id` que usa
 * {@see CashShiftReport} (el id de la fila, o 'abono-N'), para que el CRM pueda
 * pintar el estado de impresión al lado de cada línea sin adivinar cuál es cuál.
 *
 * Incluye los abonos de la caja de PRODUCTOS aunque el informe de ese turno no
 * los liste: son dinero recibido en el mostrador y se les entregó una tirilla.
 *
 * Tres consultas de origen y dos de recibos, sea cual sea el tamaño del turno:
 * un turno de 200 cobros no puede costar 200 consultas al abrir la pantalla.
 */
final class ShiftReceipts
{
    public function __construct(private readonly ReceiptSourceResolver $resolver) {}

    /**
     * @return array{data: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function for(CashShift $shift): array
    {
        $filas = $shift->type === CashShiftType::PRODUCTS
            ? $this->sales($shift)
            : $this->payments($shift);
        $filas = array_merge($filas, $this->receivablePayments($shift));

        $recibos = $this->receiptsFor($filas);

        $resumen = ['pending' => 0, 'printed' => 0, 'skipped' => 0, 'error' => 0, 'not_eligible' => 0];
        $salida = [];

        foreach ($filas as $fila) {
            $recibo = $recibos[$fila['source_type'].':'.$fila['source_id']] ?? null;
            $estado = $recibo?->printState($recibo->prints);

            // Lo que ya no se puede imprimir (anulado, revertido, de pasarela) no
            // cuenta como pendiente aunque tenga recibo: el contador es «lo que
            // falta por entregar», y eso no falta.
            if (! $fila['eligible']) {
                $resumen['not_eligible']++;
            } else {
                // «Enviando» (el servicio local lo tomó y no ha contado cómo
                // acabó) sigue siendo una tirilla que no consta entregada: cuenta
                // como pendiente. El resumen conserva sus cinco claves; el
                // estado exacto va en la fila.
                $resumen[$estado === Receipt::PRINT_SENDING ? Receipt::PRINT_PENDING : ($estado ?? Receipt::PRINT_PENDING)]++;
            }

            $salida[] = [
                'row_id' => $fila['row_id'],
                // ¿Sale esta fila en «Operaciones del turno»? Los abonos de la
                // caja de productos no (el informe solo lista ventas): el CRM los
                // muestra aparte para que también se puedan reimprimir.
                'in_report' => ! ($shift->type === CashShiftType::PRODUCTS && $fila['source_type'] === ReceiptSource::TYPE_RECEIVABLE_PAYMENT),
                'display' => $fila['display'] ?? null,
                'source_type' => $fila['source_type'],
                'source_id' => $fila['source_id'],
                'eligible' => $fila['eligible'],
                'reason' => $fila['reason'],
                'receipt' => $recibo ? ['uuid' => $recibo->uuid, 'number' => $recibo->number] : null,
                // Sin recibo: pendiente si se puede emitir, null si no.
                'print_state' => $estado ?? ($fila['eligible'] ? Receipt::PRINT_PENDING : null),
                // El último INTENTO DE IMPRESIÓN (las omisiones no cuentan): con
                // él el CRM distingue «aceptado por el sistema» (servicio local)
                // de «enviado» (navegador) y enseña el error de la impresora.
                'last_attempt' => $this->lastAttempt($recibo),
            ];
        }

        return ['data' => $salida, 'summary' => $resumen];
    }

    /** @return list<array<string, mixed>> */
    private function payments(CashShift $shift): array
    {
        return Payment::query()
            ->where('cash_shift_id', $shift->id)
            ->orderBy('id')
            ->get(['id', 'origin', 'method', 'status', 'cash_shift_id'])
            ->map(fn (Payment $p) => $this->row(
                $p->id,
                ReceiptSource::TYPE_PAYMENT,
                $p->id,
                $this->resolver->paymentIneligibility($p),
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function sales(CashShift $shift): array
    {
        return ProductSale::query()
            ->where('cash_shift_id', $shift->id)
            ->orderBy('id')
            ->get(['id', 'status', 'cash_shift_id'])
            ->map(fn (ProductSale $v) => $this->row(
                $v->id,
                ReceiptSource::TYPE_PRODUCT_SALE,
                $v->id,
                $this->resolver->productSaleIneligibility($v),
            ))
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function receivablePayments(CashShift $shift): array
    {
        return ReceivablePayment::query()
            ->where('cash_shift_id', $shift->id)
            // member_id hace falta para que la relación con el socio resuelva:
            // sin ella sale vacía y se caería al nombre del cajero.
            ->with(['receivable:id,concept,debtor_type,debtor_id,member_id', 'receivable.member:id,full_name'])
            ->orderBy('id')
            ->get(['id', 'status', 'cash_shift_id', 'receivable_id', 'amount', 'method', 'created_at', 'created_by_name'])
            ->map(function (ReceivablePayment $a): array {
                $fila = $this->row(
                    'abono-'.$a->id,
                    ReceiptSource::TYPE_RECEIVABLE_PAYMENT,
                    $a->id,
                    $this->resolver->receivablePaymentIneligibility($a),
                );
                // Lo mismo que el informe del gimnasio enseña de un abono. Lo
                // usa el CRM para listar los abonos que el informe de la caja de
                // PRODUCTOS no trae (y así poder reimprimirlos), sin tocar ese
                // informe financiero.
                $fila['display'] = [
                    'at' => optional($a->created_at)->toIso8601String(),
                    // El deudor, nunca el cajero que registró el abono.
                    'customer' => $a->receivable?->member?->full_name ?? ($a->receivable?->debtor()['name'] ?? null),
                    'concept' => $a->receivable?->concept,
                    'payment_method' => $a->method,
                    'total' => (float) $a->amount,
                ];

                return $fila;
            })
            ->all();
    }

    /**
     * Los recibos de todas las filas, con sus intentos, en dos consultas.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, Receipt> indexado por "tipo:id" de la fuente
     */
    private function receiptsFor(array $filas): array
    {
        $porTipo = [];
        foreach ($filas as $f) {
            $porTipo[$f['source_type']][] = $f['source_id'];
        }

        if ($porTipo === []) {
            return [];
        }

        /** @var Collection<int, ReceiptSource> $fuentes */
        $fuentes = ReceiptSource::query()
            ->where(function ($q) use ($porTipo): void {
                foreach ($porTipo as $tipo => $ids) {
                    $q->orWhere(fn ($w) => $w->where('source_type', $tipo)->whereIn('source_id', $ids));
                }
            })
            ->get();

        $recibos = Receipt::query()
            ->with('prints')
            ->whereKey($fuentes->pluck('receipt_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($fuentes as $f) {
            if ($recibo = $recibos->get($f->receipt_id)) {
                $out[$f->source_type.':'.$f->source_id] = $recibo;
            }
        }

        return $out;
    }

    /** @return array{kind: string, status: string, print_mode: string, dispatched_at: string|null, error_message: string|null}|null */
    private function lastAttempt(?Receipt $recibo): ?array
    {
        $ultimo = $recibo?->prints
            ->filter(fn (ReceiptPrint $p) => $p->isPrintAttempt())
            ->sortBy('id')
            ->last();

        return $ultimo === null ? null : [
            'kind' => $ultimo->kind,
            'status' => $ultimo->status,
            'print_mode' => $ultimo->print_mode,
            'dispatched_at' => optional($ultimo->dispatched_at)->toIso8601String(),
            'error_message' => $ultimo->error_message,
        ];
    }

    /**
     * @param  array{reason: string, message: string}|null  $motivo
     * @return array<string, mixed>
     */
    private function row(int|string $rowId, string $type, int $id, ?array $motivo): array
    {
        return [
            'row_id' => $rowId,
            'source_type' => $type,
            'source_id' => $id,
            'eligible' => $motivo === null,
            'reason' => $motivo['reason'] ?? null,
        ];
    }
}
