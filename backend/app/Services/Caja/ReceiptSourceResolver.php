<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Exceptions\ReceiptException;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\Receipt;
use App\Models\ReceiptSource;
use App\Models\ReceivablePayment;
use App\Models\TrainerCommissionCharge;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Support\Collection;

/**
 * QUÉ SE PUEDE IMPRIMIR, y en qué caja.
 *
 * Un recibo de caja dice «este dinero entró aquí, ahora». Solo es verdad para el
 * dinero que de verdad cambió de manos en el mostrador, y esta clase es la que
 * lo decide —con las mismas reglas que el arqueo, no con otras—:
 *
 *  · payments: solo los de MOSTRADOR (`origin = counter`), cobrados (`paid`),
 *    con turno y que NO sean el asiento de devengo de un plan a plazos
 *    (`method = receivable`): ese registro activa la membresía pero no es
 *    dinero, y su dinero llega —y se imprime— como abono. Ver CashReceipts.
 *  · product_sales: cobradas (`paid`/`delivered`) o entregadas a crédito
 *    (`credit`, que se imprime como comprobante de entrega con su saldo).
 *  · receivable_payments: abonos APLICADOS. Uno revertido ya no es dinero.
 *
 * La pasarela, las automatizaciones y la migración NO se imprimen: no pasaron
 * por un mostrador, y una tirilla de un pago de Wompi de las tres de la mañana
 * no se le entregó a nadie.
 *
 * Si la operación YA tiene recibo, se devuelve ese aunque hoy no fuera
 * imprimible (una venta anulada después de imprimirla): pedir el recibo de algo
 * es pedir EL recibo, no uno nuevo. La elegibilidad solo decide si se puede
 * CREAR.
 */
final class ReceiptSourceResolver
{
    public const REASON_GATEWAY = 'gateway';

    public const REASON_AUTOMATION = 'automation';

    public const REASON_LEGACY = 'legacy';

    public const REASON_UNKNOWN_ORIGIN = 'unknown_origin';

    public const REASON_INSTALLMENT_ACCRUAL = 'installment_accrual';

    public const REASON_NO_SHIFT = 'no_shift';

    public const REASON_PENDING = 'pending';

    public const REASON_CANCELLED = 'cancelled';

    public const REASON_REVERSED = 'reversed';

    /**
     * Patrón del identificador de cada abono de un «cobrar todo»: el
     * BulkSettlement deriva `"{peticion}:{deuda}"` del id del barrido. Es lo que
     * permite reconocer, después, qué abonos se cobraron JUNTOS.
     */
    private const BULK_REQUEST_PATTERN = '/^(.+):(\d+)$/';

    /**
     * Resuelve lo que pide el CRM en recibos ya existentes y candidatos a crear.
     *
     * @param  list<int>  $ids  uno para payment/product_sale; uno o varios para abonos
     * @return array{existing: list<Receipt>, candidates: list<ReceiptCandidate>}
     *
     * @throws ReceiptException not_found | not_printable
     */
    public function resolve(string $sourceType, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return match ($sourceType) {
            ReceiptSource::TYPE_PAYMENT => $this->payment($ids[0]),
            ReceiptSource::TYPE_PRODUCT_SALE => $this->productSale($ids[0]),
            ReceiptSource::TYPE_RECEIVABLE_PAYMENT => $this->receivablePayments($ids),
        };
    }

    /**
     * Las cajas a las que pertenecen estas operaciones, ANTES de mirar si son
     * imprimibles.
     *
     * Existe para que el permiso de caja se compruebe primero: si no, quien solo
     * tiene la caja de productos podría saber, por el motivo del rechazo, que un
     * cobro del gimnasio fue por pasarela o está anulado. Un cobro es siempre del
     * gimnasio y una venta siempre de productos; un abono, de la caja de su
     * deuda. Las que no existen no aportan caja (darán 404 al resolver).
     *
     * @param  list<int>  $ids
     * @return list<CashShiftType>
     */
    public function cashTypesFor(string $sourceType, array $ids): array
    {
        return match ($sourceType) {
            ReceiptSource::TYPE_PAYMENT => [CashShiftType::GYM],
            ReceiptSource::TYPE_PRODUCT_SALE => [CashShiftType::PRODUCTS],
            ReceiptSource::TYPE_RECEIVABLE_PAYMENT => ReceivablePayment::query()
                ->join('receivables', 'receivables.id', '=', 'receivable_payments.receivable_id')
                ->whereIn('receivable_payments.id', $ids)
                ->distinct()
                ->pluck('receivables.type')
                ->map(fn ($tipo) => $tipo instanceof CashShiftType ? $tipo : CashShiftType::from((string) $tipo))
                ->unique(fn (CashShiftType $t) => $t->value)
                ->values()
                ->all(),
        };
    }

    // ── Elegibilidad (sin consultas: sirve también para pintar un turno) ────

    /**
     * Por qué este cobro NO es imprimible, o null si lo es.
     *
     * @return array{reason: string, message: string}|null
     */
    public function paymentIneligibility(Payment $payment): ?array
    {
        $origen = PaymentOrigin::fromStored($payment->origin);

        if ($origen !== PaymentOrigin::COUNTER) {
            return match ($origen) {
                PaymentOrigin::GATEWAY => $this->why(self::REASON_GATEWAY, 'Los pagos por pasarela no pasan por el mostrador: no llevan recibo de caja.'),
                PaymentOrigin::AUTOMATION => $this->why(self::REASON_AUTOMATION, 'Este cobro lo registró una automatización, no una persona en el mostrador.'),
                PaymentOrigin::LEGACY => $this->why(self::REASON_LEGACY, 'Este cobro viene del sistema anterior: no pasó por esta caja.'),
                default => $this->why(self::REASON_UNKNOWN_ORIGIN, 'No consta que este cobro pasara por el mostrador.'),
            };
        }

        if ($payment->method === 'receivable') {
            return $this->why(
                self::REASON_INSTALLMENT_ACCRUAL,
                'Es el registro del plan a plazos, no dinero recibido. El recibo es el del abono.',
            );
        }

        if ($payment->cash_shift_id === null) {
            return $this->why(self::REASON_NO_SHIFT, 'Este cobro no pertenece a ningún turno de caja.');
        }

        return match ((string) $payment->status) {
            'paid' => null,
            'pending' => $this->why(self::REASON_PENDING, 'El cobro sigue pendiente: todavía no ha entrado dinero.'),
            default => $this->why(self::REASON_CANCELLED, 'El cobro está anulado ('.$payment->status.').'),
        };
    }

    /**
     * Por qué esta venta NO es imprimible, o null si lo es.
     *
     * TODA VENTA CONFIRMADA SACA TIRILLA, con turno o sin él (regla del
     * propietario). El caso sin turno existe: un pedido de la app que se cobra
     * en el mostrador pasa por `CajaController::pay()`, que no le asigna turno.
     * El cliente está delante y se lleva el producto, así que se le entrega su
     * recibo; el recibo nace con `cash_shift_id` null y en la caja de
     * productos. Que ese dinero no entre en ningún arqueo es un hallazgo aparte
     * de `pay()`, y no se arregla escondiendo la tirilla.
     *
     * @return array{reason: string, message: string}|null
     */
    public function productSaleIneligibility(ProductSale $sale): ?array
    {
        return match ((string) $sale->status) {
            'paid', 'delivered', ProductSale::STATUS_CREDIT => null,
            'pending' => $this->why(self::REASON_PENDING, 'La venta sigue pendiente de cobro.'),
            default => $this->why(self::REASON_CANCELLED, 'La venta está anulada.'),
        };
    }

    /** @return array{reason: string, message: string}|null */
    public function receivablePaymentIneligibility(ReceivablePayment $abono): ?array
    {
        if (! $abono->isApplied()) {
            return $this->why(self::REASON_REVERSED, 'El abono fue revertido: ya no es dinero recibido.');
        }

        if ($abono->cash_shift_id === null) {
            return $this->why(self::REASON_NO_SHIFT, 'Este abono no pertenece a ningún turno de caja.');
        }

        return null;
    }

    // ── Por tipo ────────────────────────────────────────────────────────────

    /** @return array{existing: list<Receipt>, candidates: list<ReceiptCandidate>} */
    private function payment(int $id): array
    {
        $pago = Payment::find($id) ?? throw ReceiptException::sourceNotFound(ReceiptSource::TYPE_PAYMENT, $id);

        if ($ya = $this->existingFor(ReceiptSource::TYPE_PAYMENT, [$id])) {
            return ['existing' => $ya, 'candidates' => []];
        }

        $this->assertPrintable($this->paymentIneligibility($pago), ReceiptSource::TYPE_PAYMENT, $id);

        return ['existing' => [], 'candidates' => [new ReceiptCandidate(
            cashType: CashShiftType::GYM,
            kind: $pago->upgraded_from_plan_id ? Receipt::KIND_PLAN_UPGRADE : Receipt::KIND_MEMBERSHIP_PAYMENT,
            originKey: ReceiptSource::TYPE_PAYMENT.':'.$pago->id,
            sources: [['type' => ReceiptSource::TYPE_PAYMENT, 'id' => $pago->id]],
            operationRef: $pago->reference,
            operationAt: $pago->paid_at ?? $pago->created_at,
            cashShiftId: $pago->cash_shift_id,
            payment: $pago,
        )]];
    }

    /** @return array{existing: list<Receipt>, candidates: list<ReceiptCandidate>} */
    private function productSale(int $id): array
    {
        $venta = ProductSale::find($id) ?? throw ReceiptException::sourceNotFound(ReceiptSource::TYPE_PRODUCT_SALE, $id);

        if ($ya = $this->existingFor(ReceiptSource::TYPE_PRODUCT_SALE, [$id])) {
            return ['existing' => $ya, 'candidates' => []];
        }

        $this->assertPrintable($this->productSaleIneligibility($venta), ReceiptSource::TYPE_PRODUCT_SALE, $id);

        $aCredito = $venta->status === ProductSale::STATUS_CREDIT;

        return ['existing' => [], 'candidates' => [new ReceiptCandidate(
            cashType: CashShiftType::PRODUCTS,
            kind: $aCredito ? Receipt::KIND_PRODUCT_CREDIT_SALE : Receipt::KIND_PRODUCT_SALE,
            originKey: ReceiptSource::TYPE_PRODUCT_SALE.':'.$venta->id,
            sources: [['type' => ReceiptSource::TYPE_PRODUCT_SALE, 'id' => $venta->id]],
            operationRef: $venta->code,
            operationAt: ($aCredito ? $venta->delivered_at : $venta->paid_at) ?? $venta->created_at,
            cashShiftId: $venta->cash_shift_id,
            productSale: $venta,
        )]];
    }

    /**
     * Abonos: uno suelto o los de un «cobrar todo».
     *
     * Los de un mismo barrido se agrupan por su prefijo de petición Y por caja:
     * cobrar a la vez un agua fiada y medio plan son dos dineros que entran en
     * dos cajas distintas, y cada caja tiene su tirilla y su numeración. Un
     * abono que ya tiene recibo no se vuelve a meter en otro: se devuelve el
     * suyo.
     *
     * @param  list<int>  $ids
     * @return array{existing: list<Receipt>, candidates: list<ReceiptCandidate>}
     */
    private function receivablePayments(array $ids): array
    {
        $abonos = ReceivablePayment::query()
            ->with('receivable')
            ->whereKey($ids)
            ->orderBy('id')
            ->get();

        foreach ($ids as $id) {
            if (! $abonos->contains('id', $id) || $abonos->firstWhere('id', $id)->receivable === null) {
                throw ReceiptException::sourceNotFound(ReceiptSource::TYPE_RECEIVABLE_PAYMENT, $id);
            }
        }

        $existentes = $this->existingFor(ReceiptSource::TYPE_RECEIVABLE_PAYMENT, $ids);
        $conRecibo = ReceiptSource::query()
            ->where('source_type', ReceiptSource::TYPE_RECEIVABLE_PAYMENT)
            ->whereIn('source_id', $ids)
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pendientes = $abonos->reject(fn (ReceivablePayment $a) => in_array($a->id, $conRecibo, true))->values();

        // TODO O NADA: si un abono de la petición no es imprimible no se emite
        // ninguno. Un cobro de tres deudas con una tirilla para dos dejaría al
        // cliente sin saber cuál falta.
        foreach ($pendientes as $abono) {
            $this->assertPrintable(
                $this->receivablePaymentIneligibility($abono),
                ReceiptSource::TYPE_RECEIVABLE_PAYMENT,
                $abono->id,
            );
        }

        $grupos = $pendientes->groupBy(
            fn (ReceivablePayment $a) => $this->bulkGroupOf($a).'|'.$a->receivable->type->value,
        );

        $candidatos = [];
        foreach ($grupos as $grupo) {
            $candidatos[] = $this->receivableCandidate($grupo->values());
        }

        return ['existing' => $existentes, 'candidates' => $candidatos];
    }

    /** @param  Collection<int, ReceivablePayment>  $grupo */
    private function receivableCandidate(Collection $grupo): ReceiptCandidate
    {
        $primero = $grupo->first();
        $ids = $grupo->pluck('id')->sort()->values()->all();

        return new ReceiptCandidate(
            cashType: $primero->receivable->type,
            kind: count($ids) > 1 ? Receipt::KIND_SETTLEMENT : $this->singlePaymentKind($primero),
            // Un abono suelto tiene SIEMPRE la misma llave, venga pedido solo o
            // dentro de una lista: así un reintento por la otra vía devuelve el
            // mismo recibo en vez de chocar.
            // Un grupo grande (cobrar todo con 40 deudas) daba una llave de más
            // de 191 caracteres y PostgreSQL rechazaba el INSERT: se guarda su
            // huella, que es igual de determinista. Los ids quedan en
            // receipt_sources.
            originKey: count($ids) > 1
                ? 'rp:'.hash('sha256', implode(',', $ids))
                : ReceiptSource::TYPE_RECEIVABLE_PAYMENT.':'.$ids[0],
            sources: array_map(fn (int $id) => ['type' => ReceiptSource::TYPE_RECEIVABLE_PAYMENT, 'id' => $id], $ids),
            operationRef: null,
            operationAt: $grupo->max('created_at'),
            cashShiftId: $primero->cash_shift_id,
            receivablePayments: $grupo->all(),
        );
    }

    /**
     * Qué es un abono suelto, por lo que pagó.
     *
     *  · commission: la deuda es el piso de un entrenador.
     *  · installment_first_payment: la deuda nació de vender un plan a plazos y
     *    este es su PRIMER abono, el que se cobró al vender.
     *  · receivable_payment: cualquier otro abono.
     */
    private function singlePaymentKind(ReceivablePayment $abono): string
    {
        $deuda = $abono->receivable;

        if (TrainerCommissionCharge::where('receivable_id', $deuda->id)->exists()) {
            return Receipt::KIND_COMMISSION;
        }

        if ($deuda->source_type === Payment::class) {
            $primerAbono = ReceivablePayment::where('receivable_id', $deuda->id)->min('id');
            if ((int) $primerAbono === $abono->id) {
                return Receipt::KIND_INSTALLMENT_FIRST_PAYMENT;
            }
        }

        return Receipt::KIND_RECEIVABLE_PAYMENT;
    }

    /**
     * El barrido al que pertenece un abono, o el propio abono si fue suelto.
     *
     * Solo se reconoce el barrido si el sufijo del identificador es EXACTAMENTE
     * la deuda del abono: así un identificador cualquiera del CRM que acabe en
     * «:número» no agrupa abonos que no tienen nada que ver.
     */
    private function bulkGroupOf(ReceivablePayment $abono): string
    {
        $id = (string) $abono->client_request_id;

        if ($id !== '' && preg_match(self::BULK_REQUEST_PATTERN, $id, $m) && (int) $m[2] === (int) $abono->receivable_id) {
            return 'bulk:'.$m[1];
        }

        return 'single:'.$abono->id;
    }

    /**
     * Los recibos que ya cubren alguna de estas fuentes.
     *
     * @param  list<int>  $ids
     * @return list<Receipt>
     */
    private function existingFor(string $type, array $ids): array
    {
        $recibos = ReceiptSource::query()
            ->where('source_type', $type)
            ->whereIn('source_id', $ids)
            ->pluck('receipt_id')
            ->unique()
            ->values();

        if ($recibos->isEmpty()) {
            return [];
        }

        return Receipt::whereKey($recibos->all())->orderBy('id')->get()->all();
    }

    /** @param  array{reason: string, message: string}|null  $motivo */
    private function assertPrintable(?array $motivo, string $type, int $id): void
    {
        if ($motivo !== null) {
            throw ReceiptException::notPrintable($motivo['reason'], $motivo['message'], $type, $id);
        }
    }

    /** @return array{reason: string, message: string} */
    private function why(string $reason, string $message): array
    {
        return ['reason' => $reason, 'message' => $message];
    }
}
