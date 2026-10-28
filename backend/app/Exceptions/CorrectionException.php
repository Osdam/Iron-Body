<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Una corrección administrativa (anular, devolver, corregir el medio) que el
 * estado REAL de la operación no admite.
 *
 * Lleva su código HTTP y su `code` de contrato (docs/ops/CORRECCIONES-
 * ADMINISTRATIVAS.md §4) y se pinta sola: Laravel llama a `render()`. Así el
 * mismo motivo responde igual desde el cobro, la venta o el abono, y el CRM
 * decide qué enseñar por el `code`, no por el texto.
 *
 * Excepción y no booleano, como CashShiftException y ReceivableException: un
 * `false` que alguien olvida comprobar es una corrección a medias sobre dinero.
 */
class CorrectionException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extra  Campos adicionales de la respuesta (p. ej. `invoice_id`).
     */
    public function __construct(
        string $message,
        public readonly string $code_,
        public readonly int $status = 409,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_merge([
            'ok' => false,
            'code' => $this->code_,
            'message' => $this->getMessage(),
        ], $this->extra), $this->status);
    }

    /** @return array{code: string, message: string} */
    public function toBlocker(): array
    {
        return array_merge(['code' => $this->code_, 'message' => $this->getMessage()], $this->extra);
    }

    public static function gatewayPayment(): self
    {
        return new self(
            'Este cobro entró por la pasarela: ese dinero es real y no se anula aquí. '
            .'Si hay que devolverlo, hazlo en el panel de la pasarela y registra aquí la devolución con su referencia.',
            'gateway_payment',
        );
    }

    public static function invoiceValidated(int $invoiceId): self
    {
        return new self(
            'Esta operación tiene una factura electrónica validada. Emite primero la nota crédito desde el detalle '
            .'de la factura y repite la corrección cuando la DIAN la valide.',
            'invoice_validated',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    public static function invoiceProcessing(int $invoiceId): self
    {
        return new self(
            'La factura electrónica de esta operación se está procesando en este momento. Espera a que termine y vuelve a intentarlo.',
            'invoice_processing',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    public static function partialRefundWithInvoice(int $invoiceId): self
    {
        return new self(
            'Esta venta tiene una factura electrónica validada y la nota crédito solo puede ser total: '
            .'una devolución parcial no se puede reflejar en la factura. Devuelve la venta entera o resuélvelo con contabilidad.',
            'invoice_validated',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    /**
     * Devolución PARCIAL de una venta incluida en una factura multiconcepto
     * (lote) que no está anulada: el lote se rehízo con el total de la venta y
     * no se puede rehacer desde aquí.
     */
    public static function partialRefundInBundle(int $invoiceId): self
    {
        return new self(
            'Esta venta está en una factura de varias operaciones (lote) que no está anulada. Una devolución parcial '
            .'cambiaría su importe: anula esa factura (o emite su nota crédito) y repite la devolución.',
            'invoice_in_bundle',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    /**
     * Devolución PARCIAL de una venta anterior a los importes congelados por
     * línea, con factura sin anular: su factura no se puede recalcular al
     * centavo por las unidades que quedan.
     */
    public static function partialRefundLegacyInvoice(int $invoiceId): self
    {
        return new self(
            'Esta venta es anterior al registro de importes por línea y tiene una factura sin anular: '
            .'no se puede recalcular por lo que queda. Devuélvela entera.',
            'invoice_not_recalculable',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    /** La factura de la venta existe y no se puede rehacer por lo que queda. */
    public static function invoiceNotRecalculable(int $invoiceId, string $status): self
    {
        return new self(
            "La factura de esta venta (estado «{$status}») ya pasó por la DIAN o no se puede rehacer por lo que queda. "
            .'Una devolución parcial dejaría la factura por el total: resuélvela primero desde Facturación o devuelve la venta entera.',
            'invoice_not_recalculable',
            409,
            ['invoice_id' => $invoiceId],
        );
    }

    public static function closedShiftRequiresManage(int $shiftId, string $permission): self
    {
        return new self(
            "Esta operación pertenece al turno #{$shiftId}, que ya está cerrado. Corregirla exige además el permiso de administrar esa caja.",
            'closed_shift_requires_manage',
            403,
            ['cash_shift_id' => $shiftId, 'required_permission' => $permission],
        );
    }

    public static function noOpenShift(string $cashLabel): self
    {
        return new self(
            "No hay una caja de {$cashLabel} abierta. La devolución sale del turno abierto: ábrelo antes de registrarla.",
            'no_open_shift',
        );
    }

    public static function useReceivableFlow(?int $receivableId): self
    {
        return new self(
            'Esta operación se cobra por abonos'.($receivableId ? " (cuenta por cobrar #{$receivableId})" : '').'. '
            .'El dinero que entró está en sus abonos: para devolverlo o corregirlo, anula el abono o la deuda en Créditos.',
            'use_receivable_flow',
            409,
            array_filter(['receivable_id' => $receivableId]),
        );
    }

    public static function receivableHasPayments(int $receivableId): self
    {
        return new self(
            "La cuenta por cobrar #{$receivableId} tiene abonos aplicados. Anula primero esos abonos en Créditos; "
            .'después se puede anular esta operación.',
            'receivable_has_payments',
            422,
            ['receivable_id' => $receivableId],
        );
    }

    public static function notPaid(string $code, string $status): self
    {
        return new self(
            "Solo se devuelve lo que se cobró: esta operación está «{$status}».",
            $code,
        );
    }

    public static function alreadyRefunded(): self
    {
        return new self(
            'Esta operación ya se devolvió: el dinero salió de caja. No se puede además anular.',
            'already_refunded',
        );
    }

    public static function hasRefunds(): self
    {
        return new self(
            'Esta venta ya tiene devoluciones registradas. Anularla contaría dos veces el mismo dinero: '
            .'devuelve lo que falta en vez de anularla.',
            'sale_has_refunds',
        );
    }

    public static function refundExceedsSold(string $line, int $remaining): self
    {
        return new self(
            "De «{$line}» solo quedan {$remaining} unidades sin devolver.",
            'refund_exceeds_sold',
            422,
        );
    }

    public static function nothingToRefund(): self
    {
        return new self('No queda nada por devolver en esta venta.', 'nothing_to_refund', 422);
    }

    public static function requestIdConflict(): self
    {
        return new self(
            'Ese identificador de petición ya se usó para otra operación.',
            'client_request_id_conflict',
            422,
        );
    }

    public static function useRefundEndpoint(?int $receivableId = null): self
    {
        return $receivableId !== null
            ? new self(
                "Este cobro es un plan vendido a plazos y su cuenta por cobrar #{$receivableId} lleva el dinero. "
                .'La devolución se hace por abonos en Cuentas por cobrar, no desde el cobro.',
                'use_receivable_flow',
                422,
                ['errors' => ['status' => ['La devolución se hace por abonos en Cuentas por cobrar.']], 'receivable_id' => $receivableId],
            )
            : new self(
                'Un cobro no se marca como devuelto a mano: usa «Devolver dinero», que registra la salida de caja y quita los días.',
                'use_refund_endpoint',
                422,
                ['errors' => ['status' => ['Usa «Devolver dinero» para devolver un cobro.']]],
            );
    }

    public static function saleNotPayable(string $status): self
    {
        return new self("Esta venta está «{$status}» y no se puede cobrar.", 'sale_not_payable');
    }

    public static function saleNotDeliverable(string $status): self
    {
        return new self(
            "Solo se entrega lo que está cobrado (o fiado). Esta venta está «{$status}».",
            'sale_not_deliverable',
        );
    }

    public static function saleNotCancellable(string $status): self
    {
        return new self("Esta venta está «{$status}» y no se puede anular.", 'sale_not_cancellable');
    }

    public static function methodNotCorrectable(string $status): self
    {
        return new self(
            "El medio de pago solo se corrige en algo cobrado; esta operación está «{$status}».",
            'method_not_correctable',
        );
    }

    public static function receivablePaymentNotApplied(): self
    {
        return new self(
            'Este abono está anulado: su medio de pago ya no cuenta en ningún arqueo.',
            'receivable_payment_not_applied',
        );
    }
}
