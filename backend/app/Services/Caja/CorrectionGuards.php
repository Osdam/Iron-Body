<?php

namespace App\Services\Caja;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\CorrectionException;
use App\Models\Admin;
use App\Models\CashShift;
use App\Models\ElectronicInvoice;
use App\Models\InvoiceBundleItem;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Services\Membership\MembershipTransfers;
use App\Support\Access\CrmPermission;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use Illuminate\Database\Eloquent\Model;

/**
 * Las comprobaciones que comparten TODAS las correcciones administrativas.
 *
 * Existen en un solo sitio porque son las mismas tres preguntas se corrija lo
 * que se corrija —un cobro del gimnasio, una venta de cafetería o un abono— y
 * contestarlas distinto en cada pantalla es como acaban las cajas contando
 * historias distintas:
 *
 *   · ¿Hay una factura electrónica que lo respalde ante la DIAN?
 *   · ¿El turno en que entró el dinero ya está cerrado y firmado?
 *   · ¿El dinero entró por la pasarela (y por tanto no se anula aquí)?
 *
 * Se llaman DENTRO de la transacción y con la operación ya bloqueada: la vista
 * previa las usa también, pero lo que dice la vista previa nunca se da por
 * bueno. Ver docs/ops/CORRECCIONES-ADMINISTRATIVAS.md §2.
 */
class CorrectionGuards
{
    /**
     * La factura (no la nota crédito) que respalda la operación: la suya propia
     * o la de un lote de facturación manual que la incluye y sigue activo.
     */
    public function invoiceFor(Model $source): ?ElectronicInvoice
    {
        $propia = ElectronicInvoice::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('type', InvoiceType::INVOICE->value)
            ->orderByDesc('id')
            ->first();

        if ($propia !== null && $propia->status !== InvoiceStatus::CANCELLED) {
            return $propia;
        }

        $origen = match (true) {
            $source instanceof Payment => InvoiceBundleItem::ORIGIN_PAYMENT,
            $source instanceof ProductSale => InvoiceBundleItem::ORIGIN_PRODUCT_SALE,
            default => null,
        };
        if ($origen !== null) {
            $items = InvoiceBundleItem::query()
                ->where('origin_type', $origen)
                ->where('origin_id', $source->getKey())
                ->where('active', true)
                ->with('bundle.invoice')
                ->get();
            foreach ($items as $item) {
                $lote = $item->bundle?->invoice;
                if ($lote !== null && $lote->status !== InvoiceStatus::CANCELLED) {
                    return $lote;
                }
            }
        }

        return $propia;
    }

    /** La factura de un LOTE manual activo que incluye la operación, si no está anulada. */
    public function bundleInvoiceFor(Model $source): ?ElectronicInvoice
    {
        $origen = match (true) {
            $source instanceof Payment => InvoiceBundleItem::ORIGIN_PAYMENT,
            $source instanceof ProductSale => InvoiceBundleItem::ORIGIN_PRODUCT_SALE,
            default => null,
        };
        if ($origen === null) {
            return null;
        }

        foreach (InvoiceBundleItem::query()->where('origin_type', $origen)->where('origin_id', $source->getKey())
            ->where('active', true)->with('bundle.invoice')->get() as $item) {
            $lote = $item->bundle?->invoice;
            if ($lote !== null && $lote->status !== InvoiceStatus::CANCELLED) {
                return $lote;
            }
        }

        return null;
    }

    /** La factura PROPIA de la operación (no la de un lote), si no está anulada. */
    public function ownInvoiceFor(Model $source): ?ElectronicInvoice
    {
        return ElectronicInvoice::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('type', InvoiceType::INVOICE->value)
            ->where('status', '!=', InvoiceStatus::CANCELLED->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Lo que dice la factura sobre anular o devolver por COMPLETO.
     *
     *  - validada sin nota crédito validada → 409 `invoice_validated`;
     *  - en proceso (factura o nota)        → 409 `invoice_processing`;
     *  - pendiente, en error, rechazada o ya anulada por nota crédito → adelante:
     *    el guardián de emisión no factura un cobro que ya no está pagado.
     */
    public function invoiceBlocker(Model $source): ?CorrectionException
    {
        $factura = $this->invoiceFor($source);
        if ($factura === null) {
            return null;
        }

        if ($factura->status === InvoiceStatus::PROCESSING) {
            return CorrectionException::invoiceProcessing($factura->id);
        }

        if ($factura->status !== InvoiceStatus::VALIDATED) {
            return null;
        }

        $nota = ElectronicInvoice::query()
            ->where('source_type', $factura->source_type)
            ->where('source_id', $factura->source_id)
            ->where('type', InvoiceType::CREDIT_NOTE->value)
            ->orderByDesc('id')
            ->first();

        if ($nota?->status === InvoiceStatus::CREDIT_NOTE_VALIDATED) {
            return null;
        }
        if ($nota?->status === InvoiceStatus::CREDIT_NOTE_PROCESSING) {
            return CorrectionException::invoiceProcessing($factura->id);
        }

        return CorrectionException::invoiceValidated($factura->id);
    }

    public function assertInvoiceAllows(Model $source): void
    {
        if ($bloqueo = $this->invoiceBlocker($source)) {
            throw $bloqueo;
        }
    }

    /**
     * Corregir algo de un turno CERRADO exige, además del permiso de la acción,
     * administrar esa caja. El cierre firmado no cambia; el informe del turno
     * lista la corrección como posterior al cierre.
     */
    public function shiftBlocker(?CashShift $shift, ?Admin $actor): ?CorrectionException
    {
        if ($shift === null || $shift->isOpen()) {
            return null;
        }

        $permiso = $shift->type->managePermission();

        return CrmPermission::allows($actor, $permiso)
            ? null
            : CorrectionException::closedShiftRequiresManage($shift->id, $permiso);
    }

    public function assertShiftAllows(?CashShift $shift, ?Admin $actor): void
    {
        if ($bloqueo = $this->shiftBlocker($shift, $actor)) {
            throw $bloqueo;
        }
    }

    /**
     * Un cobro cuyo periodo se CEDIÓ a otra persona y el traspaso sigue vivo
     * (docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md §4): no se anula ni se devuelve
     * hasta revertir el traspaso. Se llama con el cobro ya bloqueado; la
     * vista previa la usa también.
     */
    public function transferBlocker(Payment $payment): ?CorrectionException
    {
        $traspaso = MembershipTransfers::liveFromPayment((int) $payment->id);

        return $traspaso === null
            ? null
            : CorrectionException::membershipTransferred((int) $traspaso->id, $traspaso->beneficiary_user_id);
    }

    public function assertNotTransferred(Payment $payment): void
    {
        if ($bloqueo = $this->transferBlocker($payment)) {
            throw $bloqueo;
        }
    }

    /**
     * ¿El dinero de este cobro entró por la pasarela?
     *
     * Por su ORIGEN (lo fija el código que lo creó) y, para filas anteriores a
     * esa columna, por su medio. Un cobro de pasarela es dinero real que llegó
     * a la cuenta del gimnasio: anularlo aquí no lo devuelve a nadie.
     */
    public static function isGatewayPayment(Payment $payment): bool
    {
        return $payment->origin === PaymentOrigin::GATEWAY->value
            || PaymentMethodKind::normalize($payment->method) === PaymentMethodKind::WOMPI;
    }

    /** Lo mismo para una venta: `online` es pasarela en el arqueo. */
    public static function isGatewaySale(ProductSale $sale): bool
    {
        return PaymentMethodKind::normalize($sale->payment_method) === PaymentMethodKind::WOMPI
            && $sale->payment_status === 'paid';
    }

    /** Resumen de la factura para la vista previa y los enlaces. */
    public function invoiceSummary(Model $source): ?array
    {
        $factura = $this->invoiceFor($source);

        return $factura === null ? null : [
            'id' => $factura->id,
            'status' => $factura->status->value,
            'number' => $factura->full_number,
        ];
    }
}
