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
            'wants_invoice' => (bool) ($transaction?->metadata['wants_invoice'] ?? false),
            // Un pago en efectivo registrado en caja no tiene referencia de
            // pasarela, y eso es legítimo. Lo que no vale es un pago de pasarela
            // sin transacción que lo respalde.
            'has_verifiable_reference' => $reference === null
                ? $this->isManualPayment($payment)
                : $transaction !== null,
        ];
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

    private function statusOf(mixed $payment): ?string
    {
        $status = $payment->status ?? null;

        if ($status === null) {
            return null;
        }

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
