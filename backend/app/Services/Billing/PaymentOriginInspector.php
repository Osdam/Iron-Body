<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use App\Models\PaymentTransaction;

/**
 * Averigua de dónde viene realmente el dinero de una factura.
 *
 * Existe porque se descubrió que había documentos fiscales REALES ante la DIAN
 * (IBFE7, IBFE8) originados en transacciones de Wompi en ambiente **sandbox**
 * con tarjeta de prueba `4242`, y que las siete solicitudes pendientes eran del
 * mismo tipo. Un pago de sandbox no movió dinero: facturarlo declara ante la
 * DIAN una venta que no existió.
 *
 * La comprobación no puede vivir en la capa de presentación ni en el job: se
 * consulta desde la barrera previa al HTTP, donde ya no hay forma de esquivarla.
 */
class PaymentOriginInspector
{
    /**
     * Últimos cuatro dígitos de tarjetas de prueba conocidas de la pasarela.
     *
     * Es una red de seguridad SECUNDARIA: el criterio principal es
     * `payment_transactions.environment`. Se mantiene porque una credencial
     * mal configurada podría marcar como `production` una transacción hecha con
     * plástico de prueba, y ese caso debe seguir bloqueándose.
     */
    public const TEST_CARD_LAST_FOUR = ['4242', '4111', '0002', '0004'];

    /**
     * Prefijo de las referencias creadas al importar el sistema anterior.
     * Parecen referencias de pasarela pero no lo son: no hay transacción que
     * las respalde ni pasarela a la que consultar.
     */
    public const IMPORTED_REFERENCE_PREFIX = 'MIGR-';

    /**
     * Radiografía del origen. Ningún campo lanza excepción si falta: se
     * prefiere `null` y que la barrera decida, a romper el flujo de pago.
     *
     * @return array{
     *     transaction: ?PaymentTransaction,
     *     environment: ?string,
     *     card_last_four: ?string,
     *     reference: ?string,
     *     payment_status: ?string,
     *     is_sandbox: bool,
     *     is_test_card: bool,
     *     wants_invoice: bool,
     *     has_verifiable_reference: bool
     * }
     */
    public function inspect(ElectronicInvoice $invoice): array
    {
        $payment = $invoice->source;
        $reference = $payment->reference ?? null;

        $transaction = $this->transactionFor($reference);

        $environment = $transaction?->environment;
        $lastFour = $transaction?->card_last_four;

        return [
            'transaction' => $transaction,
            'environment' => $environment,
            'card_last_four' => $lastFour,
            'reference' => $reference,
            'payment_status' => $this->statusOf($payment),
            'is_sandbox' => $environment !== null && strtolower($environment) !== 'production',
            'is_test_card' => $lastFour !== null && in_array($lastFour, self::TEST_CARD_LAST_FOUR, true),
            'wants_invoice' => $this->wantsInvoice($payment, $transaction),
            'has_verifiable_reference' => $this->hasVerifiableReference($payment, $reference, $transaction),
        ];
    }

    /**
     * ¿Se solicitó factura para esta compra?
     *
     * Orden deliberado: primero el HECHO ECONÓMICO (`payments` /
     * `product_sales`), después la transacción de pasarela.
     *
     * Antes sólo se miraba la transacción, y eso hacía IMPOSIBLE facturar un
     * pago en efectivo o una venta de mostrador: no crean transacción, así que
     * `wants_invoice` era false pasara lo que pasara, aunque el cliente la
     * hubiera pedido en el mostrador. La solicitud pertenece a la compra, no al
     * medio de pago.
     *
     * La rama de `metadata` se conserva por compatibilidad con los pagos de
     * Wompi ya aprobados antes de este cambio.
     */
    private function wantsInvoice(mixed $payment, ?PaymentTransaction $transaction): bool
    {
        if (($payment->invoice_requested ?? false) === true) {
            return true;
        }

        return (bool) ($transaction?->metadata['wants_invoice'] ?? false);
    }

    /**
     * ¿Se puede rastrear de dónde vino el dinero?
     *
     * Tres casos legítimos y uno que no lo es:
     *
     *   - Sin referencia y pago manual (efectivo, transferencia, mostrador):
     *     válido. La trazabilidad la da el registro de caja.
     *   - Con referencia de pasarela y transacción que la respalde: válido.
     *   - Referencia `MIGR-*`: NO. Son importaciones del sistema anterior cuya
     *     referencia IMITA a una de pasarela sin serlo; no existe transacción
     *     que confirmar, y aceptarlas sería fabricar verificabilidad.
     *   - Con referencia y sin transacción: no se puede confirmar nada.
     */
    private function hasVerifiableReference(mixed $payment, ?string $reference, ?PaymentTransaction $transaction): bool
    {
        if ($reference === null || trim($reference) === '') {
            return $this->isManualPayment($payment);
        }

        if ($this->isImportedReference($reference)) {
            return false;
        }

        return $transaction !== null;
    }

    /** Referencia fabricada por la importación del sistema anterior. */
    private function isImportedReference(string $reference): bool
    {
        return str_starts_with(strtoupper(trim($reference)), self::IMPORTED_REFERENCE_PREFIX);
    }

    private function transactionFor(?string $reference): ?PaymentTransaction
    {
        if (blank($reference)) {
            return null;
        }

        return PaymentTransaction::where('reference', $reference)->first()
            // Wompi añade un sufijo de intento («-0», «-152») a la referencia.
            ?? PaymentTransaction::where('reference', 'like', $reference.'%')->first();
    }

    private function isManualPayment(mixed $payment): bool
    {
        $method = strtolower((string) ($payment->payment_method ?? $payment->method ?? ''));

        return in_array($method, ['cash', 'efectivo', 'transfer', 'transferencia', 'manual'], true);
    }

    /**
     * Estado del COBRO, que no siempre es la columna `status`.
     *
     * `product_sales` tiene dos estados distintos: `status` es el ciclo de vida
     * de la venta (pending/paid/delivered/cancelled) y `payment_status` es si el
     * dinero entró. Antes se leía `status`, así que una venta ya ENTREGADA pero
     * NO COBRADA se declaraba «paid» y pasaba la barrera: se habría facturado
     * ante la DIAN una venta cuyo dinero nunca llegó.
     *
     * Cuando existe `payment_status`, manda esa. `payments` no la tiene y sigue
     * usando `status`, exactamente como antes.
     */
    private function statusOf(mixed $payment): ?string
    {
        $status = $payment->payment_status ?? $payment->status ?? null;

        if ($status === null) {
            return null;
        }

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
