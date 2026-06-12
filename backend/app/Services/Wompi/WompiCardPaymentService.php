<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;

/**
 * Cobro con TARJETA. PCI: el PAN/CVC NUNCA llegan aquí — Flutter tokeniza con la
 * llave PÚBLICA contra POST /v1/tokens/cards y este servicio recibe SOLO el
 * `card_token`. 3D Secure se resuelve por el estado de la transacción
 * (PENDING/requires_action → URL/desafío del emisor → webhook), nunca aprobando
 * por terminar el challenge.
 */
class WompiCardPaymentService extends AbstractWompiPaymentService
{
    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            WompiAcceptanceService::make(),
            (array) config('wompi'),
        );
    }

    protected function method(): string
    {
        return 'card';
    }

    protected function buildPaymentMethod(array $data, PaymentTransaction $transaction): ?array
    {
        $token = trim((string) ($data['card_token'] ?? ''));
        if ($token === '') {
            return null;
        }
        $installments = (int) ($data['installments'] ?? 1);
        $installments = max(1, min(36, $installments));

        return [
            'type'         => 'CARD',
            'token'        => $token,
            'installments' => $installments,
        ];
    }
}
