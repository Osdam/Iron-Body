<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;

/**
 * Cobro con NEQUI vía Wompi (push a la app Nequi). El usuario aprueba en su app
 * Nequi y el backend confirma por webhook/reconciliación. No se usa ninguna
 * integración Nequi independiente: Wompi es la única pasarela.
 */
class WompiNequiPaymentService extends AbstractWompiPaymentService
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
        return 'nequi';
    }

    protected function buildPaymentMethod(array $data, PaymentTransaction $transaction): ?array
    {
        $phone = $this->normalizeCoPhone(
            $data['phone'] ?? $data['phone_number'] ?? ($transaction->customer_phone ?? '')
        );
        if (strlen($phone) !== 10) {
            return null;
        }

        return [
            'type'         => 'NEQUI',
            'phone_number' => $phone,
        ];
    }

    /** Normaliza a 10 dígitos colombianos (quita +57/57/0 y símbolos). */
    private function normalizeCoPhone(?string $phone): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return $digits;
    }
}
