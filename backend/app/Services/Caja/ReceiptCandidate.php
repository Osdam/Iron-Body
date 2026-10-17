<?php

namespace App\Services\Caja;

use App\Enums\CashShiftType;
use App\Models\Payment;
use App\Models\ProductSale;
use App\Models\ReceivablePayment;
use Carbon\CarbonInterface;

/**
 * Un recibo que TODAVÍA NO EXISTE: qué operación imprime, en qué caja y con qué
 * llave de idempotencia.
 *
 * Lo produce {@see ReceiptSourceResolver} después de comprobar que la operación
 * es imprimible, y lo consumen el compositor (qué se imprime) y el emisor (el
 * número y la fila). Llevar ya cargados los modelos de origen evita que cada
 * pieza vuelva a consultarlos por su cuenta.
 */
final class ReceiptCandidate
{
    /**
     * @param  list<array{type: string, id: int}>  $sources
     * @param  list<ReceivablePayment>  $receivablePayments
     */
    public function __construct(
        public readonly CashShiftType $cashType,
        public readonly string $kind,
        public readonly string $originKey,
        public readonly array $sources,
        public readonly ?string $operationRef,
        public readonly ?CarbonInterface $operationAt,
        public readonly ?int $cashShiftId,
        public readonly ?Payment $payment = null,
        public readonly ?ProductSale $productSale = null,
        public readonly array $receivablePayments = [],
    ) {}
}
